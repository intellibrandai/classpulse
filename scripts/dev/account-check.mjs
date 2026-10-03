#!/usr/bin/env node
// DEV-ONLY (not shipped): end-to-end proof of Account settings (change email / change password) against the LOCAL app.
//   node scripts/dev/account-check.mjs [--no-shots] [--shots-dir storage/app/final-v6]
// Creates a SCRATCH user (ZZ scratch, zz-scratch-<random>@example.test) with random credentials that live only in memory
// (nothing is written to disk or printed), drives three headless Chrome profiles with REAL mouse clicks and key events,
// then DELETES the scratch user and proves the dev database is back to exactly the original state (teacher hash compared
// as a SHA-256 of the stored hash, never printed). It never touches the teacher account, classes, students or notes.
// Failed attempts are rate limited (5 per minute): the script clears the file cache (dev only) where it needs a fresh window.
import { execFileSync } from 'node:child_process';
import { createHash, randomBytes } from 'node:crypto';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { Browser, ROOT, parseFlags, sleep } from './lib/cdp.mjs';

const flags = parseFlags(process.argv.slice(2));
const SHOTS = !flags['no-shots'];
const SHOT_DIR = join(ROOT, String(flags['shots-dir'] || 'storage/app/final-v6'));
const TEACHER = 'teacher@classpulse.test';
const REMEMBER_KEY = 'classpulse.login.remembered-email';
const Q = JSON.stringify;

let failed = 0;
let passed = 0;
const check = (ok, label) => { console.log((ok ? 'PASS ' : 'FAIL ') + label); if (ok) passed++; else failed++; };

// ---- random in-memory credentials ---------------------------------------------------------------------------------
const ALPHABET = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789-_!#';
function randomPassword(length = 20) {
  const bytes = randomBytes(length);
  return Array.from(bytes, (b) => ALPHABET[b % ALPHABET.length]).join('');
}
const stamp = randomBytes(4).toString('hex');
const EMAIL1 = `zz-scratch-${stamp}@example.test`;
const EMAIL2 = `zz-scratch-${stamp}-b@example.test`;
const EMAIL3_TYPED = `Zz-Scratch-${stamp}-C@Example.TEST`;
const PW0 = randomPassword(20);
const PW11 = randomPassword(11);
const PW12 = randomPassword(12);
const WRONG = randomPassword(16);
const SECRETS = [PW0, PW11, PW12, WRONG];

// ---- database side (tinker over stdin: secrets never appear in a process list or in the output) ------------------------
function tinker(code) {
  const out = execFileSync('docker', ['compose', 'exec', '-T', 'app', 'php', 'artisan', 'tinker'], { cwd: ROOT, input: code + '\n', encoding: 'utf8', maxBuffer: 1 << 24 });
  const match = /@@JSON@@(\{.*\}|\[.*\]|true|false|null|"[^"]*"|\d+)\s*$/m.exec(out.replace(/\x1b\[[0-9;]*m/g, ''));
  return match ? JSON.parse(match[1]) : null;
}
const J = (expr) => `echo "@@"."JSON@@".json_encode(${expr}), PHP_EOL;`;
const snapshot = () => tinker(J(`[
  'users' => App\\Models\\User::count(), 'classes' => App\\Models\\SchoolClass::count(), 'students' => App\\Models\\Student::count(),
  'notes' => DB::table('student_notes')->count(), 'drafts' => DB::table('report_comment_drafts')->count(),
  'entries' => DB::table('participation_entries')->count(),
  'teacher' => hash('sha256', (string) App\\Models\\User::where('email', ${Q(TEACHER)})->value('password')),
  'teacher_updated' => (string) App\\Models\\User::where('email', ${Q(TEACHER)})->value('updated_at'),
]`));
const createScratch = (email, password) => tinker(`$u = new App\\Models\\User(); $u->name = 'ZZ scratch'; $u->email = ${Q(email)}; $u->password = Illuminate\\Support\\Facades\\Hash::make(${Q(password)}); $u->save(); ${J('$u->id')}`);
const deleteScratch = () => tinker(`$ids = App\\Models\\User::where('name', 'ZZ scratch')->pluck('id'); DB::table('sessions')->whereIn('user_id', $ids)->delete(); App\\Models\\User::whereIn('id', $ids)->delete(); ${J('$ids->count()')}`);
const userState = (email) => tinker(J(`(function () { $u = App\\Models\\User::where('email', ${Q(email)})->first(); return $u ? ['id' => $u->id, 'sessions' => DB::table('sessions')->where('user_id', $u->id)->count(), 'bcrypt' => str_starts_with($u->password, '$2y$')] : null; })()`));
const passwordIs = (email, password) => tinker(J(`(function () { $u = App\\Models\\User::where('email', ${Q(email)})->first(); return $u !== null && Illuminate\\Support\\Facades\\Hash::check(${Q(password)}, $u->password); })()`));
const emailExists = (email) => tinker(J(`App\\Models\\User::where('email', ${Q(email)})->exists()`));
const scratchHash = (email) => tinker(J(`hash('sha256', (string) App\\Models\\User::where('email', ${Q(email)})->value('password'))`));
const clearThrottle = () => execFileSync('docker', ['compose', 'exec', '-T', 'app', 'php', 'artisan', 'cache:clear'], { cwd: ROOT, stdio: 'ignore' });

