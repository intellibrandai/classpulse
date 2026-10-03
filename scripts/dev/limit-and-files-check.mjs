#!/usr/bin/env node
// DEV-ONLY (not shipped): real-browser check of the 35-student limit and the "Files & backups" group against the LOCAL app.
//   node scripts/dev/limit-and-files-check.mjs [--out storage/app/final-v4]
// Creates the TEMPORARY scratch class "ZZ Demo (temp)" (8 fictitious students), pastes a 30-name list into the import page,
// checks the over-capacity flags BEFORE saving, commits, tries student #36 and a restore at 35, downloads every CSV linked
// from Files & backups, takes screenshots, and deletes the scratch class again through "Delete this class".
import { mkdirSync } from 'node:fs';
import { writeFile } from 'node:fs/promises';
import { join, resolve } from 'node:path';
import { Browser, ROOT, parseFlags, sleep } from './lib/cdp.mjs';
import { DEFAULT_CLASS_NAME, deleteScratchClass, findClassId, seedScratchClass } from './lib/scratch.mjs';

const flags = parseFlags(process.argv.slice(2));
const outDir = resolve(ROOT, typeof flags.out === 'string' ? flags.out : 'storage/app/final-v4');
mkdirSync(outDir, { recursive: true });
const NAME = DEFAULT_CLASS_NAME;
const b = new Browser();
let failed = 0;
const check = (ok, label) => { console.log((ok ? 'PASS ' : 'FAIL ') + label); if (!ok) failed++; };
let classId = null;

async function shoot(file, width, height, fullPage = false) {
  await b.send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false });
  await sleep(300);
  let h = height;
  if (fullPage) h = Math.min(6000, await b.eval('Math.max(document.documentElement.scrollHeight, document.body.scrollHeight)'));
  await b.send('Emulation.setDeviceMetricsOverride', { width, height: h, deviceScaleFactor: 1, mobile: false });
  await sleep(250);
  const { data } = await b.send('Page.captureScreenshot', { format: 'png', fromSurface: true, clip: { x: 0, y: 0, width, height: h, scale: 1 } });
  await writeFile(join(outDir, file), Buffer.from(data, 'base64'));
  console.log('  wrote ' + join('storage/app/final-v4', file));
}

async function submitKeys(formSelector) {
  const loaded = b.waitEvent('Page.loadEventFired');
  await b.eval(`document.querySelector(${JSON.stringify(formSelector)}).requestSubmit(); true`);
  await loaded;
  await b.eval('document.fonts.ready.then(() => true)');
}

