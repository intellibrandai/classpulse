// Class Roster pure helpers (no DOM access, no network): search, sort and the "Showing n of m" counter.
// Averages come from the server (data-avg); nothing here computes a stored figure.
(function () {
function normalize(text) {
  return String(text === null || text === undefined ? '' : text)
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .trim();
}

// Same rule as App\Support\StudentName::lastNameKey: the part before a comma, otherwise the last word.
function lastNameKey(name) {
  const full = normalize(name);
  let last;
  if (full.indexOf(',') !== -1) {
    last = full.split(',')[0].trim();
  } else {
    const words = full.split(/\s+/).filter(Boolean);
    last = words.length ? words[words.length - 1] : '';
  }
  return last + '|' + full;
}

// row: { name, preferred, number }. Every word of the search must appear in the name, preferred name or number.
function matchesStudent(row, term) {
  const needles = normalize(term).split(/\s+/).filter(Boolean);
  if (!needles.length) { return true; }
  const haystack = [row.name, row.preferred, row.number].map(normalize).join(' ');
  return needles.every(function (needle) { return haystack.indexOf(needle) !== -1; });
}

// rows: [{ id, order, name, avg (number or null), ... }] -> sorted copy. Ties fall back to last name, then order.
function sortRows(rows, key) {
  const cmp = function (x, y) { return x < y ? -1 : x > y ? 1 : 0; };
  const byLast = function (a, b) { return cmp(lastNameKey(a.name), lastNameKey(b.name)) || a.order - b.order; };
  const byName = function (a, b) { return cmp(normalize(a.name), normalize(b.name)) || a.order - b.order; };
  const byAvg = function (a, b) {
    const x = a.avg === null || a.avg === undefined ? -Infinity : a.avg;
    const y = b.avg === null || b.avg === undefined ? -Infinity : b.avg;
    return x !== y ? y - x : byLast(a, b);
  };
  const byRecent = function (a, b) { return b.id - a.id; };
  const compare = { 'last-asc': byLast, 'name-asc': byName, 'avg-desc': byAvg, recent: byRecent }[key] || byLast;
  return rows.slice().sort(compare);
}

// "Showing n of m students" as [before, number, after] so the number can be bold.
function countText(visible, total) {
  const noun = total === 1 ? 'student' : 'students';
  const parts = ['Showing ', String(visible), ' of ' + total + ' ' + noun];
  return { visible: visible, parts: parts, text: parts.join('') };
}

// Filter and sort in one step: returns { order: [rows sorted, all of them], visible: [rows that match, in order] }.
function plan(rows, term, key) {
  const order = sortRows(rows, key);
  return { order: order, visible: order.filter(function (row) { return matchesStudent(row, term); }) };
}

const api = { normalize, lastNameKey, matchesStudent, sortRows, countText, plan };
if (typeof module !== 'undefined' && module.exports) { module.exports = api; } else { globalThis.ClassPulseRoster = api; }
})();