// ---- browser helpers (real mouse and keys) -------------------------------------------------------------------------
const browsers = [];
async function newBrowser(width = 1287, height = 1250) {
  const b = new Browser();
  await b.launch({ width, height });
  await b.send('Network.enable');
  await b.send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
  await b.send('Page.addScriptToEvaluateOnNewDocument', { source: `(() => { try { const sheet = new CSSStyleSheet(); sheet.replaceSync('*, *::before, *::after, ::backdrop { backdrop-filter: none !important; -webkit-backdrop-filter: none !important; }'); document.adoptedStyleSheets = [sheet]; } catch (e) { /* ignore */ } })()` });
  browsers.push(b);
  await b.theme('dark');
  return b;
}
const center = (b, sel) => b.eval(`(() => { const el = document.querySelector(${Q(sel)}); if (!el) throw new Error('no element ' + ${Q(sel)}); el.scrollIntoView({ block: 'center' }); const r = el.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2 }; })()`);
async function click(b, sel) {
  await center(b, sel);
  await sleep(60);
  const { x, y } = await center(b, sel);
  await b.mouseClick(x, y);
}
async function clickNav(b, sel) {
  const loaded = b.waitEvent('Page.loadEventFired', 20000);
  await click(b, sel);
  await loaded;
  await b.send('Page.bringToFront');
  await b.settle(300);
}
async function typeInto(b, sel, text) {
  await b.eval(`document.querySelector(${Q(sel)}).value = ''; true`);
  await click(b, sel);
  await b.typeText(text);
}
const val = (b, sel) => b.eval(`document.querySelector(${Q(sel)}).value`);
const text = (b, sel) => b.eval(`(document.querySelector(${Q(sel)}) || { textContent: '' }).textContent.replace(/\\s+/g, ' ').trim()`);
const activeId = (b) => b.eval(`document.activeElement ? (document.activeElement.id || document.activeElement.tagName.toLowerCase() + (document.activeElement.hasAttribute('data-pw-toggle') ? '[toggle:' + document.activeElement.getAttribute('aria-controls') + ']' : '')) : 'none'`);
const stored = (b) => b.eval(`localStorage.getItem(${Q(REMEMBER_KEY)})`);

// Everything a browser keeps or shows that could hold a secret: cookies, storages, URL, the whole DOM.
async function leaks(b) {
  const { cookies } = await b.send('Network.getAllCookies');
  const cookieText = cookies.map((c) => c.value + decodeURIComponent(c.value)).join('|');
  const page = await b.eval(`(() => { const dump = []; for (const s of [localStorage, sessionStorage]) for (let i = 0; i < s.length; i++) dump.push(s.key(i) + '=' + s.getItem(s.key(i))); return location.href + '\\n' + dump.join('\\n') + '\\n' + document.documentElement.outerHTML; })()`);
  return SECRETS.filter((s) => cookieText.includes(s) || page.includes(s) || page.includes(encodeURIComponent(s)));
}

