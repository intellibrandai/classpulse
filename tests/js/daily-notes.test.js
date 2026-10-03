const test = require('node:test');
const assert = require('node:assert/strict');
const notes = require('../../public/js/daily-notes.js');

test('isDirty compares trimmed text with the saved text', () => {
  assert.equal(notes.isDirty('Hello', 'Hello'), false);
  assert.equal(notes.isDirty('  Hello  ', 'Hello'), false);
  assert.equal(notes.isDirty('Hello!', 'Hello'), true);
  assert.equal(notes.isDirty('', ''), false);
  assert.equal(notes.isDirty('   ', ''), false);
  assert.equal(notes.isDirty('', 'Saved remark'), true);
  assert.equal(notes.isDirty(undefined, null), false);
});

test('counter shows n / 2000 and warns near and over the limit', () => {
  assert.deepEqual(notes.counter(0), { text: '0 / 2000', level: 'ok' });
  assert.deepEqual(notes.counter(1799), { text: '1799 / 2000', level: 'ok' });
  assert.deepEqual(notes.counter(1800), { text: '1800 / 2000', level: 'near' });
  assert.deepEqual(notes.counter(2000), { text: '2000 / 2000', level: 'near' });
  assert.deepEqual(notes.counter(2001), { text: '2001 / 2000', level: 'over' });
  assert.equal(notes.counter(5, 10).level, 'ok');
  assert.equal(notes.counter(9, 10).level, 'near');
});

test('transition: typing marks dirty, reverting the text returns to idle, saving never regresses', () => {
  assert.equal(notes.transition('idle', 'edit', true), 'dirty');
  assert.equal(notes.transition('dirty', 'edit', false), 'idle');
  assert.equal(notes.transition('saved', 'edit', true), 'dirty');
  assert.equal(notes.transition('saved', 'edit', false), 'saved');
  assert.equal(notes.transition('saving', 'edit', true), 'saving');
  assert.equal(notes.transition('dirty', 'save'), 'saving');
  assert.equal(notes.transition('error', 'save'), 'saving');
  assert.equal(notes.transition('saving', 'save'), 'saving');
  assert.equal(notes.transition('saving', 'success', false), 'saved');
  assert.equal(notes.transition('saving', 'success', true), 'dirty');
  assert.equal(notes.transition('saving', 'failure'), 'error');
  assert.equal(notes.transition('error', 'edit', true), 'dirty');
});

test('statusText gives visible text for every state', () => {
  assert.equal(notes.statusText('saving'), 'Saving…');
  assert.equal(notes.statusText('saved', { time: '14:05' }), 'Saved at 14:05');
  assert.equal(notes.statusText('saved', { time: '14:05', cleared: true }), 'Note cleared at 14:05');
  assert.equal(notes.statusText('dirty'), 'Unsaved changes');
  assert.equal(notes.statusText('error', { message: 'Not saved — no connection.' }), 'Not saved — no connection.');
  assert.equal(notes.statusText('error'), 'Could not save the note');
  assert.equal(notes.statusText('idle'), '');
});

test('clock formats HH:MM in the requested zone', () => {
  const instant = new Date('2026-10-21T18:05:00Z');
  assert.equal(notes.clock(instant, 'America/Toronto'), '14:05');
  assert.equal(notes.clock(instant, 'UTC'), '18:05');
});
