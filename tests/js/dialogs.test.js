'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const lib = require('../../public/js/dialogs.js');

test('Tab wraps from the last control to the first and Shift+Tab from the first to the last', () => {
  assert.equal(lib.trapIndex(3, 4, false), 0);
  assert.equal(lib.trapIndex(0, 4, true), 3);
  assert.equal(lib.trapIndex(1, 4, false), 2);
  assert.equal(lib.trapIndex(2, 4, true), 1);
});

test('a focus on the container itself (or outside) enters the list at the matching end', () => {
  assert.equal(lib.trapIndex(-1, 4, false), 0);
  assert.equal(lib.trapIndex(-1, 4, true), 3);
  assert.equal(lib.trapIndex(9, 4, false), 0);
});

test('an empty container has nothing to focus', () => {
  assert.equal(lib.trapIndex(-1, 0, false), -1);
  assert.equal(lib.trapIndex(0, 0, true), -1);
});
