'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const lib = require('../../public/js/weekly-lib.js');

// ---- search ------------------------------------------------------------------

test('search ignores case, accents and surrounding spaces', () => {
  assert.equal(lib.matchesName('Élise Fournier', 'elise'), true);
  assert.equal(lib.matchesName('Élise Fournier', '  FOURN '), true);
  assert.equal(lib.matchesName('Alex Rivera', 'zed'), false);
  assert.equal(lib.matchesName('Alex Rivera', ''), true);
  assert.equal(lib.matchesName('Alex Rivera', '   '), true);
});

test('counter text says "of" only while a search narrows the list', () => {
  assert.deepEqual(lib.countText(12, 12, '').parts, ['Showing ', '12', ' active students']);
  assert.equal(lib.countText(3, 12, 'al').text, 'Showing 3 of 12 active students');
  assert.equal(lib.countText(0, 12, 'zzz').text, 'Showing 0 of 12 active students');
  assert.equal(lib.countText(1, 1, '').text, 'Showing 1 active student');
  // A term that matches everything is not reported as filtered.
  assert.equal(lib.countText(12, 12, 'a').text, 'Showing 12 active students');
});

// ---- sort ----------------------------------------------------------------------

const ROWS = [
  { order: 0, name: 'Ada', total: 5, avg: 2.5, absences: 0 },
  { order: 1, name: 'Bo', total: 9, avg: 3, absences: 2 },
  { order: 2, name: 'Cleo', total: 0, avg: null, absences: 1 },
  { order: 3, name: 'Dev', total: 9, avg: 4.5, absences: 2 },
  { order: 4, name: 'Élise', total: 5, avg: 1, absences: 0 },
];
const names = (key) => lib.sortRows(ROWS, key).map((r) => r.name);

test('sort by name both ways', () => {
  assert.deepEqual(names('name-asc'), ['Ada', 'Bo', 'Cleo', 'Dev', 'Élise']);
  assert.deepEqual(names('name-desc'), ['Élise', 'Dev', 'Cleo', 'Bo', 'Ada']);
});

test('sort by weekly total, ties fall back to name A-Z', () => {
  assert.deepEqual(names('total-desc'), ['Bo', 'Dev', 'Ada', 'Élise', 'Cleo']);
});

test('sort by weekly average puts "No data" last', () => {
  assert.deepEqual(names('avg-desc'), ['Dev', 'Bo', 'Ada', 'Élise', 'Cleo']);
});

test('sort by absences, most first, ties by name', () => {
  assert.deepEqual(names('absences-desc'), ['Bo', 'Dev', 'Cleo', 'Ada', 'Élise']);
});

test('sorting returns a copy and an unknown key means name A-Z', () => {
  const before = ROWS.map((r) => r.name);
  lib.sortRows(ROWS, 'total-desc');
  assert.deepEqual(ROWS.map((r) => r.name), before);
  assert.deepEqual(names('nonsense'), ['Ada', 'Bo', 'Cleo', 'Dev', 'Élise']);
});

// ---- cell text -------------------------------------------------------------------

test('the three states read differently: dash, 0 and A', () => {
  assert.equal(lib.cellText('none', null), '—');
  assert.equal(lib.cellText('present', 0), '0');
  assert.equal(lib.cellText('absent', null), 'A');
  assert.equal(lib.stateText('none', null), 'Not recorded');
  assert.equal(lib.stateText('present', 0), 'Present, 0 points');
  assert.equal(lib.stateText('present', 1), 'Present, 1 point');
  assert.equal(lib.stateText('absent', null), 'Absent');
  assert.equal(lib.heatClass('none', null), '');
  assert.equal(lib.heatClass('present', 0), 'heat-0');
  assert.equal(lib.heatClass('present', 2), 'heat-low');
  assert.equal(lib.heatClass('present', 3), 'heat-mid');
  assert.equal(lib.heatClass('present', 6), 'heat-high');
  assert.equal(lib.heatClass('absent', null), 'heat-absent');
  assert.equal(lib.cellLabel('Ada', 'Mon Oct 19', 'present', 3, null), 'Ada, Mon Oct 19: Present, 3 points. Open editor');
});

// ---- quick editor ------------------------------------------------------------------

test('editor state per cell state', () => {
  const none = lib.editorState('none', null);
  assert.equal(none.showRecordZero, true);
  assert.equal(none.showClear, false);
  assert.equal(none.minusDisabled, true);
  assert.equal(none.plusDisabled, false);
  assert.equal(none.inputValue, '');

  const zero = lib.editorState('present', 0);
  assert.equal(zero.minusDisabled, true, 'minimum is zero');
  assert.equal(zero.showRecordZero, false);
  assert.equal(zero.showClear, true);
  assert.equal(zero.inputValue, '0');

  assert.equal(lib.editorState('present', 99).plusDisabled, true);
  assert.equal(lib.editorState('present', 98).plusDisabled, false);

  const absent = lib.editorState('absent', null);
  assert.equal(absent.pointsEnabled, false);
  assert.equal(absent.showMarkPresent, true);
  assert.equal(absent.showMarkAbsent, false);
  assert.match(absent.hint, /Mark present/);
});

