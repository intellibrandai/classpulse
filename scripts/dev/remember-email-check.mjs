#!/usr/bin/env node
// DEV-ONLY (not shipped): real-browser check of the login "Remember my email" checkbox against the LOCAL app.
//   node scripts/dev/remember-email-check.mjs
// Uses real key events in a fresh headless Chrome profile and inspects localStorage and cookies after every step.
// Never prints the password. The login route allows 5 attempts per minute: this script makes 4 POSTs.
import { Browser, sleep } from './lib/cdp.mjs';

const KEY = 'classpulse.login.remembered-email';
const b = new Browser();
let failed = 0;
const check = (ok, label) => { console.log((ok ? 'PASS ' : 'FAIL ') + label); if (!ok) failed++; };
const stored = () => b.eval(`localStorage.getItem(${JSON.stringify(KEY)})`);
const state = () => b.eval(`({ email: document.querySelector('#email').value, checked: document.querySelector('#remember-email').checked, focus: document.activeElement.id, path: location.pathname })`);

try {
  await b.launch({ width: 1287, height: 900 });
  await b.goto('/login');
  let s = await state();
  check(s.email === '' && s.checked === false, 'fresh profile: email empty, box unchecked (default)');
  check((await stored()) === null, 'fresh profile: nothing stored');
  check(await b.eval(`document.querySelector('#remember-email').getAttribute('name') === null`), 'the box has no name, so it is never posted');

  // 1. tick the box, log in (real keys)
  let path = await b.loginWithKeys({ remember: true });
  check(path === '/daily', 'login with the box ticked reaches /daily');
  check((await stored()) === 'teacher@classpulse.test', 'localStorage holds exactly the trimmed email');
  check(await b.eval(`localStorage.length === 1`), 'localStorage holds nothing else');
  check(!(await b.passwordStored()), 'the password is not in localStorage, sessionStorage or any cookie');
  const cookies = await b.cookieNames();
  check(!cookies.some((c) => /^remember_/.test(c.name)), 'no Laravel remember-me cookie (' + cookies.map((c) => c.name.replace(/[A-Za-z0-9%]{20,}/, '...')).join(', ') + ')');
  const session = cookies.find((c) => /session/i.test(c.name));
  check(Boolean(session) && (session.session || session.expires - Date.now() / 1000 <= 481 * 60), 'session cookie lifetime not lengthened (480 minutes configured)');

  // 2. log out with the keyboard, revisit: prefilled, checked, focus on the password
  await b.eval(`document.querySelector('[data-account-toggle]').focus(); true`);
  await b.press('Enter');
  await b.eval(`document.querySelector('#account-menu [role=menuitem]').focus(); true`);
  const out = b.waitEvent('Page.loadEventFired');
  await b.press('Enter');
  await out;
  await sleep(300);
  s = await state();
  check(s.path === '/login', 'logged out to /login');
  check(s.email === 'teacher@classpulse.test' && s.checked === true, 'revisit: email prefilled and box checked');
  check(s.focus === 'password', 'revisit: focus is on the password field (got ' + s.focus + ')');

  // 3. unchecking deletes immediately (Space on the box)
  await b.eval(`document.querySelector('#remember-email').focus(); true`);
  await b.press('Space');
  check((await stored()) === null, 'unchecking removes the stored email at once');
  await b.goto('/login');
  s = await state();
  check(s.email === '' && s.checked === false, 'next visit after unchecking: empty and unchecked');

  // 4. ticked + wrong password: the page reloads with old(email), the email is stored (submit happened), box state from storage
  path = await b.loginWithKeys({ remember: true, wrongPassword: true });
  s = await state();
  check(path === '/login' && s.email === 'teacher@classpulse.test', 'wrong password keeps the typed email (old input)');
  check((await stored()) === 'teacher@classpulse.test' && s.checked === true, 'ticked submit stored the email; box is ticked again after the reload');

  // 5. unticked submit deletes
  await b.eval(`document.querySelector('#remember-email').checked = true; true`);
  path = await b.loginWithKeys({ remember: false, wrongPassword: true });
  check((await stored()) === null, 'submit with the box unticked deletes the stored email');

  // 6. password-manager friendliness: an already filled value is not overwritten
  await b.eval(`localStorage.setItem(${JSON.stringify(KEY)}, 'someone@else.test'); true`);
  await b.goto('/login?prefilled=1');
  s = await state();
  check(s.email === 'someone@else.test' && s.focus === 'password', 'stored email is used only when the field is empty (empty here)');
  const attrs = await b.eval(`(() => { const e = document.querySelector('#email'), p = document.querySelector('#password'); return [e.type, e.name, e.autocomplete, p.type, p.name, p.autocomplete, document.querySelector('form [autocomplete=off]:not([type=hidden])') === null]; })()`);
  check(JSON.stringify(attrs) === JSON.stringify(['email', 'email', 'username', 'password', 'password', 'current-password', true]), 'autocomplete attributes: ' + attrs.join(' '));
  await b.eval(`localStorage.removeItem(${JSON.stringify(KEY)}); true`);
} catch (error) {
  console.error('remember-email-check failed: ' + error.message);
  failed++;
} finally {
  await b.close();
}
console.log(failed === 0 ? '\nALL CHECKS PASSED' : '\n' + failed + ' CHECK(S) FAILED');
process.exit(failed ? 1 : 0);
