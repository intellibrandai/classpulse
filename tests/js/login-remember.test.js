'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const lib = require('../../public/js/login-remember.js');

function fakeStorage(initial) {
  const data = Object.assign({}, initial);
  return {
    data,
    getItem: (k) => (Object.prototype.hasOwnProperty.call(data, k) ? data[k] : null),
    setItem: (k, v) => { data[k] = String(v); },
    removeItem: (k) => { delete data[k]; },
  };
}
const blocked = {
  getItem() { throw new Error('SecurityError'); },
  setItem() { throw new Error('SecurityError'); },
  removeItem() { throw new Error('SecurityError'); },
};
const fields = (email, checked) => ({ email: { value: email }, password: { value: '' }, checkbox: { checked: Boolean(checked) } });

test('store keeps only the trimmed email under the namespaced key', () => {
  const s = fakeStorage();
  assert.equal(lib.store(s, '  teacher@classpulse.test  '), true);
  assert.deepEqual(Object.keys(s.data), [lib.KEY]);
  assert.equal(s.data[lib.KEY], 'teacher@classpulse.test');
  assert.match(lib.KEY, /^classpulse\./);
});

test('store refuses empty and malformed values and removes any previous email', () => {
  const s = fakeStorage({ [lib.KEY]: 'old@example.test' });
  lib.store(s, '   ');
  assert.equal(lib.KEY in s.data, false);
  lib.store(s, 'not an email');
  assert.equal(lib.KEY in s.data, false);
});

test('clear deletes the stored email', () => {
  const s = fakeStorage({ [lib.KEY]: 'a@b.test' });
  assert.equal(lib.clear(s), true);
  assert.equal(lib.read(s), '');
});

test('prefill fills an empty email field, ticks the box and asks for password focus', () => {
  const s = fakeStorage({ [lib.KEY]: 'a@b.test' });
  const f = fields('', false);
  assert.deepEqual(lib.prefill(s, f), { remembered: true, prefilled: true, focusPassword: true });
  assert.equal(f.email.value, 'a@b.test');
  assert.equal(f.checkbox.checked, true);
});

test('prefill never overwrites a value that is already there (old input or a password manager)', () => {
  const s = fakeStorage({ [lib.KEY]: 'a@b.test' });
  const f = fields('typed@b.test', false);
  assert.deepEqual(lib.prefill(s, f), { remembered: true, prefilled: false, focusPassword: false });
  assert.equal(f.email.value, 'typed@b.test');
});

test('prefill does nothing when nothing is stored or the stored value is junk', () => {
  const f = fields('', false);
  assert.equal(lib.prefill(fakeStorage(), f).remembered, false);
  assert.equal(lib.prefill(fakeStorage({ [lib.KEY]: '<script>' }), f).remembered, false);
  assert.equal(f.email.value, '');
  assert.equal(f.checkbox.checked, false);
});

test('submit with the box ticked stores, unticked deletes', () => {
  const s = fakeStorage();
  lib.onSubmit(s, fields(' a@b.test ', true));
  assert.equal(s.data[lib.KEY], 'a@b.test');
  lib.onSubmit(s, fields('a@b.test', false));
  assert.equal(lib.KEY in s.data, false);
});

test('never stores anything but the email (no password, no token)', () => {
  const s = fakeStorage();
  const f = fields('a@b.test', true);
  f.password.value = 'secret-password';
  lib.onSubmit(s, f);
  assert.equal(JSON.stringify(s.data).includes('secret-password'), false);
  assert.equal(Object.keys(s.data).length, 1);
});

test('blocked storage never throws', () => {
  assert.equal(lib.read(blocked), '');
  assert.equal(lib.store(blocked, 'a@b.test'), false);
  assert.equal(lib.clear(blocked), false);
  assert.deepEqual(lib.prefill(blocked, fields('', false)), { remembered: false, prefilled: false, focusPassword: false });
  assert.doesNotThrow(() => lib.onSubmit(blocked, fields('a@b.test', true)));
});