async function scratchLogin(b, email, password, { remember = false } = {}) {
  await b.goto('/login');
  await b.eval(`document.querySelector('#email').value = ''; document.querySelector('#email').focus(); true`);
  await b.typeText(email);
  await b.press('Tab');
  await b.typeText(password);
  await b.press('Tab');
  const isChecked = await b.eval('document.activeElement.checked');
  if (remember !== isChecked) await b.press('Space');
  await b.press('Tab');
  const loaded = b.waitEvent('Page.loadEventFired', 20000);
  await b.press('Enter');
  await loaded;
  await b.send('Page.bringToFront');
  await b.settle(300);
  return b.eval('location.pathname');
}

async function shot(b, name, theme = null) {
  if (!SHOTS) return;
  mkdirSync(SHOT_DIR, { recursive: true });
  const themes = theme ? [theme] : ['dark', 'light'];
  for (const t of themes) {
    await b.theme(t);
    await b.settle(150);
    await b.screenshot(join(SHOT_DIR, `account-${name}-${t}.png`));
  }
  await b.theme('dark');
}
async function scrollToAccount(b) {
  await b.eval(`(() => { const g = document.getElementById('account'); g.open = true; g.scrollIntoView({ block: 'start' }); window.scrollBy(0, -88); return true; })()`);
  await sleep(150);
}
async function openAccountFromMenu(b) {
  await click(b, '[data-account-toggle]');
  await b.waitFor(`document.querySelector('#account-menu') && !document.querySelector('#account-menu').hidden`, 5000, 'avatar menu');
}
async function submitForm(b, formSel) {
  const loaded = b.waitEvent('Page.loadEventFired', 20000);
  await click(b, formSel + ' button[type=submit]');
  await loaded;
  await b.send('Page.bringToFront');
  await b.settle(350);
}
const signedInAs = (b) => b.eval(`(document.querySelector('.account-address') || { textContent: '' }).textContent.trim()`);

