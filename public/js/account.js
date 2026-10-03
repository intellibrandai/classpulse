// Account settings (Class Roster & Settings > Account): show/hide password toggles, opening the group from the
// #account link or hash, focusing the first error, and refreshing "Remember my email" after an email change.
// UMD like login-remember.js: the pure helpers are unit-tested in tests/js/account.test.js.
// Never reads, stores or sends a password; the only localStorage key touched is the one login-remember.js already owns.
(function (root, factory) {
  const api = factory();
  if (typeof module !== 'undefined' && module.exports) { module.exports = api; } else { root.ClassPulseAccount = api; }
  if (typeof document !== 'undefined') { api.init(document, root); }
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  // Same key as login-remember.js (a unit test asserts both stay equal).
  const REMEMBER_KEY = 'classpulse.login.remembered-email';

  // ---- show / hide password ---------------------------------------------------------------

  // Sets one toggle and its input to a state. visible=true shows the text; the button always says what a press does next.
  function setVisible(input, button, visible) {
    input.type = visible ? 'text' : 'password';
    button.setAttribute('aria-pressed', visible ? 'true' : 'false');
    button.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
    return visible;
  }

  function toggle(input, button) {
    return setVisible(input, button, input.type === 'password');
  }

  // Every field back to hidden (page load, submit, back/forward cache restore).
  function resetAll(pairs) {
    pairs.forEach(function (pair) { setVisible(pair.input, pair.button, false); });
  }

  // ---- remembered email -------------------------------------------------------------------

  function shape(value) {
    if (typeof value !== 'string') { return ''; }
    const email = value.trim();
    return email.length > 0 && email.length <= 254 && /^[^\s@]+@[^\s@]+$/.test(email) ? email : '';
  }

  // After a successful email change: replace the remembered address ONLY when one is stored and equals the old address
  // (case-insensitive). Never stores anything when nothing was remembered. A blocked storage is never an error.
  // Returns 'replaced' | 'unchanged' | 'none' | 'blocked'.
  function refreshRemembered(storage, oldEmail, newEmail) {
    if (!storage) { return 'blocked'; }
    let stored = null;
    try { stored = storage.getItem(REMEMBER_KEY); } catch (e) { return 'blocked'; }
    if (typeof stored !== 'string' || stored.trim() === '') { return 'none'; }
    const previous = shape(oldEmail);
    const next = shape(newEmail);
    if (previous === '' || next === '' || stored.trim().toLowerCase() !== previous.toLowerCase()) { return 'unchanged'; }
    try { storage.setItem(REMEMBER_KEY, next); return 'replaced'; } catch (e) { return 'blocked'; }
  }

  // ---- wiring -----------------------------------------------------------------------------

  function init(doc, win) {
    const group = doc.querySelector('[data-account-group]');
    if (!group) { return; }

    // Toggles
    const pairs = [];
    doc.querySelectorAll('[data-pw-field]').forEach(function (wrap) {
      const input = wrap.querySelector('input');
      const button = wrap.querySelector('[data-pw-toggle]');
      if (!input || !button) { return; }
      pairs.push({ input: input, button: button });
      // Focus stays on the button (mouse click and keyboard alike), so Space/Enter can be pressed again to hide.
      button.addEventListener('click', function () { toggle(input, button); });
    });
    resetAll(pairs);
    group.querySelectorAll('form').forEach(function (form) {
      form.addEventListener('submit', function () { resetAll(pairs); });
    });
    win.addEventListener('pageshow', function (event) {
      if (event.persisted) {
        resetAll(pairs);
        pairs.forEach(function (pair) { pair.input.value = ''; });
      }
    });

    // Open from the avatar-menu link or the hash (a closed <details> would hide the target).
    function reveal(focusSummary) {
      group.open = true;
      group.scrollIntoView({ block: 'start' });
      if (focusSummary) {
        const summary = group.querySelector('summary');
        if (summary) { summary.focus({ preventScroll: true }); }
      }
    }
    doc.querySelectorAll('a[data-account-link]').forEach(function (link) {
      link.addEventListener('click', function (event) {
        event.preventDefault();
        win.history.replaceState(null, '', '#account');
        reveal(true);
      });
    });
    win.addEventListener('hashchange', function () { if (win.location.hash === '#account') { reveal(false); } });
    if (win.location.hash === '#account') { group.open = true; }

    // A failed submit focuses the first invalid field; a success focuses its message. Done after the load event on
    // purpose: the browser's scroll-to-#account step moves focus to the document and would undo an earlier focus().
    const invalid = group.querySelector('[aria-invalid="true"]');
    const flash = group.querySelector('[data-account-flash]');
    if (invalid) { group.open = true; }
    function focusResult() {
      if (invalid) {
        invalid.focus();
      } else if (flash && (doc.activeElement === doc.body || !doc.activeElement)) {
        flash.focus();
      }
    }
    if (invalid || flash) {
      if (doc.readyState === 'complete') { win.setTimeout(focusResult, 0); } else { win.addEventListener('load', function () { win.setTimeout(focusResult, 0); }); }
    }

    // Email changed: refresh "Remember my email" in this browser only if it held the OLD address.
    if (flash) {
      let storage = null;
      try { storage = win.localStorage; } catch (e) { storage = null; }
      refreshRemembered(storage, flash.getAttribute('data-old-email'), flash.getAttribute('data-new-email'));
    }
  }

  return { REMEMBER_KEY: REMEMBER_KEY, setVisible: setVisible, toggle: toggle, resetAll: resetAll, refreshRemembered: refreshRemembered, init: init };
});
