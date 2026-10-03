'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const lib = require('../../public/js/roster-lib.js');

const ROWS = [
  { id: 1, order: 0, name: 'Zoe Adams', preferred: 'Z', number: 'A-100', avg: 3.5 },
  { id: 2, order: 1, name: 'Adam Zimmer', preferred: '', number: '', avg: null },
  { id: 3, order: 2, name: 'Élise Brown', preferred: 'Lisa', number: 'B-200', avg: 5 },
  { id: 4, order: 3, name: 'Cole, Blair', preferred: '', number: 'C-300', avg: 3.5 },
];

// ---- search ------------------------------------------------------------------

test('search matches the name, the preferred name or the number, ignoring case and accents', () => {
  assert.equal(lib.matchesStudent(ROWS[2], 'elise'), true);
  assert.equal(lib.matchesStudent(ROWS[2], 'LISA'), true, 'preferred name');
  assert.equal(lib.matchesStudent(ROWS[2], 'b-2'), true, 'number');
  assert.equal(lib.matchesStudent(ROWS[2], 'zzz'), false);
  assert.equal(lib.matchesStudent(ROWS[1], ''), true);
  assert.equal(lib.matchesStudent(ROWS[1], '   '), true);
});

test('every word of the search must match', () => {
  assert.equal(lib.matchesStudent(ROWS[0], 'zoe adams'), true);
  assert.equal(lib.matchesStudent(ROWS[0], 'adams zoe'), true);
  assert.equal(lib.matchesStudent(ROWS[0], 'zoe brown'), false);
  assert.equal(lib.matchesStudent({ name: 'Ana Lopez' }, 'lopez'), true, 'missing preferred name and number are fine');
});

// ---- last name key and sort ----------------------------------------------------

test('last name key uses the part before a comma, else the last word', () => {
  assert.equal(lib.lastNameKey('Alex Rivera'), 'rivera|alex rivera');
  assert.equal(lib.lastNameKey('Cole, Blair'), 'cole|cole, blair');
  assert.equal(lib.lastNameKey('Madonna'), 'madonna|madonna');
  assert.equal(lib.lastNameKey('  '), '|');
  // The PHP helper App\Support\StudentName::lastNameKey produces the same strings (tested in RosterPageTest).
});

test('sort by last name is the default and is stable', () => {
  assert.deepEqual(lib.sortRows(ROWS, 'last-asc').map((r) => r.id), [1, 3, 4, 2], 'Adams, Brown, Cole, Zimmer');
  assert.deepEqual(lib.sortRows(ROWS, 'unknown-key').map((r) => r.id), [1, 3, 4, 2]);
  const input = ROWS.slice();
  lib.sortRows(input, 'name-asc');
  assert.deepEqual(input, ROWS, 'the input array is not modified');
});

test('sort by name compares the full display name', () => {
  assert.deepEqual(lib.sortRows(ROWS, 'name-asc').map((r) => r.id), [2, 4, 3, 1], 'Adam, Cole, Élise, Zoe');
});

test('sort by participation average puts the highest first, ties by last name, No data last', () => {
  assert.deepEqual(lib.sortRows(ROWS, 'avg-desc').map((r) => r.id), [3, 1, 4, 2], '5, 3.5 (Adams), 3.5 (Cole), null');
});

test('recently added sorts by id, newest first', () => {
  assert.deepEqual(lib.sortRows(ROWS, 'recent').map((r) => r.id), [4, 3, 2, 1]);
});

// ---- counter and plan ---------------------------------------------------------

test('counter always says "Showing n of m students"', () => {
  assert.deepEqual(lib.countText(3, 12).parts, ['Showing ', '3', ' of 12 students']);
  assert.equal(lib.countText(12, 12).text, 'Showing 12 of 12 students');
  assert.equal(lib.countText(0, 12).text, 'Showing 0 of 12 students');
  assert.equal(lib.countText(1, 1).text, 'Showing 1 of 1 student');
});

test('plan filters and sorts in one step and keeps hidden rows in the order list', () => {
  const plan = lib.plan(ROWS, 'adam', 'last-asc');
  assert.deepEqual(plan.order.map((r) => r.id), [1, 3, 4, 2]);
  assert.deepEqual(plan.visible.map((r) => r.id), [1, 2], 'Adams and Adam Zimmer');
  assert.equal(lib.plan(ROWS, '', 'recent').visible.length, 4);
  assert.equal(lib.plan(ROWS, 'nobody', 'last-asc').visible.length, 0);
});

// ---- structure -----------------------------------------------------------------

test('the roster scripts are classic scripts without import or export', () => {
  for (const file of ['roster-lib.js', 'roster.js']) {
    const source = fs.readFileSync(path.join(__dirname, '../../public/js', file), 'utf8');
    assert.doesNotMatch(source, /^\s*(import|export)\s/m, file);
    assert.doesNotMatch(source, /localStorage|sessionStorage/, file);
  }
});
