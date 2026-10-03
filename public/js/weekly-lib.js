// Weekly Matrix pure helpers (no DOM access, no network): search and sort, cell text, the quick-editor state machine,
// the undo stack, the popover reducer, clipboard copy with fallback and SVG chart descriptors.
// Totals and averages are never computed here: the server sends them (GET /api/classes/{class}/weeks/{monday}).
(function () {
const SVG_NS = 'http://www.w3.org/2000/svg';
const MAX_POINTS = 99;

function normalize(text) {
  return String(text === null || text === undefined ? '' : text)
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .trim();
}

function matchesName(name, term) {
  const needle = normalize(term);
  return needle === '' || normalize(name).indexOf(needle) !== -1;
}

// Text of the "Showing n active students" counter as [before, number, after] so the number can be bold.
function countText(visible, total, term) {
  const noun = total === 1 ? 'student' : 'students';
  const filtered = normalize(term) !== '' && visible !== total;
  const parts = filtered ? ['Showing ', String(visible), ' of ' + total + ' active ' + noun] : ['Showing ', String(total), ' active ' + noun];
  return { visible: visible, parts: parts, text: parts.join('') };
}

// rows: [{ order, name, total, avg (number or null), absences }] -> a sorted copy. Ties fall back to name A-Z, then order.
function sortRows(rows, key) {
  const byName = function (a, b) {
    const x = normalize(a.name);
    const y = normalize(b.name);
    if (x < y) { return -1; }
    if (x > y) { return 1; }
    return a.order - b.order;
  };
  const numeric = function (field) {
    return function (a, b) {
      const x = a[field] === null || a[field] === undefined ? -Infinity : a[field];
      const y = b[field] === null || b[field] === undefined ? -Infinity : b[field];
      if (x !== y) { return y - x; }
      return byName(a, b);
    };
  };
  const compare = {
    'name-asc': byName,
    'name-desc': function (a, b) { return -byName(a, b) || a.order - b.order; },
    'total-desc': numeric('total'),
    'avg-desc': numeric('avg'),
    'absences-desc': numeric('absences'),
  }[key] || byName;
  return rows.slice().sort(compare);
}

function heatClass(status, points) {
  if (status === 'absent') { return 'heat-absent'; }
  if (status !== 'present') { return ''; }
  if (points >= 6) { return 'heat-high'; }
  if (points >= 3) { return 'heat-mid'; }
  if (points >= 1) { return 'heat-low'; }
  return 'heat-0';
}

function cellText(status, points) {
  if (status === 'absent') { return 'A'; }
  return status === 'present' ? String(points) : '—';
}

function stateText(status, points) {
  if (status === 'absent') { return 'Absent'; }
  if (status === 'present') { return 'Present, ' + points + (points === 1 ? ' point' : ' points'); }
  return 'Not recorded';
}

function cellLabel(name, when, status, points, lockedReason) {
  return name + ', ' + when + ': ' + stateText(status, points) + '. ' + (lockedReason || 'Open editor');
}

// ---- quick editor ----------------------------------------------------------

// What the popover shows for a cell. Absent students cannot take points: the editor offers "Mark present" first.
function editorState(status, points) {
  const present = status === 'present';
  const absent = status === 'absent';
  return {
    status: status,
    pointsEnabled: !absent,
    minusDisabled: !present || points <= 0,
    plusDisabled: absent || (present && points >= MAX_POINTS),
    showRecordZero: status === 'none',
    showMarkAbsent: !absent,
    showMarkPresent: absent,
    showClear: status !== 'none',
    inputValue: present ? String(points) : '',
    text: stateText(status, points),
    hint: absent ? 'Mark present to enter points.' : '',
  };
}

function parsePoints(text) {
  const raw = String(text === null || text === undefined ? '' : text).trim();
  if (raw === '') { return { ok: false, message: 'Enter a whole number from 0 to ' + MAX_POINTS + '.' }; }
  if (!/^\d+$/.test(raw)) { return { ok: false, message: 'Points must be a whole number from 0 to ' + MAX_POINTS + '.' }; }
  const value = parseInt(raw, 10);
  if (value > MAX_POINTS) { return { ok: false, message: 'Points cannot go above ' + MAX_POINTS + '.' }; }
  return { ok: true, value: value };
}

// Turns a popover button into the operation for the save queue, or explains why it cannot run.
// Returns { op: { kind, student_id[, points] } } | { noop: true } | { error: message }.
function planAction(cell, action, inputText) {
  const status = cell.status;
  const points = cell.points;
  const base = function (kind) { return { op: { kind: kind, student_id: cell.studentId } }; };
  if (action === 'increment') {
    if (status === 'absent') { return { error: 'Mark present first.' }; }
    if (status === 'present' && points >= MAX_POINTS) { return { error: 'Points cannot go above ' + MAX_POINTS + '.' }; }
    return base('increment');
  }
  if (action === 'decrement') {
    if (status !== 'present') { return { error: status === 'absent' ? 'Mark present first.' : 'Nothing is recorded yet.' }; }
    if (points <= 0) { return { error: 'Points cannot go below 0.' }; }
    return base('decrement');
  }
  if (action === 'set_zero') { return status === 'none' ? base('set_zero') : { error: 'This cell already has a record.' }; }
  if (action === 'absent_on') { return status === 'absent' ? { noop: true } : base('absent_on'); }
  if (action === 'absent_off') { return status === 'absent' ? base('absent_off') : { noop: true }; }
  if (action === 'clear') { return status === 'none' ? { noop: true } : base('clear'); }
  if (action === 'set_points') {
    if (status === 'absent') { return { error: 'Mark present first.' }; }
    const parsed = parsePoints(inputText);
    if (!parsed.ok) { return { error: parsed.message }; }
    if (status === 'present' && parsed.value === points) { return { noop: true }; }
    return { op: { kind: 'set_points', student_id: cell.studentId, points: parsed.value } };
  }
  return { error: 'Unknown action.' };
}

// ---- undo stack (per page load) ----------------------------------------------

// Remembers the date of each edit so the header Undo can call the day API undo for that date (undo is per date).
function createUndoStack(max) {
  const limit = max || 20;
  let items = [];
  return {
    push: function (date, opId) {
      items.push({ date: date, opId: opId || null });
      if (items.length > limit) { items = items.slice(items.length - limit); }
      return items.length;
    },
    pop: function () { return items.length === 0 ? undefined : items.pop().date; },
    discard: function (opId) {
      const index = items.map(function (item) { return item.opId; }).lastIndexOf(opId);
      if (opId && index !== -1) { items.splice(index, 1); return true; }
      return false;
    },
    size: function () { return items.length; },
    peek: function () { return items.length === 0 ? undefined : items[items.length - 1].date; },
    clear: function () { items = []; },
  };
}

// ---- popover state machine ---------------------------------------------------

// state: { open: boolean, target: {studentId, date} | null, returnFocus: boolean }
function popoverInitial() { return { open: false, target: null, returnFocus: false }; }

function sameTarget(a, b) { return !!a && !!b && a.studentId === b.studentId && a.date === b.date; }

function popoverReduce(state, event) {
  if (event.type === 'open') {
    if (state.open && sameTarget(state.target, event.target)) { return { open: false, target: null, returnFocus: true }; }
    return { open: true, target: event.target, returnFocus: false };
  }
  if (!state.open) { return state; }
  if (event.type === 'escape' || event.type === 'close') { return { open: false, target: null, returnFocus: true }; }
  if (event.type === 'outside') { return { open: false, target: null, returnFocus: false }; }
  if (event.type === 'cell-gone') { return { open: false, target: null, returnFocus: false }; }
  return state;
}

// Tab trap: index of the control that gets focus next, wrapping at both ends.
function trapIndex(current, count, backwards) {
  if (count <= 0) { return -1; }
  if (current < 0 || current >= count) { return backwards ? count - 1 : 0; }
  return backwards ? (current - 1 + count) % count : (current + 1) % count;
}

// ---- save status across several per-date queues --------------------------------

function aggregateSaveState(states) {
  if (states.indexOf('expired') !== -1) { return 'expired'; }
  if (states.indexOf('failed') !== -1) { return 'failed'; }
  if (states.indexOf('saving') !== -1) { return 'saving'; }
  return 'saved';
}

// ---- clipboard -----------------------------------------------------------------

// deps: { clipboard: { writeText } | null, fallback: (text) => boolean }. Resolves 'clipboard' | 'fallback' | 'failed'.
function copyText(text, deps) {
  const fallback = function () {
    try {
      return deps.fallback && deps.fallback(text) ? 'fallback' : 'failed';
    } catch (error) {
      return 'failed';
    }
  };
  if (deps.clipboard && typeof deps.clipboard.writeText === 'function') {
    return Promise.resolve().then(function () { return deps.clipboard.writeText(text); }).then(
      function () { return 'clipboard'; },
      function () { return fallback(); },
    );
  }
  return Promise.resolve(fallback());
}

function summaryLines(text) {
  return String(text || '').split('\n').filter(function (line) { return line.trim() !== ''; }).slice(1);
}

// ---- SVG descriptors (same structure as reports/weekly/chart-*.blade.php) ----------

function lineChartNode(chart) {
  const children = [{ tag: 'title', text: chart.alt }];
  if (chart.path) { children.push({ tag: 'path', attrs: { d: chart.path } }); }
  chart.dots.forEach(function (dot) {
    children.push({ tag: 'circle', attrs: { class: 'ui-chart-dot', cx: String(dot.x), cy: String(dot.y), r: dot.is_last ? '3' : '2' }, children: [{ tag: 'title', text: dot.title }] });
  });
  return { tag: 'svg', attrs: { class: 'ui-chart ui-chart-violet wm-chart', viewBox: '0 0 ' + chart.width + ' ' + chart.height, role: 'img', 'aria-label': chart.alt, focusable: 'false' }, children: children };
}

function barChartNode(chart) {
  const children = [{ tag: 'title', text: chart.alt }];
  chart.bars.forEach(function (bar) {
    if (bar.is_gap) {
      const y = String(chart.baseline_y - 1);
      children.push({ tag: 'line', attrs: { class: 'ui-chart-gap', x1: String(bar.x), x2: String(bar.x + bar.width), y1: y, y2: y }, children: [{ tag: 'title', text: bar.title }] });
    } else {
      children.push({ tag: 'rect', attrs: { class: bar.is_peak ? 'ui-chart-bar is-peak' : 'ui-chart-bar', x: String(bar.x), y: String(bar.y), width: String(bar.width), height: String(bar.height), rx: '2' }, children: [{ tag: 'title', text: bar.title }] });
    }
  });
  return { tag: 'svg', attrs: { class: 'ui-chart ui-chart-blue wm-chart', viewBox: '0 0 ' + chart.width + ' ' + chart.height, role: 'img', 'aria-label': chart.alt, focusable: 'false' }, children: children };
}

// Builds real SVG nodes from a descriptor with DOM APIs only (textContent and setAttribute only, never markup strings).
function materialize(doc, node) {
  const element = doc.createElementNS(SVG_NS, node.tag);
  Object.keys(node.attrs || {}).forEach(function (name) { element.setAttribute(name, node.attrs[name]); });
  if (node.text !== undefined) { element.textContent = node.text; }
  (node.children || []).forEach(function (child) { element.appendChild(materialize(doc, child)); });
  return element;
}

const api = {
  MAX_POINTS, normalize, matchesName, countText, sortRows, heatClass, cellText, stateText, cellLabel,
  editorState, parsePoints, planAction, createUndoStack, popoverInitial, popoverReduce, trapIndex,
  aggregateSaveState, copyText, summaryLines, lineChartNode, barChartNode, materialize,
};
if (typeof module !== 'undefined' && module.exports) { module.exports = api; } else { globalThis.ClassPulseWeekly = api; }
})();
