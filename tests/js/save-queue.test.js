'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const { createSaveQueue, shouldWarnBeforeUnload } = require('../../public/js/save-queue.js');

const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

// Lets already-settled promise callbacks run without any timer.
async function flush() {
  for (let i = 0; i < 10; i += 1) {
    await Promise.resolve();
  }
}

function day(version, extra) {
  return Object.assign({ day_version: version, entries: [], summary: {}, can_undo: true, replayed: false }, extra);
}

function harness() {
  const sent = [];
  const applied = [];
  const states = [];
  const errors = [];
  const queue = createSaveQueue({
    send(op) {
      return new Promise((resolve, reject) => {
        sent.push({ op, resolve, reject });
      });
    },
    newId: randomUUID,
    onApply(d) { applied.push(d); },
    onStateChange(s) { states.push(s); },
    onError(message) { errors.push(message); },
  });

  return { queue, sent, applied, states, errors };
}

test('10 rapid enqueues are sent one at a time, in order, with distinct v4 ids', async () => {
  const { queue, sent } = harness();

  for (let i = 1; i <= 10; i += 1) {
    queue.enqueue('increment', i);
  }
  assert.equal(sent.length, 1);
  assert.equal(queue.pendingCount(), 10);

  for (let i = 0; i < 10; i += 1) {
    assert.equal(sent.length, i + 1, 'only one operation is in flight');
    sent[i].resolve({ status: 200, body: { data: day(i + 1) } });
    await flush();
  }

  assert.equal(sent.length, 10);
  assert.deepEqual(sent.map((s) => s.op.student_id), [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);
  const ids = new Set(sent.map((s) => s.op.op_id));
  assert.equal(ids.size, 10);
  for (const id of ids) {
    assert.match(id, UUID_V4);
  }
  assert.equal(queue.pendingCount(), 0);
  assert.equal(queue.state(), 'saved');
});

test('rejection, 500 and a timeout-style rejection keep ops and retry resends the same op_id', async () => {
  const failures = [
    (entry) => entry.reject(new Error('network down')),
    (entry) => entry.resolve({ status: 500, body: null }),
    (entry) => entry.reject(new DOMException('The operation was aborted.', 'AbortError')),
  ];

  for (const fail of failures) {
    const { queue, sent } = harness();
    const first = queue.enqueue('increment', 1);
    queue.enqueue('increment', 2);

    fail(sent[0]);
    await flush();

    assert.equal(queue.state(), 'failed');
    assert.equal(queue.pendingCount(), 2);
    assert.equal(sent.length, 1, 'nothing more is sent while failed');

    queue.retry();
    assert.equal(queue.state(), 'saving');
    assert.equal(sent.length, 2);
    assert.equal(sent[1].op.op_id, first.op_id);
    assert.equal(sent[1].op.student_id, 1);

    sent[1].resolve({ status: 200, body: { data: day(1) } });
    await flush();
    assert.equal(sent.length, 3);
    assert.equal(sent[2].op.student_id, 2);
    sent[2].resolve({ status: 200, body: { data: day(2) } });
    await flush();
    assert.equal(queue.state(), 'saved');
    assert.equal(queue.pendingCount(), 0);
  }
});

test('409 and 422 drop the operation, apply error.day, report the message and continue', async () => {
  for (const status of [409, 422]) {
    const { queue, sent, applied, errors } = harness();
    queue.enqueue('decrement', 1);
    queue.enqueue('increment', 2);

    sent[0].resolve({ status, body: { error: { code: 'points_min', message: 'Points cannot go below 0.', day: day(4, { marker: 'error-day' }) } } });
    await flush();

    assert.equal(applied.length, 1);
    assert.equal(applied[0].marker, 'error-day');
    assert.deepEqual(errors, ['Points cannot go below 0.']);
    assert.equal(queue.pendingCount(), 1);
    assert.equal(sent.length, 2);
    assert.equal(sent[1].op.kind, 'increment');

    sent[1].resolve({ status: 200, body: { data: day(5) } });
    await flush();
    assert.equal(queue.state(), 'saved');
  }
});

test('an error without a day is reported and does not apply anything', async () => {
  const { queue, sent, applied, errors } = harness();
  queue.enqueue('increment', 1);

  sent[0].resolve({ status: 422, body: { error: { code: 'weekend', message: 'Weekends are not school days.' } } });
  await flush();

  assert.equal(applied.length, 0);
  assert.deepEqual(errors, ['Weekends are not school days.']);
  assert.equal(queue.pendingCount(), 0);
  assert.equal(queue.state(), 'saved');
});

test('a day_version lower than the highest applied is ignored', async () => {
  const { queue, sent, applied } = harness();

  assert.equal(queue.applyServerState(day(7)), true);
  assert.equal(queue.highestVersion(), 7);
  assert.equal(queue.applyServerState(day(6)), false);
  assert.equal(queue.applyServerState(day(7)), true);
  assert.equal(applied.length, 2);

  queue.enqueue('increment', 1);
  sent[0].resolve({ status: 200, body: { data: day(3) } });
  await flush();

  assert.equal(applied.length, 2);
  assert.equal(queue.highestVersion(), 7);
  assert.equal(queue.state(), 'saved');
});

test('saved is reported only after a 2xx empties the queue', async () => {
  const { queue, sent, states } = harness();
  assert.equal(queue.state(), 'saved');

  queue.enqueue('increment', 1);
  queue.enqueue('increment', 1);
  assert.equal(queue.state(), 'saving');

  sent[0].resolve({ status: 200, body: { data: day(1) } });
  await flush();
  assert.equal(queue.state(), 'saving');
  assert.deepEqual(states, ['saving']);

  sent[1].resolve({ status: 200, body: { data: day(2) } });
  await flush();
  assert.equal(queue.state(), 'saved');
  assert.deepEqual(states, ['saving', 'saved']);
});

test('401 and 419 set expired and keep every operation', async () => {
  for (const status of [401, 419]) {
    const { queue, sent } = harness();
    queue.enqueue('increment', 1);
    queue.enqueue('increment', 2);

    sent[0].resolve({ status, body: { error: { code: 'unauthenticated', message: 'Session expired — sign in again' } } });
    await flush();

    assert.equal(queue.state(), 'expired');
    assert.equal(queue.pendingCount(), 2);
    assert.equal(sent.length, 1);

    queue.enqueue('increment', 3);
    assert.equal(queue.state(), 'expired');
    assert.equal(queue.pendingCount(), 3);
    assert.equal(sent.length, 1);
  }
});

test('shouldWarnBeforeUnload is true only while operations are pending', async () => {
  const { queue, sent } = harness();
  assert.equal(shouldWarnBeforeUnload(queue), false);

  queue.enqueue('increment', 1);
  assert.equal(shouldWarnBeforeUnload(queue), true);

  sent[0].resolve({ status: 200, body: { data: day(1) } });
  await flush();
  assert.equal(shouldWarnBeforeUnload(queue), false);
});

test('batch operations carry no student_id', () => {
  const { queue, sent } = harness();

  queue.enqueue('undo');

  assert.equal(sent[0].op.kind, 'undo');
  assert.equal('student_id' in sent[0].op, false);
});

test('enqueue can carry an absolute points value for set_points', async () => {
  const { queue, sent } = harness();
  const op = queue.enqueue('set_points', 4, { points: 12 });
  assert.deepEqual(Object.keys(sent[0].op).sort(), ['kind', 'op_id', 'points', 'student_id']);
  assert.equal(sent[0].op.points, 12);
  assert.equal(op.points, 12);
  queue.enqueue('increment', 4, { points: 'ignored' });
  assert.equal('points' in queue.enqueue('undo'), false);
});

test('onError receives the rejected operation so a caller can forget its own edit', async () => {
  const rejected = [];
  const queue = createSaveQueue({
    send() { return Promise.resolve({ status: 409, body: { error: { code: 'student_absent', message: 'Mark present first.' } } }); },
    newId: randomUUID,
    onError(message, op) { rejected.push([message, op.kind, op.op_id]); },
  });
  const op = queue.enqueue('set_points', 1, { points: 3 });
  await flush();
  assert.deepEqual(rejected, [['Mark present first.', 'set_points', op.op_id]]);
});
