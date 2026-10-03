const test = require('node:test');
const assert = require('node:assert/strict');
const lib = require('../../public/js/semester-lib.js');

const rows = [
  { id: '1', name: 'alex rivera', order: 0, total: 57, avg: 4.75, absences: 0, present: 12 },
  { id: '2', name: 'avery kowalski', order: 1, total: 27, avg: 2.45, absences: 1, present: 11 },
  { id: '3', name: 'dana absent', order: 2, total: 0, avg: null, absences: 12, present: 0 },
  { id: '4', name: 'jordan lee', order: 3, total: 57, avg: 1.89, absences: 2, present: 9 },
  { id: '5', name: 'morgan okafor', order: 4, total: 44, avg: 3.67, absences: 0, present: 12 },
];

test('search matches every word against name, preferred name and number', () => {
  assert.equal(lib.matches('alex rivera zz-100', 'rivera'), true);
  assert.equal(lib.matches('alex rivera zz-100', '  ALEX   riv '), true);
  assert.equal(lib.matches('alex rivera zz-100', 'zz-100'), true);
  assert.equal(lib.matches('alex rivera zz-100', 'alex lee'), false);
  assert.equal(lib.matches('alex rivera', ''), true);
});

test('name sort keeps the server order; metric sorts are descending with ties in name order', () => {
  assert.deepEqual(lib.sortRows(rows, 'name-asc').map((r) => r.id), ['1', '2', '3', '4', '5']);
  assert.deepEqual(lib.sortRows(rows, 'total-desc').map((r) => r.id), ['1', '4', '5', '2', '3']);
  assert.deepEqual(lib.sortRows(rows, 'absences-desc').map((r) => r.id), ['3', '4', '2', '1', '5']);
  assert.deepEqual(lib.sortRows(rows, 'present-desc').map((r) => r.id), ['1', '5', '2', '4', '3']);
  assert.deepEqual(lib.sortRows(rows, 'nonsense').map((r) => r.id), ['1', '2', '3', '4', '5']);
});

test('students without an average sort last by mean and get no rank', () => {
  assert.deepEqual(lib.sortRows(rows, 'avg-desc').map((r) => r.id), ['1', '5', '2', '4', '3']);
  assert.equal(lib.ranks(rows, 'avg-desc')['3'], undefined);
});

test('ranks exist only for a metric sort and ties share a rank', () => {
  assert.deepEqual(lib.ranks(rows, 'name-asc'), {});
  assert.deepEqual(lib.ranks(rows, 'total-desc'), { 1: 1, 4: 1, 5: 3, 2: 4, 3: 5 });
  assert.equal(lib.rankLabel('name-asc'), null);
  assert.equal(lib.rankLabel('total-desc'), 'Rank by Total points');
  assert.equal(lib.rankLabel('avg-desc'), 'Rank by Mean per present day');
});

test('paging shows a window and "Show more" grows it up to the matches', () => {
  assert.equal(lib.clampShown(25, 12, 25), 12);
  assert.equal(lib.clampShown(25, 40, 25), 25);
  assert.equal(lib.clampShown(0, 40, 25), 25);
  assert.equal(lib.showMore(25, 40, 25), 40);
  assert.equal(lib.showMore(25, 80, 25), 50);
});

test('the showing counter names filters honestly', () => {
  assert.equal(lib.showingText(25, 27, 27), 'Showing 25 of 27 students');
  assert.equal(lib.showingText(1, 1, 1), 'Showing 1 of 1 student');
  assert.equal(lib.showingText(3, 3, 27), 'Showing 3 of 3 matching students (27 in this class)');
});

test('selection: select replaces, clear deselects, unknown actions change nothing', () => {
  let state = { id: null };
  state = lib.selectionReduce(state, { type: 'select', id: 7 });
  assert.deepEqual(state, { id: '7' });
  state = lib.selectionReduce(state, { type: 'select', id: '9' });
  assert.deepEqual(state, { id: '9' });
  assert.deepEqual(lib.selectionReduce(state, { type: 'other' }), { id: '9' });
  assert.deepEqual(lib.selectionReduce(state, { type: 'clear' }), { id: null });
});

test('date labels come from the Y-m-d string without time zones', () => {
  assert.equal(lib.formatDate('2026-10-14'), 'Oct 14, 2026');
  assert.equal(lib.weekday('2026-10-14'), 'Wed');
  assert.equal(lib.formatDate('2026-02-30'), '2026-02-30');
  assert.equal(lib.weekday('nope'), '');
});

test('period membership uses inclusive bounds and accepts an undated period', () => {
  assert.equal(lib.inPeriod('2026-09-14', '2026-09-14', '2026-10-30'), true);
  assert.equal(lib.inPeriod('2026-10-30', '2026-09-14', '2026-10-30'), true);
  assert.equal(lib.inPeriod('2026-09-13', '2026-09-14', '2026-10-30'), false);
  assert.equal(lib.inPeriod('2026-09-13', '', ''), true);
});

