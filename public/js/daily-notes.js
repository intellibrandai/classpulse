// Pure helpers for the Daily Tracker student note editor: character counter, dirty check, state machine, status text.
// UMD footer so tests/js can require() it; the browser gets globalThis.ClassPulseDailyNotes.
(function () {
  var MAX = 2000;

  // Trimmed comparison: the server trims the body, so trailing spaces are not a change.
  function isDirty(current, saved) {
    return String(current || '').trim() !== String(saved || '').trim();
  }

  // "12 / 2000" plus a level: ok, near (>= 90%), over (> max).
  function counter(length, max) {
    var limit = max || MAX;
    var level = length > limit ? 'over' : (length >= limit * 0.9 ? 'near' : 'ok');
    return { text: length + ' / ' + limit, level: level };
  }

  // States: idle (nothing typed or unchanged) | dirty | saving | saved | error.
  // Events: edit(dirty) | save | success(dirty) | failure. "success" with dirty=true means the text changed while saving.
  function transition(state, event, dirty) {
    if (event === 'edit') {
      if (state === 'saving') {
        return 'saving';
      }
      return dirty ? 'dirty' : (state === 'saved' ? 'saved' : 'idle');
    }
    if (event === 'save') {
      return 'saving';
    }
    if (event === 'success') {
      return dirty ? 'dirty' : 'saved';
    }
    if (event === 'failure') {
      return 'error';
    }
    return state;
  }

  // Clock time HH:MM (24 h) in the given IANA zone; falls back to the runtime zone.
  function clock(date, timeZone) {
    var options = { hour: '2-digit', minute: '2-digit', hour12: false };
    if (timeZone) {
      options.timeZone = timeZone;
    }
    return new Intl.DateTimeFormat('en-GB', options).format(date);
  }

  function statusText(state, info) {
    var details = info || {};
    if (state === 'saving') {
      return 'Saving…';
    }
    if (state === 'saved') {
      if (details.cleared) {
        return 'Note cleared' + (details.time ? ' at ' + details.time : '');
      }
      return 'Saved' + (details.time ? ' at ' + details.time : '');
    }
    if (state === 'dirty') {
      return 'Unsaved changes';
    }
    if (state === 'error') {
      return details.message || 'Could not save the note';
    }
    return '';
  }

  var api = {
    MAX: MAX,
    isDirty: isDirty,
    counter: counter,
    transition: transition,
    clock: clock,
    statusText: statusText,
  };

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = api;
  } else {
    globalThis.ClassPulseDailyNotes = api;
  }
})();
