// Login "Remember my email": keeps ONLY the email address in this browser's localStorage when the box is ticked.
// Never stores a password or a token, never sends anything to the server, never touches the session lifetime.
// UMD like save-queue.js: the pure helpers are unit-tested in tests/js/login-remember.test.js.
(function (root, factory) {
  const api = factory();
  if (typeof module !== 'undefined' && module.exports) { module.exports = api; } else { root.ClassPulseLoginRemember = api; }
  if (typeof document !== 'undefined') { api.init(document, root); }
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  const KEY = 'classpulse.login.remembered-email';

  // A deliberately loose shape check: stops junk written by someone else, the server validates the real address.
  function clean(value) {
    if (typeof value !== 'string') { return ''; }
    const email = value.trim();
    return email.length > 0 && email.length <= 254 && /^[^\s@]+@[^\s@]+$/.test(email) ? email : '';
  }

  // Every storage call is wrapped: a blocked or full storage must never break the login form.
  function read(storage) {
    try { return clean(storage.getItem(KEY)); } catch (e) { return ''; }
  }

  function store(storage, email) {
    const value = clean(email);
    if (value === '') { return clear(storage); }
    try { storage.setItem(KEY, value); return true; } catch (e) { return false; }
  }

  function clear(storage) {
    try { storage.removeItem(KEY); return true; } catch (e) { return false; }
  }

  // Applies the stored email only to an EMPTY field (never over a value the server or a password manager put there).
  // Returns { remembered, prefilled, focusPassword }.
  function prefill(storage, fields) {
    const saved = read(storage);
    if (saved === '') { return { remembered: false, prefilled: false, focusPassword: false }; }
    fields.checkbox.checked = true;
    if (fields.email.value.trim() !== '') { return { remembered: true, prefilled: false, focusPassword: false }; }
    fields.email.value = saved;
    return { remembered: true, prefilled: true, focusPassword: true };
  }

  // Called on submit: ticked stores the trimmed email, unticked deletes it.
  function onSubmit(storage, fields) {
    return fields.checkbox.checked ? store(storage, fields.email.value) : clear(storage);
  }

  function init(doc, win) {
    const checkbox = doc.querySelector('[data-remember-email]');
    const form = checkbox ? checkbox.form : null;
    if (!checkbox || !form) { return; }
    const email = form.elements.namedItem('email');
    const password = form.elements.namedItem('password');
    if (!email || !password) { return; }
    let storage = null;
    try { storage = win.localStorage; } catch (e) { storage = null; }
    if (!storage) { return; }
    const fields = { email: email, password: password, checkbox: checkbox };

    const result = prefill(storage, fields);
    if (result.focusPassword) { password.focus(); }
    checkbox.addEventListener('change', function () { if (!checkbox.checked) { clear(storage); } });
    form.addEventListener('submit', function () { onSubmit(storage, fields); });
  }

  return { KEY: KEY, clean: clean, read: read, store: store, clear: clear, prefill: prefill, onSubmit: onSubmit, init: init };
});