test('notes: one per date, newest first', () => {
  let notes = [{ date: '2026-09-22', body: 'b' }, { date: '2026-09-03', body: 'a' }];
  notes = lib.mergeNote(notes, { date: '2026-09-28', body: 'c' });
  assert.deepEqual(notes.map((n) => n.date), ['2026-09-28', '2026-09-22', '2026-09-03']);
  notes = lib.mergeNote(notes, { date: '2026-09-22', body: 'edited' });
  assert.equal(notes.length, 3);
  assert.equal(notes[1].body, 'edited');
  assert.equal(lib.hasNoteOn(notes, '2026-09-22'), true);
  assert.equal(lib.hasNoteOn(notes, '2026-09-23'), false);
  assert.deepEqual(lib.removeNote(notes, '2026-09-22').map((n) => n.date), ['2026-09-28', '2026-09-03']);
});

test('excerpt keeps short text and cuts long text on a word boundary', () => {
  assert.equal(lib.excerpt('  Short   note  ', 50), 'Short note');
  const cut = lib.excerpt('Led the group discussion on meal planning and asked follow-up questions', 30);
  assert.ok(cut.length <= 30, cut);
  assert.ok(cut.endsWith('…'));
  assert.ok(!/\s…$/.test(cut));
});

test('comment template uses only the period numbers and the saved notes', () => {
  const text = lib.buildComment({
    name: 'Alex Rivera', preferred: '', total: '57', present: '12', average: '4.75', periodLabel: 'Q2 / Finals',
    notes: [{ label: 'Sep 30, 2026', body: 'Edited scratch note.' }, { label: 'Sep 22, 2026', body: 'Asked thoughtful questions.' }],
  });
  assert.equal(text, 'Alex Rivera recorded 57 participation points over 12 present days (average 4.75) in Q2 / Finals. Notes: Sep 30, 2026: Edited scratch note. Sep 22, 2026: Asked thoughtful questions.');
});

test('comment template: preferred name, singular units, no notes, no present days, note limit', () => {
  assert.equal(
    lib.buildComment({ name: 'Jordan Lee', preferred: 'Jo', total: '1', present: '1', average: '1.00', periodLabel: 'Full Semester', notes: [] }),
    'Jo recorded 1 participation point over 1 present day (average 1.00) in Full Semester.',
  );
  assert.equal(
    lib.buildComment({ name: 'Dana Absent', preferred: '', total: '0', present: '0', average: '', periodLabel: 'Q1 / Midterm', notes: [{ label: 'Sep 3, 2026', body: 'x' }] }),
    'Dana Absent has no present days recorded in Q1 / Midterm yet. Notes: Sep 3, 2026: x',
  );
  const many = [1, 2, 3, 4, 5].map((n) => ({ label: 'D' + n, body: 'n' + n }));
  const text = lib.buildComment({ name: 'A', preferred: '', total: '2', present: '2', average: '1.00', periodLabel: 'P', notes: many });
  assert.ok(text.includes('D3: n3') && !text.includes('D4'));
});

test('Copy all joins name and draft and skips empty drafts', () => {
  assert.equal(
    lib.joinAll([{ name: 'A', text: ' one ' }, { name: 'B', text: '   ' }, { name: 'C', text: 'three' }]),
    'A\none\n\nC\nthree',
  );
  assert.equal(lib.joinAll([]), '');
});

test('draftInit: a saved body wins over the generated template, blank saved falls back to the template', () => {
  const saved = lib.draftInit({ body: 'My words', updated_at: '2026-10-19T18:45:00+00:00' }, 'Template text');
  assert.deepEqual([saved.source, saved.text, saved.save, lib.draftBadge(saved)], ['saved', 'My words', 'saved', 'Saved draft']);
  const tpl = lib.draftInit(undefined, 'Template text');
  assert.deepEqual([tpl.source, tpl.text, tpl.save, lib.draftBadge(tpl)], ['template', 'Template text', 'idle', 'Template']);
  assert.equal(lib.draftInit({ body: '  ', updated_at: null }, 'T').source, 'template');
});