test('typed points are whole numbers from 0 to 99', () => {
  assert.deepEqual(lib.parsePoints('0'), { ok: true, value: 0 });
  assert.deepEqual(lib.parsePoints(' 42 '), { ok: true, value: 42 });
  assert.deepEqual(lib.parsePoints('99'), { ok: true, value: 99 });
  assert.equal(lib.parsePoints('100').ok, false);
  assert.equal(lib.parsePoints('-1').ok, false);
  assert.equal(lib.parsePoints('2.5').ok, false);
  assert.equal(lib.parsePoints('1e2').ok, false);
  assert.equal(lib.parsePoints('').ok, false);
  assert.equal(lib.parsePoints('abc').ok, false);
});

test('planAction maps a button to the operation and refuses illegal moves', () => {
  const cell = (status, points) => ({ studentId: 7, date: '2026-10-19', status, points });
  assert.deepEqual(lib.planAction(cell('none', null), 'set_zero'), { op: { kind: 'set_zero', student_id: 7 } });
  assert.deepEqual(lib.planAction(cell('present', 3), 'increment'), { op: { kind: 'increment', student_id: 7 } });
  assert.deepEqual(lib.planAction(cell('present', 3), 'set_points', '8'), { op: { kind: 'set_points', student_id: 7, points: 8 } });
  assert.deepEqual(lib.planAction(cell('none', null), 'set_points', '0'), { op: { kind: 'set_points', student_id: 7, points: 0 } });
  assert.deepEqual(lib.planAction(cell('present', 3), 'set_points', '3'), { noop: true });
  assert.deepEqual(lib.planAction(cell('present', 3), 'clear'), { op: { kind: 'clear', student_id: 7 } });
  assert.deepEqual(lib.planAction(cell('none', null), 'clear'), { noop: true });
  assert.deepEqual(lib.planAction(cell('none', null), 'absent_on'), { op: { kind: 'absent_on', student_id: 7 } });
  assert.deepEqual(lib.planAction(cell('absent', null), 'absent_off'), { op: { kind: 'absent_off', student_id: 7 } });

  assert.match(lib.planAction(cell('present', 0), 'decrement').error, /below 0/);
  assert.match(lib.planAction(cell('present', 99), 'increment').error, /above 99/);
  assert.match(lib.planAction(cell('absent', null), 'increment').error, /Mark present/);
  assert.match(lib.planAction(cell('absent', null), 'set_points', '4').error, /Mark present/);
  assert.match(lib.planAction(cell('present', 3), 'set_points', '100').error, /above 99/);
  assert.match(lib.planAction(cell('present', 3), 'set_points', '').error, /whole number/);
  assert.match(lib.planAction(cell('present', 3), 'set_zero').error, /already/);
});

// ---- undo stack --------------------------------------------------------------------

test('undo stack returns the date of the last edit first', () => {
  const stack = lib.createUndoStack(20);
  assert.equal(stack.size(), 0);
  assert.equal(stack.pop(), undefined);
  stack.push('2026-10-19', 'a');
  stack.push('2026-10-21', 'b');
  stack.push('2026-10-19', 'c');
  assert.equal(stack.size(), 3);
  assert.equal(stack.peek(), '2026-10-19');
  assert.deepEqual([stack.pop(), stack.pop(), stack.pop(), stack.pop()], ['2026-10-19', '2026-10-21', '2026-10-19', undefined]);
});

test('undo stack keeps at most 20 edits and drops the oldest', () => {
  const stack = lib.createUndoStack(20);
  for (let i = 0; i < 25; i += 1) {
    stack.push('2026-10-' + String(10 + i), 'op' + i);
  }
  assert.equal(stack.size(), 20);
  let last;
  let count = 0;
  for (let d = stack.pop(); d !== undefined; d = stack.pop()) {
    last = d;
    count += 1;
  }
  assert.equal(count, 20);
  assert.equal(last, '2026-10-15', 'the five oldest were dropped');
});

test('a rejected edit is forgotten by its op id', () => {
  const stack = lib.createUndoStack(20);
  stack.push('2026-10-19', 'a');
  stack.push('2026-10-20', 'b');
  assert.equal(stack.discard('b'), true);
  assert.equal(stack.discard('b'), false);
  assert.equal(stack.discard('zzz'), false);
  assert.equal(stack.size(), 1);
  assert.equal(stack.pop(), '2026-10-19');
  stack.push('2026-10-19', 'c');
  stack.clear();
  assert.equal(stack.size(), 0);
});