try {
  await b.launch({ width: 1287, height: 900 });
  await b.login();
  if ((await findClassId(b, NAME)) !== null) throw new Error('A class named "' + NAME + '" already exists; remove it first');
  ({ classId } = await seedScratchClass(b, NAME, () => {}));

  // ---- import 30 pasted names into a class that already has 8 students: 27 fit, rows 28-30 are flagged before saving
  await b.goto('/classes/' + classId + '/import');
  const names = Array.from({ length: 30 }, (_, i) => 'Import Person ' + String(i + 1).padStart(2, '0')).join('\\n');
  await b.eval(`document.querySelector('#import-pasted').value = ${JSON.stringify(names)}.replace(/\\\\n/g, '\\n'); true`);
  await submitKeys('form[action$="/import/preview"]');
  const preview = await b.eval(`({ over: (document.querySelector('[data-import-over-capacity]') || {}).textContent, seats: (document.querySelector('[data-import-seats]') || {}).textContent, disabled: document.querySelectorAll('.imp-table input[type=checkbox]:disabled').length, checked: document.querySelectorAll('.imp-table input[type=checkbox]:checked').length, errors: document.querySelectorAll('.pill-error').length })`);
  check(/Only 27 more students fit \(8 of 35 active\)\. Rows 28.30 exceed the limit and will not be imported\./.test((preview.over || '').replace(/\s+/g, ' ')), 'preview explains the limit before saving: ' + (preview.over || '').replace(/\s+/g, ' ').trim());
  check(preview.disabled === 3 && preview.checked === 27, 'rows 28-30 are disabled ERROR rows, 27 are selected (disabled ' + preview.disabled + ', checked ' + preview.checked + ')');
  await shoot('import-over-capacity-dark.png', 1287, 900, true);
  await b.eval(`document.documentElement.dataset.theme = 'light'; true`);
  await b.theme('light');
  await shoot('import-over-capacity-light.png', 1287, 900, true);
  await b.theme('dark');
  await submitKeys('form[action$="/import/commit"]');
  check(await b.eval(`location.pathname === '/roster'`), 'commit redirects to the roster');
  check(await b.eval(`(document.querySelector('[data-capacity]') || {}).textContent.includes('35 / 35 seats')`), 'capacity meter shows 35 / 35 seats and Full');

  // ---- student #36 by quick add is refused, nothing is saved
  await b.eval(`document.querySelector('#quick-name').value = 'Overflow Person'; true`);
  await submitKeys('form.ro-quick');
  const quick = await b.eval(`(document.querySelector('#quick-errors') || {}).textContent || ''`);
  check(quick.includes('This class is full: 35 of 35 active students. Archive a student to free a seat.'), 'quick add #36 is refused with the clear message');
  check(await b.eval(`!document.body.textContent.includes('Overflow Person') || document.querySelector('#quick-name').value === 'Overflow Person'`), 'the refused student is not in the roster');
  await shoot('roster-full-dark.png', 1287, 900, false);

  // ---- archive one, restore at 34 works; archive two then restore is blocked again only at 35
  await b.eval(`document.querySelector('.ro-archive summary').click(); true`);
  const archiveForm = await b.eval(`(() => { const f = document.querySelector('.ro-archive form'); f.id = 'tmp-archive'; return Boolean(f); })()`);
  check(archiveForm, 'archive confirmation opens');
  await submitKeys('#tmp-archive');
  check(await b.eval(`(document.querySelector('[data-capacity]') || {}).textContent.includes('34 / 35 seats')`), 'archiving frees a seat (34 / 35)');
  await b.eval(`(() => { const d = document.querySelector('#archived'); if (d) d.open = true; const f = document.querySelector('#archived form[action$="/restore"]'); f.id = 'tmp-restore'; return true; })()`);
  await submitKeys('#tmp-restore');
  check(await b.eval(`(document.querySelector('[data-capacity]') || {}).textContent.includes('35 / 35 seats')`), 'restore at 34 active is allowed (back to 35 / 35)');
  await b.eval(`document.querySelector('.ro-archive summary').click(); true`);
  await b.eval(`document.querySelector('.ro-archive form').id = 'tmp-archive2'; true`);
  await submitKeys('#tmp-archive2');
  await b.eval(`document.querySelector('#quick-name').value = 'Seat Filler'; true`);
  await submitKeys('form.ro-quick');
  check(await b.eval(`(document.querySelector('[data-capacity]') || {}).textContent.includes('35 / 35 seats')`), 'a freed seat admits student #35 again');
  await b.eval(`(() => { const d = document.querySelector('#archived'); if (d) d.open = true; const f = document.querySelector('#archived form[action$="/restore"]'); f.id = 'tmp-restore2'; return true; })()`);
  await submitKeys('#tmp-restore2');
  const restoreMsg = await b.eval(`(document.querySelector('.ro-archived-error') || {}).textContent || ''`);
  check(restoreMsg.includes('This class is full: 35 of 35 active students. Archive a student to free a seat.'), 'restore at 35 active is refused with the message: ' + restoreMsg.trim());

  // ---- Files & backups: open the group, download every CSV
  await b.goto('/roster?class=' + classId);
  await b.eval(`document.querySelector('#files').open = true; true`);
  const links = await b.eval(`Array.from(document.querySelectorAll('#files a[href]')).map((a) => ({ href: a.href, label: a.getAttribute('aria-label') || a.textContent.trim() }))`);
  check(links.length === 5, 'Files & backups lists 5 links (import, weekly, full semester, Q1, Q2): ' + links.length);
  for (const link of links.slice(1)) {
    const res = await b.eval(`(async () => { const r = await fetch(${JSON.stringify(link.href)}, { credentials: 'same-origin' }); const t = await r.text(); return { status: r.status, type: r.headers.get('content-type'), disp: r.headers.get('content-disposition'), head: t.split('\\n')[0].slice(0, 60), lines: t.split('\\n').length }; })()`);
    check(res.status === 200 && /text\/csv/.test(res.type) && /attachment/.test(res.disp) && res.lines > 5, link.label + ' downloads a real CSV (' + res.disp + ', ' + res.lines + ' lines, header "' + res.head + '")');
  }
  const imp = await b.eval(`(async () => { const r = await fetch(${JSON.stringify(links[0].href)}); return r.status; })()`);
  check(imp === 200, 'Import roster link opens the import flow');
  await b.eval(`document.querySelector('#files').scrollIntoView({ block: 'start' }); window.scrollBy(0, -90); true`);
  for (const [theme, w, h, file] of [['dark', 1287, 900, 'files-backups-dark-desktop.png'], ['light', 1287, 900, 'files-backups-light-desktop.png'], ['dark', 375, 812, 'files-backups-dark-mobile.png'], ['light', 375, 812, 'files-backups-light-mobile.png']]) {
    await b.theme(theme);
    await b.send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: false });
    await sleep(300);
    await b.eval(`(() => { const s = document.querySelector('#files'); s.scrollIntoView({ block: 'start' }); window.scrollBy(0, -${w < 600 ? 10 : 90}); return true; })()`);
    await sleep(250);
    const top = await b.eval('window.scrollY'); // clip coordinates are document coordinates
    const { data } = await b.send('Page.captureScreenshot', { format: 'png', fromSurface: true, clip: { x: 0, y: top, width: w, height: h, scale: 1 } });
    await writeFile(join(outDir, file), Buffer.from(data, 'base64'));
    console.log('  wrote storage/app/final-v4/' + file);
  }
} catch (error) {
  console.error('limit-and-files-check failed: ' + error.message);
  failed++;
} finally {
  try {
    const id = await findClassId(b, NAME);
    if (id !== null) { await b.goto('/daily'); await deleteScratchClass(b, id, NAME, (l) => console.log(l)); }
  } catch (error) {
    console.error('cleanup failed: ' + error.message + ' (delete "' + NAME + '" from Class Roster & Settings)');
    failed++;
  }
  await b.close();
}
console.log(failed === 0 ? '\nALL CHECKS PASSED' : '\n' + failed + ' CHECK(S) FAILED');
process.exit(failed ? 1 : 0);