test('draft state machine: input, save, saved and failure transitions', () => {
  let s = lib.draftInit(null, 'Template');
  assert.equal(lib.draftRequest(s), null);
  s = lib.draftReduce(s, { type: 'input', text: 'Template edited' });
  assert.equal(s.save, 'dirty');
  assert.deepEqual(lib.draftRequest(s), { method: 'PUT', body: 'Template edited' });
  s = lib.draftReduce(s, { type: 'save' });
  assert.equal(lib.draftStatusText(s), 'Saving…');
  assert.equal(lib.draftRequest(s), null, 'one request in flight at a time');
  s = lib.draftReduce(s, { type: 'input', text: 'Template edited again' });
  assert.equal(s.save, 'saving', 'typing while saving keeps the saving state');
  s = lib.draftReduce(s, { type: 'saved', draft: { body: 'Template edited', updated_at: '2026-10-19T18:45:00+00:00' }, template: 'Template' });
  assert.equal(s.save, 'dirty', 'the text moved on while the request was out');
  assert.equal(s.source, 'saved');
  assert.deepEqual(lib.draftRequest(s), { method: 'PUT', body: 'Template edited again' });
  s = lib.draftReduce(s, { type: 'save' });
  s = lib.draftReduce(s, { type: 'saved', draft: { body: 'Template edited again', updated_at: '2026-10-19T18:46:00+00:00' }, template: 'Template' });
  assert.deepEqual([s.save, lib.draftStatusText(s), s.updatedAt], ['saved', 'Saved', '2026-10-19T18:46:00+00:00']);
  s = lib.draftReduce(s, { type: 'save' });
  s = lib.draftReduce(s, { type: 'failed' });
  assert.deepEqual([s.save, lib.draftStatusText(s)], ['error', 'Save failed']);
  assert.equal(lib.draftRequest(s), null, 'unchanged text after an error is not dirty');
});

test('a failed save of changed text stays retryable', () => {
  let s = lib.draftInit(null, 'Template');
  s = lib.draftReduce(s, { type: 'input', text: 'Changed' });
  s = lib.draftReduce(s, { type: 'save' });
  s = lib.draftReduce(s, { type: 'failed' });
  assert.equal(s.save, 'error');
  assert.deepEqual(lib.draftRequest(s), { method: 'PUT', body: 'Changed' });
});

test('a blank text deletes the draft and returns to the template', () => {
  let s = lib.draftInit({ body: 'Mine', updated_at: null }, 'Template');
  s = lib.draftReduce(s, { type: 'input', text: '   ' });
  assert.deepEqual(lib.draftRequest(s), { method: 'DELETE' });
  s = lib.draftReduce(s, { type: 'save' });
  s = lib.draftReduce(s, { type: 'saved', draft: null, template: 'Template' });
  assert.deepEqual([s.source, s.text, s.save, lib.draftBadge(s)], ['template', 'Template', 'idle', 'Template']);
});

test('reset returns to the template and the template refreshes only while untouched', () => {
  let s = lib.draftInit({ body: 'Mine', updated_at: 'x' }, 'Template');
  s = lib.draftReduce(s, { type: 'reset', template: 'Template' });
  assert.deepEqual([s.source, s.text, s.updatedAt, s.save], ['template', 'Template', null, 'idle']);
  s = lib.draftReduce(s, { type: 'template', template: 'Template with a new note' });
  assert.equal(s.text, 'Template with a new note');
  s = lib.draftReduce(s, { type: 'input', text: 'Edited' });
  s = lib.draftReduce(s, { type: 'template', template: 'Another' });
  assert.equal(s.text, 'Edited', 'edited text is never overwritten');
  const saved = lib.draftInit({ body: 'Mine', updated_at: null }, 'T');
  assert.equal(lib.draftReduce(saved, { type: 'template', template: 'New' }).text, 'Mine');
});

test('formatStamp shows the school-timezone time and tolerates bad input', () => {
  assert.match(lib.formatStamp('2026-10-19T18:45:00+00:00'), /^Oct 19, 2:45 PM$/);
  assert.equal(lib.formatStamp(null), '');
  assert.equal(lib.formatStamp('nope'), '');
});

test('Reset to template stays enabled while an edit of the template is being saved', () => {
  let s = lib.draftInit(null, 'Template');
  assert.equal(lib.resetDisabled(s), true, 'untouched template: nothing to reset');
  s = lib.draftReduce(s, { type: 'input', text: 'Template edited' });
  assert.equal(lib.resetDisabled(s), false, 'edited');
  // Moving the focus to the Reset button starts the save: the button must not switch itself off under the keyboard or mouse.
  s = lib.draftReduce(s, { type: 'save' });
  assert.equal(lib.resetDisabled(s), false, 'saving');
  s = lib.draftReduce(s, { type: 'failed' });
  assert.equal(lib.resetDisabled(s), false, 'failed save of changed text');
  s = lib.draftReduce(s, { type: 'reset', template: 'Template' });
  assert.equal(lib.resetDisabled(s), true, 'back on the template');
  const saved = lib.draftInit({ body: 'My words', updated_at: null }, 'Template');
  assert.equal(lib.resetDisabled(saved), false, 'a saved draft can be reset');
});