let before = null;
let scratchCreated = false;
try {
  console.log('Baseline and scratch user');
  before = snapshot();
  check(before && before.users === 1 && before.classes === 3 && before.students === 51 && before.notes === 0 && before.drafts === 0, `dev DB baseline: ${before && before.users} user, ${before && before.classes} classes, ${before && before.students} students, ${before && before.notes} notes, ${before && before.drafts} drafts`);
  if (!before || before.users !== 1) throw new Error('Unexpected baseline: refusing to continue');
  createScratch(EMAIL1, PW0);
  scratchCreated = true;
  check((await emailExists(EMAIL1)) === true && (await passwordIs(EMAIL1, PW0)) === true, 'scratch user created with a random strong password (kept in memory only)');
  clearThrottle();

  // ================================================================ browser 1: mouse
  console.log('\nBrowser 1: real mouse, "Remember my email" ticked');
  const b1 = await newBrowser();
  let path = await scratchLogin(b1, EMAIL1, PW0, { remember: true });
  check(path === '/daily', 'scratch user signs in (real keys) and reaches /daily');
  check((await stored(b1)) === EMAIL1, 'Remember my email ticked: the OLD email is stored in this browser');

  // Avatar menu -> Account settings
  await openAccountFromMenu(b1);
  check((await text(b1, '#account-menu a[data-account-link]')) === 'Account settings', 'avatar menu has the "Account settings" item');
  check(await b1.eval(`document.querySelector('#account-menu a[data-account-link] svg use').getAttribute('href') === '#icon-user'`), 'the item carries the user icon');
  check(await b1.eval(`document.activeElement === document.querySelector('#account-menu [role=menuitem]')`), 'first menu item receives focus when the menu opens');
  await b1.eval('window.scrollTo(0, 0); true');
  await shot(b1, 'avatar-menu-1287');
  await clickNav(b1, '#account-menu a[data-account-link]');
  check((await b1.eval('location.pathname + location.hash')) === '/roster#account', 'clicking it opens /roster#account');
  check(await b1.eval(`document.getElementById('account').open === true`), 'the Account group opens from the #account hash');
  check((await text(b1, '[data-account-current-email]')) === EMAIL1, 'the signed-in email is shown read-only at the top of the group');
  // Same page again: close the group, use the menu link in place
  await b1.eval(`document.getElementById('account').open = false; true`);
  await openAccountFromMenu(b1);
  await click(b1, '#account-menu a[data-account-link]');
  await sleep(150);
  check(await b1.eval(`document.getElementById('account').open === true && document.activeElement === document.querySelector('#account > summary')`), 'on the Roster page the menu link reopens the group and focuses its heading');
  await scrollToAccount(b1);
  await shot(b1, 'group-open-1287');

  // Show / hide toggles (mouse)
  const FIELDS = ['account-email-password', 'account-current-password', 'account-new-password', 'account-confirm-password'];
  let toggleOk = true;
  for (const id of FIELDS) {
    const sel = `[data-pw-toggle][aria-controls=${id}]`;
    const state = () => b1.eval(`(() => { const i = document.getElementById(${Q(id)}); const t = document.querySelector(${Q(sel)}); const r = t.getBoundingClientRect(); return [i.type, t.getAttribute('aria-pressed'), t.getAttribute('aria-label'), Math.round(r.width) >= 40 && Math.round(r.height) >= 40]; })()`);
    const s0 = await state();
    await typeInto(b1, '#' + id, 'Visible-demo-123');
    await click(b1, sel);
    const s1 = await state();
    if (id === 'account-new-password') await shot(b1, 'toggle-visible-1287');
    await click(b1, sel);
    const s2 = await state();
    toggleOk = toggleOk && JSON.stringify(s0) === JSON.stringify(['password', 'false', 'Show password', true]) && JSON.stringify(s1) === JSON.stringify(['text', 'true', 'Hide password', true]) && JSON.stringify(s2) === JSON.stringify(['password', 'false', 'Show password', true]);
    await b1.eval(`document.getElementById(${Q(id)}).value = ''; true`);
  }
  check(toggleOk, 'all four toggles: hidden by default, click shows (aria-pressed true, "Hide password"), click hides again, target >= 40px');
  check(await b1.eval(`Array.from(document.querySelectorAll('[data-pw-toggle]')).every((t) => document.getElementById(t.getAttribute('aria-controls')) && t.type === 'button')`), 'every toggle is a button[type=button] whose aria-controls points at its input');

  // Failure 1: wrong current password, email form
  await typeInto(b1, '#account-new-email', EMAIL2);
  await typeInto(b1, '#account-email-password', WRONG);
  await submitForm(b1, 'form[action$="/account/email"]');
  check((await b1.eval('location.pathname + location.hash')) === '/roster#account', 'wrong password (email form): redirected back to /roster#account');
  check((await text(b1, '#account-email-password-error')) === 'The current password is incorrect.', 'wrong password (email form): "The current password is incorrect."');
  check((await activeId(b1)) === 'account-email-password', 'wrong password (email form): focus moves to the first invalid field');
  check((await emailExists(EMAIL1)) === true && (await emailExists(EMAIL2)) === false && (await signedInAs(b1)) === EMAIL1, 'wrong password (email form): email NOT changed');
  check((await val(b1, '#account-email-password')) === '' && (await val(b1, '#account-new-email')) === EMAIL2, 'password field is empty again, the typed new email is kept');
  await scrollToAccount(b1);
  await shot(b1, 'error-wrong-current-1287');

  // Failure 2: wrong current password, password form (good new password)
  await typeInto(b1, '#account-current-password', WRONG);
  await typeInto(b1, '#account-new-password', PW12);
  await typeInto(b1, '#account-confirm-password', PW12);
  await submitForm(b1, 'form[action$="/account/password"]');
  check((await text(b1, '#account-current-password-error')) === 'The current password is incorrect.', 'wrong password (password form): clear error');
  check((await passwordIs(EMAIL1, PW0)) === true && (await passwordIs(EMAIL1, PW12)) === false, 'wrong password (password form): password NOT changed (the old one still works)');

  // Failure 3: 11 characters
  await typeInto(b1, '#account-current-password', PW0);
  await typeInto(b1, '#account-new-password', PW11);
  await typeInto(b1, '#account-confirm-password', PW11);
  await submitForm(b1, 'form[action$="/account/password"]');
  check((await text(b1, '#account-new-password-error')) === 'The new password must be at least 12 characters.', '11 characters rejected: "The new password must be at least 12 characters."');
  check((await activeId(b1)) === 'account-new-password', '11 characters: focus moves to the New password field');
  check((await passwordIs(EMAIL1, PW11)) === false && (await passwordIs(EMAIL1, PW0)) === true, '11 characters: nothing changed');
  await scrollToAccount(b1);
  await shot(b1, 'error-short-password-1287');

  // Failure 4 and 5: duplicate email (exact and case variant)
  await typeInto(b1, '#account-new-email', TEACHER);
  await typeInto(b1, '#account-email-password', PW0);
  await submitForm(b1, 'form[action$="/account/email"]');
  check((await text(b1, '#account-new-email-error')) === 'That email is already used by another account.', 'duplicate email: "That email is already used by another account."');
  check((await activeId(b1)) === 'account-new-email', 'duplicate email: focus moves to the New email field');
  await scrollToAccount(b1);
  await shot(b1, 'error-duplicate-email-1287');
  await typeInto(b1, '#account-new-email', TEACHER.toUpperCase());
  await typeInto(b1, '#account-email-password', PW0);
  await submitForm(b1, 'form[action$="/account/email"]');
  check((await text(b1, '#account-new-email-error')) === 'That email is already used by another account.', 'duplicate email, UPPER CASE variant, is rejected too');
  check((await emailExists(TEACHER)) === true && (await emailExists(EMAIL1)) === true, 'duplicate email: both accounts unchanged');

  // Rate limit: five failures so far -> the next attempt is blocked, even with the right password
  await typeInto(b1, '#account-new-email', EMAIL2);
  await typeInto(b1, '#account-email-password', PW0);
  const blocked = b1.waitEvent('Page.loadEventFired', 20000);
  await click(b1, 'form[action$="/account/email"] button[type=submit]');
  await blocked;
  await b1.settle(300);
  check((await b1.eval('document.body.innerText')).includes('Too many failed attempts. Wait'), 'after 5 failed attempts in a minute the next one is answered with the 429 message (even with the right password)');
  check((await emailExists(EMAIL1)) === true && (await emailExists(EMAIL2)) === false, 'the blocked attempt changed nothing');
  clearThrottle();

  // Success: change email
  await b1.goto('/roster#account');
  await b1.settle(300);
  await typeInto(b1, '#account-new-email', EMAIL2);
  await typeInto(b1, '#account-email-password', PW0);
  const hashBefore = await scratchHash(EMAIL1);
  await submitForm(b1, 'form[action$="/account/email"]');
  check((await text(b1, '[data-account-flash]')) === `Your email was changed to ${EMAIL2}.`, 'email change: flash "Your email was changed to <new>."');
  check((await b1.eval('location.pathname')) === '/roster' && (await signedInAs(b1)) === EMAIL2, 'email change: still signed in, header shows the new email');
  check((await text(b1, '[data-account-current-email]')) === EMAIL2, 'email change: the read-only email at the top of the group is the new one');
  check((await emailExists(EMAIL2)) === true && (await emailExists(EMAIL1)) === false && (await scratchHash(EMAIL2)) === hashBefore, 'email change: only users.email changed (password hash identical)');
  check(await b1.eval(`document.activeElement.hasAttribute('data-account-flash')`), 'email change: focus lands on the success message');
  check((await stored(b1)) === EMAIL2, 'remembered email (was the OLD email) is replaced by the NEW one in this browser');
  check(await b1.eval(`localStorage.length === 1`), 'localStorage holds nothing else');
  await scrollToAccount(b1);
  await shot(b1, 'success-email-changed-1287');

  // Log out through the menu, revisit login: prefilled with the NEW email
  await openAccountFromMenu(b1);
  await clickNav(b1, '#account-menu .logout-form button');
  check((await b1.eval('location.pathname')) === '/login', 'logged out to /login');
  check((await val(b1, '#email')) === EMAIL2 && (await b1.eval(`document.querySelector('#remember-email').checked`)), 'login page is prefilled with the NEW email and "Remember my email" is ticked');
  // old email no longer signs in (box unticked so this attempt does not rewrite the remembered address)
  path = await scratchLogin(b1, EMAIL1, PW0, { remember: false });
  check(path === '/login' && (await b1.eval('document.body.innerText')).includes('These credentials do not match'), 'the OLD email can no longer sign in');
  check((await stored(b1)) === null, 'ticking nothing removes the remembered email (existing behaviour)');

  // ================================================================ password change with a second session
  console.log('\nBrowser 2: a second session of the same user, then the password change in browser 1');
  const b2 = await newBrowser();
  path = await scratchLogin(b2, EMAIL2, PW0, { remember: false });
  check(path === '/daily', 'browser 2: second login of the same user');
  path = await scratchLogin(b1, EMAIL2, PW0, { remember: true });
  check(path === '/daily' && (await stored(b1)) === EMAIL2, 'browser 1: signs in with the NEW email, remembered again');
  check((await userState(EMAIL2)).sessions === 2, 'two live session rows for the user');
  await b1.goto('/roster#account');
  await b1.settle(300);
  await typeInto(b1, '#account-current-password', PW0);
  await typeInto(b1, '#account-new-password', PW12);
  await typeInto(b1, '#account-confirm-password', PW11);
  await submitForm(b1, 'form[action$="/account/password"]');
  check((await text(b1, '#account-confirm-password-error')) === 'The password confirmation does not match.', 'confirmation mismatch: "The password confirmation does not match."');
  await typeInto(b1, '#account-current-password', PW0);
  await typeInto(b1, '#account-new-password', PW0);
  await typeInto(b1, '#account-confirm-password', PW0);
  await submitForm(b1, 'form[action$="/account/password"]');
  check((await text(b1, '#account-new-password-error')) === 'Choose a new password that is different from the current one.', 'same as current: "Choose a new password that is different from the current one."');
  // 11 rejected, 12 accepted
  await typeInto(b1, '#account-current-password', PW0);
  await typeInto(b1, '#account-new-password', PW11);
  await typeInto(b1, '#account-confirm-password', PW11);
  await submitForm(b1, 'form[action$="/account/password"]');
  check((await passwordIs(EMAIL2, PW11)) === false && (await passwordIs(EMAIL2, PW0)) === true, 'boundary: 11 characters rejected');
  check(PW12.length === 12, 'boundary: the next password has exactly 12 characters');
  const hash0 = await scratchHash(EMAIL2);
  await typeInto(b1, '#account-current-password', PW0);
  await typeInto(b1, '#account-new-password', PW12);
  await typeInto(b1, '#account-confirm-password', PW12);
  await submitForm(b1, 'form[action$="/account/password"]');
  check((await b1.eval('location.pathname')) === '/login', '12 characters accepted: forced back to /login (signed out)');
  check((await text(b1, '.login-status[role=status]')) === 'Your password was changed. Please sign in again.', 'login page shows "Your password was changed. Please sign in again." (role=status)');
  await shot(b1, 'login-after-password-change-1287');
  const st = await userState(EMAIL2);
  check(st.sessions === 0 && st.bcrypt === true && (await scratchHash(EMAIL2)) !== hash0, 'all session rows of the user are gone; the stored value is a new bcrypt hash');
  check((await passwordIs(EMAIL2, PW12)) === true && (await passwordIs(EMAIL2, PW0)) === false, 'database: the new password verifies, the old one does not');
  check((await val(b1, '#email')) === EMAIL2, 'login page keeps the remembered email (still the same address)');
  path = await scratchLogin(b1, EMAIL2, PW0, { remember: true });
  check(path === '/login' && (await b1.eval('document.body.innerText')).includes('These credentials do not match'), 'the OLD password is rejected at /login');
  await b2.goto('/roster');
  check((await b2.eval('location.pathname')) === '/login', 'browser 2 (the other session) is signed out as well');
  path = await scratchLogin(b1, EMAIL2, PW12, { remember: true });
  check(path === '/daily', 'the NEW password signs in');
  const leaked1 = await leaks(b1);
  check(leaked1.length === 0, 'no password in cookies, localStorage, sessionStorage, the URL or the page HTML (browser 1)');

  // ================================================================ browser 3: keyboard only, remember OFF
  console.log('\nBrowser 3: keyboard only, "Remember my email" NOT ticked');
  const b3 = await newBrowser();
  path = await scratchLogin(b3, EMAIL2, PW12, { remember: false });
  check(path === '/daily' && (await b3.eval('localStorage.length')) === 0, 'sign in without remembering: nothing is stored');
  await b3.eval(`document.querySelector('[data-account-toggle]').focus(); true`);
  await b3.press('Enter');
  check(await b3.eval(`document.activeElement === document.querySelector('#account-menu a[data-account-link]')`), 'keyboard: Enter on the avatar opens the menu and focuses "Account settings"');
  const nav = b3.waitEvent('Page.loadEventFired', 20000);
  await b3.press('Enter');
  await nav;
  await b3.send('Page.bringToFront');
  await b3.settle(350);
  check((await b3.eval('location.pathname + location.hash')) === '/roster#account', 'keyboard: Enter on "Account settings" opens /roster#account');
  check(await b3.eval(`document.getElementById('account').open`), 'keyboard: the group is open');
  // Tab order through the group
  await b3.eval(`document.querySelector('#account > summary').focus(); true`);
  const order = [];
  for (let i = 0; i < 11; i++) { await b3.press('Tab'); order.push(await activeId(b3)); }
  const expected = ['account-new-email', 'account-email-password', 'button[toggle:account-email-password]', 'button', 'account-current-password', 'button[toggle:account-current-password]', 'account-new-password', 'button[toggle:account-new-password]', 'account-confirm-password', 'button[toggle:account-confirm-password]', 'button'];
  check(JSON.stringify(order) === JSON.stringify(expected), 'keyboard: Tab order is email, password, toggle, Change email, then the three password fields with their toggles, Change password');
  // Toggles with Space and Enter
  await b3.eval(`document.querySelector('[data-pw-toggle][aria-controls=account-new-password]').focus(); true`);
  await b3.press('Space');
  const afterSpace = await b3.eval(`[document.getElementById('account-new-password').type, document.querySelector('[data-pw-toggle][aria-controls=account-new-password]').getAttribute('aria-pressed'), document.activeElement.getAttribute('aria-controls')]`);
  await b3.press('Enter');
  const afterEnter = await b3.eval(`[document.getElementById('account-new-password').type, document.querySelector('[data-pw-toggle][aria-controls=account-new-password]').getAttribute('aria-pressed')]`);
  check(JSON.stringify(afterSpace) === JSON.stringify(['text', 'true', 'account-new-password']) && JSON.stringify(afterEnter) === JSON.stringify(['password', 'false']), 'keyboard: Space shows the password, Enter hides it again, focus stays on the toggle');
  // Wrong password submitted with Enter: error and focus on the first invalid field
  await b3.eval(`document.getElementById('account-new-email').focus(); true`);
  await b3.typeText(EMAIL3_TYPED);
  await b3.press('Tab');
  await b3.typeText(WRONG);
  let loaded = b3.waitEvent('Page.loadEventFired', 20000);
  await b3.press('Enter');
  await loaded;
  await b3.settle(350);
  check((await text(b3, '#account-email-password-error')) === 'The current password is incorrect.' && (await activeId(b3)) === 'account-email-password', 'keyboard: Enter submits; the error is shown (role=alert) and focus is on the first invalid field');
  check(await b3.eval(`document.getElementById('account-email-password').getAttribute('aria-describedby') === 'account-email-password-error' && document.getElementById('account-email-password-error').getAttribute('role') === 'alert'`), 'keyboard: the error is linked with aria-describedby and announced (role=alert)');
  // Correct password, mixed-case email, Enter
  await b3.eval(`document.getElementById('account-email-password').focus(); true`);
  await b3.typeText(PW12);
  loaded = b3.waitEvent('Page.loadEventFired', 20000);
  await b3.press('Enter');
  await loaded;
  await b3.settle(350);
  const EMAIL3 = EMAIL3_TYPED.toLowerCase();
  check((await text(b3, '[data-account-flash]')) === `Your email was changed to ${EMAIL3}.` && (await emailExists(EMAIL3)) === true, 'keyboard: email change succeeded and the address was saved lower-cased');
  check(await b3.eval(`document.activeElement.hasAttribute('data-account-flash')`), 'keyboard: focus lands on the success message');
  check((await b3.eval('localStorage.length')) === 0, 'remember was off: nothing was stored after the email change');
  check((await leaks(b3)).length === 0, 'no password anywhere in browser 3');
  // Rate-limit window reset, then the password change by keyboard (and a clean sign-in again with it)
  clearThrottle();
  await b3.eval(`document.getElementById('account-current-password').focus(); true`);
  await b3.typeText(PW12);
  await b3.press('Tab'); await b3.press('Tab');
  await b3.typeText(PW0);
  await b3.press('Tab'); await b3.press('Tab');
  await b3.typeText(PW0);
  loaded = b3.waitEvent('Page.loadEventFired', 20000);
  await b3.press('Enter');
  await loaded;
  await b3.settle(350);
  check((await b3.eval('location.pathname')) === '/login' && (await text(b3, '.login-status')) === 'Your password was changed. Please sign in again.', 'keyboard: password change submitted with Enter, forced back to /login with the status message');
  check((await passwordIs(EMAIL3, PW0)) === true && (await userState(EMAIL3)).sessions === 0, 'keyboard: the new password works and every session is gone');
  check((await b3.eval('localStorage.length')) === 0, 'still nothing in localStorage (remember never ticked)');
  await shot(b3, 'login-after-password-change-1287-keyboard');
  for (const w of [768, 375]) {
    await b3.setViewport(w, w === 375 ? 812 : 1024);
    await shot(b3, `login-after-password-change-${w}`);
  }

  // ================================================================ screens at other widths
  console.log('\nScreens');
  const b4 = await newBrowser();
  path = await scratchLogin(b4, EMAIL3, PW0, { remember: false });
  check(path === '/daily', 'sign in for the width screenshots');
  for (const [w, h] of [[768, 1700], [375, 2200]]) {
    await b4.setViewport(w, h);
    await b4.goto('/weekly');
    await b4.goto('/roster#account');
    await b4.settle(400);
    check(await b4.eval(`document.getElementById('account').open && document.documentElement.scrollWidth <= innerWidth + 1`), `${w}px: group open from the hash, no horizontal scroll`);
    await scrollToAccount(b4);
    await shot(b4, `group-open-${w}`);
  }
  await b4.setViewport(768, 900);
  await b4.goto('/daily');
  await openAccountFromMenu(b4);
  await shot(b4, 'avatar-menu-768');
  check((await leaks(b4)).length === 0, 'no password anywhere in browser 4');
} catch (error) {
  console.error('account-check failed: ' + error.message);
  failed++;
} finally {
  for (const b of browsers) await b.close();
  let after = null;
  try {
    if (scratchCreated) { deleteScratch(); }
    after = snapshot();
    check(after !== null && after.users === 1, 'cleanup: the scratch user is deleted (1 user left)');
    check(after !== null && before !== null && after.teacher === before.teacher && after.teacher_updated === before.teacher_updated, `cleanup: teacher@classpulse.test stored hash and updated_at are UNCHANGED (compared, not printed)`);
    check(after !== null && before !== null && after.classes === 3 && after.students === 51 && after.notes === 0 && after.drafts === 0 && after.entries === before.entries, `cleanup: ${after && after.classes} classes, ${after && after.students} students, ${after && after.notes} notes, ${after && after.drafts} drafts, entries unchanged`);
    check((await emailExists(TEACHER)) === true && (await emailExists(EMAIL1)) === false && (await emailExists(EMAIL2)) === false, 'cleanup: only the teacher account remains');
    clearThrottle();
  } catch (error) {
    console.error('cleanup verification failed: ' + error.message);
    failed++;
  }
}
console.log(`\n${passed} passed, ${failed} failed`);
console.log(failed === 0 ? 'ALL CHECKS PASSED' : failed + ' CHECK(S) FAILED');
process.exit(failed === 0 ? 0 : 1);
