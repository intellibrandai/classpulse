'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const lib = require('../../public/js/account.js');
const remember = require('../../public/js/login-remember.js');

function fakeStorage(initial) {
  const data = Object.assign({}, initial);
  return {
    data,
    writes: 0,
    getItem: (k) => (Object.prototype.hasOwnProperty.call(data, k) ? data[k] : null),
    setItem(k, v) { this.writes += 1; data[k] = String(v); },
    removeItem: (k) => { delete data[k]; },
  };
}
const blocked = {
  getItem() { throw new Error('SecurityError'); },
  setItem() { throw new Error('SecurityError'); },
  removeItem() { throw new Error('SecurityError'); },
};
const K = lib.REMEMBER_KEY;
const pair = () => {
  const attrs = {};
  return {
    input: { type: 'password' },
    button: { setAttribute(k, v) { attrs[k] = v; }, attrs },
  };
};

// ---- show / hide toggle ----------------------------------------------------------------------

test('the remembered-email key is the one login-remember.js owns', () => {
  assert.equal(lib.REMEMBER_KEY, remember.KEY);
});

test('toggle switches password to text and back, with aria-pressed and the next action as the label', () => {
  const { input, button } = pair();
  assert.equal(lib.toggle(input, button), true);
  assert.equal(input.type, 'text');
  assert.equal(button.attrs['aria-pressed'], 'true');
  assert.equal(button.attrs['aria-label'], 'Hide password');
  assert.equal(lib.toggle(input, button), false);
  assert.equal(input.type, 'password');
  assert.equal(button.attrs['aria-pressed'], 'false');
  assert.equal(button.attrs['aria-label'], 'Show password');
});

test('setVisible is idempotent and resetAll hides every field again', () => {
  const a = pair();
  const b = pair();
  lib.setVisible(a.input, a.button, true);
  lib.setVisible(a.input, a.button, true);
  lib.setVisible(b.input, b.button, true);
  assert.equal(a.input.type, 'text');
  lib.resetAll([a, b]);
  assert.deepEqual([a.input.type, b.input.type], ['password', 'password']);
  assert.deepEqual([a.button.attrs['aria-pressed'], b.button.attrs['aria-label']], ['false', 'Show password']);
});

test('toggles are independent: showing one field leaves the others hidden', () => {
  const a = pair();
  const b = pair();
  lib.toggle(a.input, a.button);
  assert.deepEqual([a.input.type, b.input.type], ['text', 'password']);
});

// ---- remembered email --------------------------------------------------------------------------

test('replaces the remembered email when it equals the old one, ignoring case and spaces', () => {
  const s = fakeStorage({ [K]: 'Teacher@ClassPulse.test' });
  assert.equal(lib.refreshRemembered(s, 'teacher@classpulse.test', 'new@example.test'), 'replaced');
  assert.equal(s.data[K], 'new@example.test');
  assert.deepEqual(Object.keys(s.data), [K]);
});

test('leaves a remembered email that is NOT the old one untouched', () => {
  const s = fakeStorage({ [K]: 'someone@else.test' });
  assert.equal(lib.refreshRemembered(s, 'teacher@classpulse.test', 'new@example.test'), 'unchanged');
  assert.equal(s.data[K], 'someone@else.test');
  assert.equal(s.writes, 0);
});

test('never stores anything when nothing was remembered', () => {
  const s = fakeStorage();
  assert.equal(lib.refreshRemembered(s, 'teacher@classpulse.test', 'new@example.test'), 'none');
  assert.deepEqual(s.data, {});
  assert.equal(s.writes, 0);
  const blank = fakeStorage({ [K]: '   ' });
  assert.equal(lib.refreshRemembered(blank, 'teacher@classpulse.test', 'new@example.test'), 'none');
  assert.equal(blank.writes, 0);
});

test('adds no other localStorage key', () => {
  const s = fakeStorage({ [K]: 'teacher@classpulse.test', 'classpulse-theme': 'dark' });
  lib.refreshRemembered(s, 'teacher@classpulse.test', 'new@example.test');
  assert.deepEqual(Object.keys(s.data).sort(), [K, 'classpulse-theme'].sort());
});

test('a blocked or missing storage is never an error', () => {
  assert.equal(lib.refreshRemembered(blocked, 'a@b.test', 'c@d.test'), 'blocked');
  assert.equal(lib.refreshRemembered(null, 'a@b.test', 'c@d.test'), 'blocked');
  const readOnly = { getItem: () => 'a@b.test', setItem() { throw new Error('QuotaExceeded'); } };
  assert.equal(lib.refreshRemembered(readOnly, 'a@b.test', 'c@d.test'), 'blocked');
});

test('refuses to store a malformed new address or to match on a malformed old one', () => {
  const s = fakeStorage({ [K]: 'a@b.test' });
  assert.equal(lib.refreshRemembered(s, 'a@b.test', 'not an email'), 'unchanged');
  assert.equal(lib.refreshRemembered(s, '', 'c@d.test'), 'unchanged');
  assert.equal(lib.refreshRemembered(s, null, 'c@d.test'), 'unchanged');
  assert.equal(s.data[K], 'a@b.test');
});