// ---- popover state machine ------------------------------------------------------------

test('popover opens on a cell, toggles on the same cell and switches cells', () => {
  const a = { studentId: '1', date: '2026-10-19' };
  const b = { studentId: '2', date: '2026-10-19' };
  let state = lib.popoverInitial();
  assert.equal(state.open, false);

  state = lib.popoverReduce(state, { type: 'open', target: a });
  assert.deepEqual([state.open, state.target], [true, a]);

  state = lib.popoverReduce(state, { type: 'open', target: b });
  assert.deepEqual([state.open, state.target], [true, b]);

  state = lib.popoverReduce(state, { type: 'open', target: b });
  assert.deepEqual([state.open, state.returnFocus], [false, true]);
});

test('Escape and Close return focus to the cell, an outside click does not', () => {
  const target = { studentId: '1', date: '2026-10-19' };
  const open = lib.popoverReduce(lib.popoverInitial(), { type: 'open', target });
  assert.deepEqual([lib.popoverReduce(open, { type: 'escape' }).open, lib.popoverReduce(open, { type: 'escape' }).returnFocus], [false, true]);
  assert.equal(lib.popoverReduce(open, { type: 'close' }).returnFocus, true);
  const outside = lib.popoverReduce(open, { type: 'outside' });
  assert.deepEqual([outside.open, outside.returnFocus], [false, false]);
  // Events while closed change nothing.
  const closed = lib.popoverInitial();
  assert.equal(lib.popoverReduce(closed, { type: 'escape' }), closed);
});

test('the focus trap wraps in both directions', () => {
  assert.equal(lib.trapIndex(0, 4, false), 1);
  assert.equal(lib.trapIndex(3, 4, false), 0);
  assert.equal(lib.trapIndex(0, 4, true), 3);
  assert.equal(lib.trapIndex(2, 4, true), 1);
  assert.equal(lib.trapIndex(-1, 4, false), 0);
  assert.equal(lib.trapIndex(-1, 4, true), 3);
  assert.equal(lib.trapIndex(0, 0, false), -1);
});

// ---- save status -------------------------------------------------------------------------

test('the footer status combines the per-date queues', () => {
  assert.equal(lib.aggregateSaveState([]), 'saved');
  assert.equal(lib.aggregateSaveState(['saved', 'saved']), 'saved');
  assert.equal(lib.aggregateSaveState(['saved', 'saving']), 'saving');
  assert.equal(lib.aggregateSaveState(['saving', 'failed']), 'failed');
  assert.equal(lib.aggregateSaveState(['failed', 'expired', 'saving']), 'expired');
});

// ---- clipboard ---------------------------------------------------------------------------

test('copy uses the clipboard API when it works', async () => {
  const written = [];
  const how = await lib.copyText('hello', { clipboard: { writeText: (t) => { written.push(t); return Promise.resolve(); } }, fallback: () => { throw new Error('unused'); } });
  assert.equal(how, 'clipboard');
  assert.deepEqual(written, ['hello']);
});

test('copy falls back to the textarea path when the clipboard API is missing or rejects', async () => {
  const seen = [];
  const fallback = (t) => { seen.push(t); return true; };
  assert.equal(await lib.copyText('a', { clipboard: null, fallback }), 'fallback');
  assert.equal(await lib.copyText('b', { clipboard: { writeText: () => Promise.reject(new Error('denied')) }, fallback }), 'fallback');
  assert.equal(await lib.copyText('c', { clipboard: { writeText: () => { throw new Error('sync'); } }, fallback }), 'fallback');
  assert.deepEqual(seen, ['a', 'b', 'c']);
});

test('copy reports failure when both paths fail', async () => {
  assert.equal(await lib.copyText('x', { clipboard: null, fallback: () => false }), 'failed');
  assert.equal(await lib.copyText('x', { clipboard: null, fallback: () => { throw new Error('no'); } }), 'failed');
  assert.equal(await lib.copyText('x', { clipboard: null }), 'failed');
});

test('summary lines drop the title line and blanks', () => {
  assert.deepEqual(lib.summaryLines('HNL - week of Oct 19\nTotal points: 15\n\nAbsences: 1\n'), ['Total points: 15', 'Absences: 1']);
  assert.deepEqual(lib.summaryLines(''), []);
});

// ---- charts -------------------------------------------------------------------------------

