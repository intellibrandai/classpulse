// Pure FIFO save queue: exactly one operation in flight, no DOM access.
// States: 'saving', 'saved', 'failed' (retry() resumes), 'expired' (sign in again).
function createSaveQueue(options) {
  const queue = [];
  let inFlight = false;
  let halted = false;
  let state = 'saved';
  let highest = 0;

  function setState(next) {
    if (state === next) {
      return;
    }
    state = next;
    if (options.onStateChange) {
      options.onStateChange(next);
    }
  }

  function applyState(day, remaining) {
    if (!day || typeof day.day_version !== 'number' || day.day_version < highest) {
      return false;
    }
    highest = day.day_version;
    if (options.onApply) {
      options.onApply(day, remaining);
    }
    return true;
  }

  function finish() {
    inFlight = false;
    pump();
  }

  function handle(response) {
    const status = response && response.status;
    const body = response && response.body;

    if (status >= 200 && status < 300) {
      applyState(body && body.data, queue.length - 1);
      queue.shift();
      finish();
      return;
    }

    if (status === 409 || status === 422) {
      const error = (body && body.error) || {};
      const rejected = queue.shift();
      applyState(error.day, queue.length);
      if (options.onError) {
        // The second argument (the rejected operation) lets a caller that tracks its own edits forget it.
        options.onError(error.message || 'The change could not be saved.', rejected);
      }
      finish();
      return;
    }

    inFlight = false;
    halted = true;
    setState(status === 401 || status === 419 ? 'expired' : 'failed');
  }

  function fail() {
    inFlight = false;
    halted = true;
    setState('failed');
  }

  function pump() {
    if (inFlight || halted) {
      return;
    }
    if (queue.length === 0) {
      setState('saved');
      return;
    }
    inFlight = true;
    let request;
    try {
      request = Promise.resolve(options.send(queue[0]));
    } catch (error) {
      request = Promise.reject(error);
    }
    request.then(handle, fail);
  }

  return {
    enqueue(kind, studentId, extra) {
      const op = { op_id: options.newId(), kind };
      if (studentId !== undefined && studentId !== null) {
        op.student_id = studentId;
      }
      if (extra && typeof extra.points === 'number') {
        op.points = extra.points;
      }
      queue.push(op);
      if (!halted) {
        setState('saving');
      }
      pump();
      return op;
    },
    retry() {
      if (state !== 'failed') {
        return;
      }
      halted = false;
      setState('saving');
      pump();
    },
    applyServerState(day) {
      return applyState(day, queue.length);
    },
    pendingCount() {
      return queue.length;
    },
    state() {
      return state;
    },
    highestVersion() {
      return highest;
    },
  };
}

function shouldWarnBeforeUnload(queue) {
  return queue.pendingCount() > 0;
}

const api = { createSaveQueue, shouldWarnBeforeUnload };
if (typeof module !== 'undefined' && module.exports) { module.exports = api; } else { globalThis.ClassPulseSaveQueue = api; }
