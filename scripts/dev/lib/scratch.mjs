// DEV-ONLY: create and remove a TEMPORARY scratch class with fictitious data through the running app
// (same session, CSRF and endpoints the UI uses). It never touches any other class. Only classes whose name
// starts with "ZZ " can be removed by this code.
import { pageFetchSource, sleep } from './cdp.mjs';

export const DEFAULT_CLASS_NAME = 'ZZ Demo (temp)';

const STUDENTS = [
  { display_name: 'Alex Rivera', preferred_name: 'Alex', student_number: 'ZZ-101', observations: 'Sits near the front. Works well in pairs and likes to explain answers aloud before writing them down.' },
  { display_name: 'Jordan Lee', preferred_name: '', student_number: 'ZZ-102', observations: '' },
  { display_name: 'Morgan Okafor', preferred_name: 'Mo', student_number: 'ZZ-103', observations: 'Needs a quiet minute before presenting to the whole class; very strong in small groups, asks precise clarifying questions, and usually volunteers after a second prompt when the topic connects to something from an earlier unit that the class already discussed together.' },
  { display_name: 'Avery Kowalski', preferred_name: '', student_number: 'ZZ-104', observations: '' },
  { display_name: 'Sam Patel', preferred_name: '', student_number: 'ZZ-105', observations: 'Often absent on Mondays.' },
  { display_name: 'Riley Chen', preferred_name: 'Ri', student_number: 'ZZ-106', observations: '' },
  { display_name: 'Casey Nguyen', preferred_name: '', student_number: 'ZZ-107', observations: '' },
  { display_name: 'Taylor Brooks', preferred_name: '', student_number: 'ZZ-108', observations: '' },
];

const NOTE_TEXT = [
  'Volunteered twice during the warm-up discussion and linked the topic back to last week.',
  'Quiet today; contributed only when asked directly. Follow up on Thursday.',
  'Led the group activity and helped a classmate who was stuck on the second question.',
  'Asked a thoughtful question about how the two examples differ. Showing real curiosity.',
  'Came prepared with notes and shared a counter-example that moved the discussion forward.',
  'Distracted at the start of class, then settled and finished the exit ticket early.',
];

function weekdays(from, to) {
  const out = [];
  for (let d = new Date(from + 'T00:00:00Z'); d <= new Date(to + 'T00:00:00Z'); d.setUTCDate(d.getUTCDate() + 1)) {
    if (d.getUTCDay() >= 1 && d.getUTCDay() <= 5) out.push(d.toISOString().slice(0, 10));
  }
  return out;
}

async function api(browser, method, url, body) {
  const res = await browser.eval(pageFetchSource(method, url, body));
  if (res.status >= 400) throw new Error(method + ' ' + url + ' -> ' + res.status + ' ' + JSON.stringify(res.payload));
  return res.payload;
}

async function form(browser, method, url, fields) {
  return browser.eval(`(async () => {
    const token = document.querySelector('meta[name=csrf-token]').content;
    const body = new URLSearchParams(${JSON.stringify(fields)});
    body.set('_token', token);
    ${method === 'PUT' ? "body.set('_method', 'PUT');" : ''}
    const res = await fetch(${JSON.stringify(url)}, { method: 'POST', body, headers: { 'Accept': 'text/html', 'X-CSRF-TOKEN': token }, redirect: 'follow' });
    return { status: res.status, url: res.url, ok: res.ok };
  })()`);
}

export async function listClasses(browser) {
  await browser.goto('/roster');
  return browser.eval(`Array.from(document.querySelectorAll('.class-menu-header .class-menu-item')).map(a => ({ id: Number(new URL(a.href).searchParams.get('class')), name: a.querySelector('.class-menu-item-code').textContent.trim() }))`);
}

export async function findClassId(browser, name) {
  const all = await listClasses(browser);
  const hit = all.find((c) => c.name === name || c.name.startsWith(name + ' '));
  return hit ? hit.id : null;
}

