const test = require('node:test');
const assert = require('node:assert/strict');
const format = require('../../public/js/daily-format.js');

test('statusText keeps the three recorded states and the not recorded state apart', () => {
  assert.equal(format.statusText('none', null), 'Not recorded');
  assert.equal(format.statusText('present', 0), 'Present · 0');
  assert.equal(format.statusText('present', 4), 'Present');
  assert.equal(format.statusText('absent', null), 'Absent');
});

test('cardState maps status and points to the four visual states', () => {
  assert.equal(format.cardState('none', null), 'untouched');
  assert.equal(format.cardState('present', 0), 'zero');
  assert.equal(format.cardState('present', 2), 'present');
  assert.equal(format.cardState('absent', null), 'absent');
});

test('badgeClass follows the state and falls back to the untouched badge', () => {
  assert.equal(format.badgeClass('zero'), 'badge-zero');
  assert.equal(format.badgeClass('present'), 'badge-active');
  assert.equal(format.badgeClass('absent'), 'badge-absent');
  assert.equal(format.badgeClass('bogus'), 'badge-untouched');
});

test('panelSubText and dayValueText use singular and plural points', () => {
  assert.equal(format.panelSubText('present', 3), 'Present · 3 pts today');
  assert.equal(format.panelSubText('present', 1), 'Present · 1 pt today');
  assert.equal(format.panelSubText('present', 0), 'Present · 0 pts today');
  assert.equal(format.panelSubText('none', null), 'Not recorded today');
  assert.equal(format.panelSubText('absent', null), 'Absent today');
  assert.equal(format.dayValueText('present', 0), 'Present · 0');
  assert.equal(format.dayValueText('present', 5), '5 pts');
  assert.equal(format.dayValueText('absent', null), 'Absent');
  assert.equal(format.dayValueText('none', null), 'Not recorded');
});

test('summarize counts states, total and mean over present students only', () => {
  const result = format.summarize([
    { status: 'present', points: 3 },
    { status: 'present', points: 0 },
    { status: 'absent', points: null },
    { status: 'none', points: null },
    { status: 'none', points: null },
  ]);
  assert.deepEqual(result, { total: 3, present: 2, absent: 1, none: 2, active: 5, mean: 1.5 });
});

test('summarize returns a null mean when nobody is present', () => {
  const result = format.summarize([{ status: 'none', points: null }, { status: 'absent', points: null }]);
  assert.equal(result.mean, null);
  assert.equal(result.total, 0);
});

test('filterCounts separates Active, Zero, Absent and Not recorded', () => {
  const counts = format.filterCounts([
    { status: 'present', points: 3 },
    { status: 'present', points: 1 },
    { status: 'present', points: 0 },
    { status: 'absent', points: null },
    { status: 'none', points: null },
    { status: 'none', points: null },
  ]);
  assert.deepEqual(counts, { all: 6, active: 2, zero: 1, absent: 1, none: 2 });
  assert.deepEqual(format.filterCounts([]), { all: 0, active: 0, zero: 0, absent: 0, none: 0 });
});

test('matchesFilter: a recorded zero is Zero, never Not recorded, and absences are never Active', () => {
  assert.equal(format.matchesFilter('all', 'none', null), true);
  assert.equal(format.matchesFilter('active', 'present', 2), true);
  assert.equal(format.matchesFilter('active', 'present', 0), false);
  assert.equal(format.matchesFilter('active', 'absent', null), false);
  assert.equal(format.matchesFilter('zero', 'present', 0), true);
  assert.equal(format.matchesFilter('zero', 'none', null), false);
  assert.equal(format.matchesFilter('absent', 'absent', null), true);
  assert.equal(format.matchesFilter('none', 'none', null), true);
  assert.equal(format.matchesFilter('none', 'present', 0), false);
  assert.equal(format.matchesFilter('none', 'absent', null), false);
});