function fakeDoc() {
  const make = (tag) => ({
    tag, attrs: {}, children: [], textContent: '',
    setAttribute(name, value) { this.attrs[name] = value; },
    appendChild(child) { this.children.push(child); },
  });
  return { createElementNS: (ns, tag) => { assert.equal(ns, 'http://www.w3.org/2000/svg'); return make(tag); } };
}

test('line chart descriptor draws a path and dots with text alternatives, built with DOM calls only', () => {
  const chart = { width: 120, height: 40, has_data: true, path: 'M4.00 30.00 L60.00 10.00', dots: [{ x: 4, y: 30, title: 'Mon: 1.00', is_last: false }, { x: 60, y: 10, title: 'Tue: 3.00', is_last: true }], alt: 'Class average per present day by weekday. Mon: 1.00; Tue: 3.00' };
  const svg = lib.materialize(fakeDoc(), lib.lineChartNode(chart));
  assert.equal(svg.tag, 'svg');
  assert.equal(svg.attrs.viewBox, '0 0 120 40');
  assert.equal(svg.attrs.role, 'img');
  assert.equal(svg.attrs['aria-label'], chart.alt);
  assert.deepEqual(svg.children.map((c) => c.tag), ['title', 'path', 'circle', 'circle']);
  assert.equal(svg.children[0].textContent, chart.alt);
  assert.equal(svg.children[1].attrs.d, chart.path);
  assert.equal(svg.children[3].attrs.r, '3');
  assert.equal(svg.children[2].children[0].textContent, 'Mon: 1.00');
});

test('hostile text stays text: it is only ever assigned to textContent or attributes', () => {
  const chart = { width: 120, height: 40, has_data: true, path: '', dots: [{ x: 1, y: 1, title: '<img src=x onerror=alert(1)>', is_last: true }], alt: '"><script>alert(1)</script>' };
  const svg = lib.materialize(fakeDoc(), lib.lineChartNode(chart));
  assert.equal(svg.children.length, 2, 'no path element when there is no path');
  assert.equal(svg.children[1].children[0].textContent, '<img src=x onerror=alert(1)>');
  assert.equal(svg.attrs['aria-label'], '"><script>alert(1)</script>');
});

test('bar chart descriptor draws gaps as dashed baseline marks and highlights the peak', () => {
  const chart = {
    width: 120, height: 40, has_data: true, baseline_y: 40,
    bars: [
      { x: 0, y: 10, width: 20, height: 30, is_gap: false, is_peak: true, title: 'Mon: 9 points' },
      { x: 25, y: 40, width: 20, height: 0, is_gap: true, title: 'Tue: no data' },
      { x: 50, y: 38, width: 20, height: 2, is_gap: false, is_peak: false, title: 'Wed: 0 points' },
    ],
    alt: 'Points per weekday.',
  };
  const svg = lib.materialize(fakeDoc(), lib.barChartNode(chart));
  assert.deepEqual(svg.children.map((c) => c.tag), ['title', 'rect', 'line', 'rect']);
  assert.equal(svg.children[1].attrs.class, 'ui-chart-bar is-peak');
  assert.equal(svg.children[2].attrs.class, 'ui-chart-gap');
  assert.equal(svg.children[2].attrs.y1, '39');
  assert.equal(svg.children[3].attrs.class, 'ui-chart-bar');
  assert.equal(svg.children[3].attrs.height, '2', 'a recorded zero is still a visible bar, unlike a gap');
});

// ---- contracts with the page --------------------------------------------------------------

test('weekly.js only touches data hooks that the Blade view provides', () => {
  const root = path.join(__dirname, '../../');
  const js = fs.readFileSync(path.join(root, 'public/js/weekly.js'), 'utf8');
  const view = ['resources/views/reports/weekly.blade.php', 'resources/views/reports/weekly/cell.blade.php']
    .map((f) => fs.readFileSync(path.join(root, f), 'utf8')).join('\n');
  const hooks = new Set();
  for (const m of js.matchAll(/\[(data-[a-z-]+)(?:=|\])/g)) hooks.add(m[1]);
  assert.ok(hooks.size > 15, 'found the hooks');
  // Hooks that JavaScript creates itself or reads from dataset only.
  const created = new Set(['data-save-text']);
  const missing = [...hooks].filter((h) => !created.has(h) && !view.includes(h));
  assert.deepEqual(missing, []);
});

test('weekly.js and weekly-lib.js are classic scripts without import or export', () => {
  for (const file of ['weekly.js', 'weekly-lib.js']) {
    const source = fs.readFileSync(path.join(__dirname, '../../public/js', file), 'utf8');
    assert.doesNotMatch(source, /^\s*(import|export)\s/m, file);
    assert.doesNotMatch(source, /innerHTML|insertAdjacentHTML|outerHTML/, file + ' must not build markup from strings');
  }
});