export async function seedScratchClass(browser, name, log = console.log) {
  await browser.goto('/roster?create=1');
  const created = await form(browser, 'POST', '/classes', {
    name, title: 'Scratch class for screenshots', subject_description: 'Fictitious data', period_label: 'Temp',
    room: 'B-12', schedule: 'Mon-Fri 9:00', roster_cap: '35', semester_start: '2026-08-24', semester_end: '2027-01-29',
  });
  const classId = Number((/class=(\d+)/.exec(created.url) || [])[1]);
  if (!classId) throw new Error('Could not create the scratch class (name taken or validation failed): ' + created.url);
  log('scratch class id ' + classId);

  await browser.goto('/roster?class=' + classId);
  const periods = await form(browser, 'PUT', '/classes/' + classId, {
    name, title: 'Scratch class for screenshots', subject_description: 'Fictitious data', period_label: 'Temp', room: 'B-12', schedule: 'Mon-Fri 9:00',
    roster_cap: '35', semester_start: '2026-08-24', semester_end: '2027-01-29',
    q1_label: 'Q1 / Midterm', q1_start: '2026-08-24', q1_end: '2026-10-30', q2_label: 'Q2 / Finals', q2_start: '2026-11-02', q2_end: '2027-01-29',
  });
  if (!periods.ok) throw new Error('Saving the scratch periods failed: ' + periods.status);

  for (const s of STUDENTS) {
    const r = await form(browser, 'POST', '/classes/' + classId + '/students', { display_name: s.display_name, student_number: s.student_number });
    if (!r.ok) throw new Error('Adding ' + s.display_name + ' failed: ' + r.status);
  }
  // Profile fields (preferred name, observations) need an update per student; find the ids from the roster page.
  await browser.goto('/roster?class=' + classId);
  const ids = await browser.eval(`Array.from(document.querySelectorAll('form[action*="/students/"][action$="/archive"]')).map(f => Number(/students\\/(\\d+)\\/archive/.exec(f.action)[1]))`);
  const byNumber = {};
  const dayState = await api(browser, 'GET', '/api/classes/' + classId + '/days/2026-09-30');
  for (const entry of dayState.data.entries) byNumber[entry.student_id] = entry;
  const studentIds = Object.keys(byNumber).map(Number).sort((a, b) => a - b);
  if (studentIds.length !== STUDENTS.length) throw new Error('Expected ' + STUDENTS.length + ' students, found ' + studentIds.length + ' (archive forms: ' + ids.length + ')');
  for (let i = 0; i < STUDENTS.length; i++) {
    const s = STUDENTS[i];
    const r = await form(browser, 'PUT', '/students/' + studentIds[i], {
      display_name: s.display_name, preferred_name: s.preferred_name, student_number: s.student_number, observations: s.observations,
    });
    if (!r.ok) throw new Error('Updating ' + s.display_name + ' failed: ' + r.status);
  }

  // Records: six-plus weeks of deterministic points, a few absences.
  const days = weekdays('2026-08-24', '2026-09-30');
  log('recording ' + days.length + ' days for ' + studentIds.length + ' students');
  for (const [d, date] of days.entries()) {
    for (const [i, id] of studentIds.entries()) {
      const seed = (d * 7 + i * 13 + (d % 5) * i) % 11;
      if (seed === 0 && i % 3 === 1) {
        await api(browser, 'POST', '/api/classes/' + classId + '/days/' + date + '/operations', { op_id: crypto.randomUUID(), kind: 'absent_on', student_id: id });
        continue;
      }
      if (seed === 10 && i === 7) continue; // one not-recorded day
      const points = Math.max(0, Math.round(2 + (i % 4) + Math.sin(d / 3 + i) * 2.4 + (d > 12 ? 1 : 0)));
      await api(browser, 'POST', '/api/classes/' + classId + '/days/' + date + '/operations', { op_id: crypto.randomUUID(), kind: 'set_points', student_id: id, points });
    }
  }

  // Notes: a few per student on different days; drafts for two students.
  const noteDates = ['2026-09-08', '2026-09-14', '2026-09-21', '2026-09-25', '2026-09-30'];
  for (const [i, id] of studentIds.entries()) {
    const count = i < 3 ? 4 : 2;
    for (let n = 0; n < count; n++) {
      await api(browser, 'PUT', '/api/classes/' + classId + '/students/' + id + '/notes/' + noteDates[(n + i + 4) % noteDates.length], { body: NOTE_TEXT[(i + n) % NOTE_TEXT.length] });
    }
  }
  await api(browser, 'PUT', '/api/classes/' + classId + '/students/' + studentIds[0] + '/report-comments/full', {
    body: 'Alex is an engaged, curious contributor who explains their thinking clearly and supports classmates in group work. Next step: keep taking the lead on longer discussions.',
  });
  await api(browser, 'PUT', '/api/classes/' + classId + '/students/' + studentIds[2] + '/report-comments/full', {
    body: 'Mo participates most in small groups and is building confidence in whole-class discussion. Continue to invite Mo to share one idea per lesson.',
  });
  await sleep(200);
  return { classId, studentIds };
}

// Uses the roster page "Delete this class" section exactly as the owner would.
export async function deleteScratchClass(browser, classId, name, log = console.log) {
  if (!name.startsWith('ZZ ')) throw new Error('Refusing to delete a class that is not a scratch class: ' + name);
  await browser.goto('/roster?class=' + classId);
  await browser.eval(`document.querySelector('.ro-danger-details').open = true; true`);
  await browser.eval(`(() => { const f = document.querySelector('.ro-danger-details form'); f.querySelector('#confirm-name').value = ${JSON.stringify(name)}; return true; })()`);
  const loaded = browser.waitEvent('Page.loadEventFired');
  await browser.eval(`document.querySelector('.ro-danger-details form').requestSubmit(); true`);
  await loaded;
  const left = await listClasses(browser);
  log('classes now: ' + left.map((c) => c.name).join(', '));
  return left;
}

// A FULL roster (30 students) with long names, long preferred names and long observations, so the printed roster
// spans two pages. Fictitious data only. Used by `print-pdfs.mjs --full-roster`; removed again with deleteScratchClass.
const FIRST = ['Alexandria', 'Bartholomew', 'Clementine', 'Dominique', 'Evangeline', 'Fitzgerald', 'Genevieve', 'Hildegard', 'Isabella-Rose', 'Jean-Baptiste'];
const LAST = ['Montgomery-Featherstonehaugh', 'Van der Westhuizen', 'Papadopoulos-Nakamura', 'Oyelaran-Castellanos', 'Kowalczyk-Brannigan', 'Delacroix-Sutherland'];
const LONG_OBS = 'Participates enthusiastically in small-group discussions, often linking new ideas to earlier units, and needs a reminder to wait for classmates before adding a second point. Works best when the task is broken into short steps with a visible checklist; responds well to specific praise. Ask about the family project before the next report card and confirm the seating plan for the lab rotation.';

export async function seedFullRoster(browser, name, count = 30, log = console.log) {
  await browser.goto('/roster?create=1');
  const created = await form(browser, 'POST', '/classes', {
    name, title: 'Full roster for print checks', subject_description: 'Fictitious data', period_label: 'Temp',
    room: 'B-12', schedule: 'Mon-Fri 9:00', roster_cap: String(count), semester_start: '2026-08-24', semester_end: '2027-01-29',
  });
  const classId = Number((/class=(\d+)/.exec(created.url) || [])[1]);
  if (!classId) throw new Error('Could not create the full-roster class (name taken or validation failed): ' + created.url);
  log('full-roster class id ' + classId);
  await browser.goto('/roster?class=' + classId);
  const people = [];
  for (let i = 0; i < count; i++) {
    people.push({
      display_name: FIRST[i % FIRST.length] + ' ' + LAST[i % LAST.length] + (i >= FIRST.length ? ' ' + String.fromCharCode(65 + (i % 26)) + '.' : ''),
      preferred_name: i % 3 === 0 ? 'Alexandra-Bartholomew "Bart" the Third' : (i % 3 === 1 ? 'Jean-Baptiste' : ''),
      student_number: 'ZZ-' + String(200 + i),
      observations: i % 2 === 0 ? LONG_OBS : (i % 5 === 1 ? 'Often absent on Mondays.' : ''),
    });
  }
  for (const p of people) {
    const r = await form(browser, 'POST', '/classes/' + classId + '/students', { display_name: p.display_name, student_number: p.student_number });
    if (!r.ok) throw new Error('Adding ' + p.display_name + ' failed: ' + r.status);
  }
  const dayState = await api(browser, 'GET', '/api/classes/' + classId + '/days/2026-09-30');
  const ids = dayState.data.entries.map((e) => e.student_id).sort((a, b) => a - b);
  if (ids.length !== count) throw new Error('Expected ' + count + ' students, found ' + ids.length);
  for (let i = 0; i < count; i++) {
    const r = await form(browser, 'PUT', '/students/' + ids[i], { display_name: people[i].display_name, preferred_name: people[i].preferred_name, student_number: people[i].student_number, observations: people[i].observations });
    if (!r.ok) throw new Error('Updating ' + people[i].display_name + ' failed: ' + r.status);
  }
  for (const date of ['2026-09-28', '2026-09-29', '2026-09-30']) {
    for (const [i, id] of ids.entries()) {
      await api(browser, 'POST', '/api/classes/' + classId + '/days/' + date + '/operations', { op_id: crypto.randomUUID(), kind: 'set_points', student_id: id, points: (i * 3 + date.length) % 7 });
    }
  }
  await sleep(200);
  return { classId, studentIds: ids };
}
