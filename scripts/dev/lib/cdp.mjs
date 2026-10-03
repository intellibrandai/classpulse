// DEV-ONLY helpers shared by scripts/dev/*.mjs: launch the host's Google Chrome headless, talk to it over the
// Chrome DevTools Protocol (global WebSocket + fetch, no npm packages) and log into the LOCAL app.
// Never shipped (scripts/ is excluded from the release). Never prints the login password.
import { spawn } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, existsSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

export const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');
export const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
export const BASE_URL = process.env.CLASSPULSE_URL || 'http://localhost:8090';
export const LOGIN_EMAIL = 'teacher@classpulse.test';

export const sleep = (ms) => new Promise((done) => setTimeout(done, ms));

export function parseFlags(argv) {
  const flags = {};
  for (let i = 0; i < argv.length; i++) {
    if (!argv[i].startsWith('--')) continue;
    const [key, inline] = argv[i].slice(2).split('=');
    flags[key] = inline !== undefined ? inline : (argv[i + 1] && !argv[i + 1].startsWith('--') ? argv[++i] : true);
  }
  return flags;
}

function readPassword() {
  const file = join(ROOT, 'storage/app/local-test-login.txt');
  const match = /^\s*Password:\s*(.+?)\s*$/m.exec(readFileSync(file, 'utf8'));
  if (!match) throw new Error('No "Password:" line in storage/app/local-test-login.txt');
  return match[1];
}

export class Browser {
  constructor() {
    this.proc = null;
    this.dir = null;
    this.ws = null;
    this.nextId = 1;
    this.pending = new Map();
    this.listeners = [];
  }

  async launch({ width = 1287, height = 900 } = {}) {
    if (!existsSync(CHROME)) throw new Error('Google Chrome not found at ' + CHROME);
    this.dir = mkdtempSync(join(tmpdir(), 'classpulse-chrome-'));
    this.proc = spawn(CHROME, [
      '--headless=new', '--remote-debugging-port=0', '--user-data-dir=' + this.dir,
      '--no-first-run', '--no-default-browser-check', '--disable-extensions', '--hide-scrollbars',
      '--use-mock-keychain', '--password-store=basic', '--force-color-profile=srgb',
      '--window-size=' + width + ',' + height, ...(process.env.CLASSPULSE_CHROME_FLAGS ? process.env.CLASSPULSE_CHROME_FLAGS.split(' ') : []), 'about:blank',
    ], { stdio: 'ignore' });
    const portFile = join(this.dir, 'DevToolsActivePort');
    for (let i = 0; i < 100 && !existsSync(portFile); i++) await sleep(100);
    if (!existsSync(portFile)) throw new Error('Chrome did not open a debugging port');
    const port = readFileSync(portFile, 'utf8').split('\n')[0];
    let target = null;
    for (let i = 0; i < 50 && !target; i++) {
      try {
        const list = await (await fetch('http://127.0.0.1:' + port + '/json/list')).json();
        target = list.find((t) => t.type === 'page');
      } catch { /* not ready */ }
      if (!target) await sleep(100);
    }
    if (!target) throw new Error('No page target');
    this.ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((ok, fail) => { this.ws.onopen = ok; this.ws.onerror = () => fail(new Error('CDP socket failed')); });
    this.ws.onmessage = (event) => {
      const msg = JSON.parse(event.data);
      if (msg.id && this.pending.has(msg.id)) {
        const { ok, fail } = this.pending.get(msg.id);
        this.pending.delete(msg.id);
        if (msg.error) fail(new Error(msg.error.message)); else ok(msg.result);
      } else if (msg.method) {
        this.listeners.forEach((fn) => fn(msg));
      }
    };
    await this.send('Page.enable');
    await this.send('Runtime.enable');
    await this.setViewport(width, height);
  }

  send(method, params = {}, timeout = 30000) {
    const id = this.nextId++;
    return new Promise((ok, fail) => {
      const timer = setTimeout(() => { this.pending.delete(id); fail(new Error('CDP ' + method + ' timed out after ' + timeout + ' ms')); }, timeout);
      this.pending.set(id, { ok: (v) => { clearTimeout(timer); ok(v); }, fail: (e) => { clearTimeout(timer); fail(e); } });
      this.ws.send(JSON.stringify({ id, method, params }));
    });
  }

  setViewport(width, height, scale = 1) {
    return this.send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: scale, mobile: false });
  }

  waitEvent(method, timeout = 30000) {
    return new Promise((ok, fail) => {
      const timer = setTimeout(() => fail(new Error('Timeout waiting for ' + method)), timeout);
      const fn = (msg) => {
        if (msg.method === method) {
          clearTimeout(timer);
          this.listeners = this.listeners.filter((l) => l !== fn);
          ok(msg.params);
        }
      };
      this.listeners.push(fn);
    });
  }

  async eval(expression) {
    const result = await this.send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
    if (result.exceptionDetails) throw new Error('Page script failed: ' + (result.exceptionDetails.exception?.description || result.exceptionDetails.text));
    return result.result.value;
  }

  async goto(path) {
    const loaded = this.waitEvent('Page.loadEventFired');
    await this.send('Page.navigate', { url: path.startsWith('http') ? path : BASE_URL + path });
    await loaded;
    await this.eval('document.fonts ? document.fonts.ready.then(() => true) : true');
  }

  async waitFor(expression, timeout = 15000, label = expression) {
    const end = Date.now() + timeout;
    while (Date.now() < end) {
      if (await this.eval('Boolean(' + expression + ')')) return;
      await sleep(100);
    }
    throw new Error('Timed out waiting for: ' + label);
  }

  // Waits until no fetch has started for a moment (the pages paint from lazy JSON).
  async settle(ms = 400) {
    await sleep(ms);
    await this.eval('document.fonts.ready.then(() => true)');
  }

  async click(selector) {
    await this.eval('(() => { const el = document.querySelector(' + JSON.stringify(selector) + '); if (!el) throw new Error("no element " + ' + JSON.stringify(selector) + '); el.click(); return true; })()');
  }

  async login() {
    await this.goto('/login');
    const password = readPassword();
    const ok = await this.eval(`(() => {
      const form = document.querySelector('form[action$="/login"]') || document.querySelector('form');
      form.querySelector('[name=email]').value = ${JSON.stringify(LOGIN_EMAIL)};
      form.querySelector('[name=password]').value = ${JSON.stringify(password)};
      return true;
    })()`);
    if (!ok) throw new Error('Login form not found');
    const loaded = this.waitEvent('Page.loadEventFired');
    await this.eval(`document.querySelector('form[action$="/login"], form').requestSubmit()`);
    await loaded;
    const path = await this.eval('location.pathname');
    if (path === '/login') throw new Error('Login failed (is the local account set up?)');
  }

  // Fills the login form (password read from the local file, never printed) and drives it with REAL keys:
  // Tab from the password field to the "Remember my email" box (Space ticks it), Tab to Sign in, Enter.
  // wrongPassword submits an invalid password instead. Returns the path the browser lands on.
  async loginWithKeys({ remember = false, wrongPassword = false } = {}) {
    const password = wrongPassword ? 'definitely-not-the-password' : readPassword();
    await this.eval(`(() => {
      const email = document.querySelector('#email');
      if (!email.value) email.value = ${JSON.stringify(LOGIN_EMAIL)};
      const pw = document.querySelector('#password');
      pw.value = ${JSON.stringify(password)};
      pw.focus();
      return true;
    })()`);
    await this.press('Tab');
    const onBox = await this.eval(`document.activeElement && document.activeElement.id === 'remember-email'`);
    if (!onBox) throw new Error('Tab from the password field did not reach the Remember my email checkbox');
    const isChecked = await this.eval(`document.activeElement.checked`);
    if (remember !== isChecked) await this.press('Space');
    await this.press('Tab');
    const loaded = this.waitEvent('Page.loadEventFired');
    await this.press('Enter');
    await loaded;
    await this.eval('document.fonts ? document.fonts.ready.then(() => true) : true');
    return this.eval('location.pathname');
  }

  // True when the local password (or its URL-encoded form) appears in localStorage, sessionStorage or any cookie value.
  async passwordStored() {
    const password = readPassword();
    const { cookies } = await this.send('Network.getAllCookies');
    const inCookies = cookies.some((c) => c.value.includes(password) || decodeURIComponent(c.value).includes(password));
    const inStorage = await this.eval(`(() => {
      const pw = ${JSON.stringify(password)};
      const dump = [];
      for (const store of [localStorage, sessionStorage]) for (let i = 0; i < store.length; i++) dump.push(store.key(i) + '=' + store.getItem(store.key(i)));
      return dump.some((entry) => entry.includes(pw));
    })()`);
    return inCookies || inStorage;
  }

  async cookieNames() {
    const { cookies } = await this.send('Network.getAllCookies');
    return cookies.map((c) => ({ name: c.name, httpOnly: c.httpOnly, session: c.session, expires: c.expires }));
  }

  async theme(mode) {
    await this.send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-color-scheme', value: mode }] });
    await this.eval(`document.documentElement.dataset.theme = ${JSON.stringify(mode)}; true`);
    await sleep(150);
  }

  // ---- REAL keyboard input (Input.dispatchKeyEvent), used by scripts/dev/keyboard-audit.mjs ----
  // Key table: name -> [key, code, windowsVirtualKeyCode, text]
  static KEYS = {
    Tab: ['Tab', 'Tab', 9, ''], Enter: ['Enter', 'Enter', 13, '\r'], Space: [' ', 'Space', 32, ' '],
    Escape: ['Escape', 'Escape', 27, ''], ArrowDown: ['ArrowDown', 'ArrowDown', 40, ''], ArrowUp: ['ArrowUp', 'ArrowUp', 38, ''],
    ArrowLeft: ['ArrowLeft', 'ArrowLeft', 37, ''], ArrowRight: ['ArrowRight', 'ArrowRight', 39, ''],
    Home: ['Home', 'Home', 36, ''], End: ['End', 'End', 35, ''], Backspace: ['Backspace', 'Backspace', 8, ''],
    Slash: ['/', 'Slash', 191, '/'],
  };

  async press(name, { shift = false, ctrl = false, meta = false, commands } = {}) {
    const [key, code, vk, text] = Browser.KEYS[name] || [name, name, 0, name.length === 1 ? name : ''];
    const modifiers = (shift ? 8 : 0) | (ctrl ? 2 : 0) | (meta ? 4 : 0);
    await this.send('Input.dispatchKeyEvent', { type: text ? 'keyDown' : 'rawKeyDown', key, code, windowsVirtualKeyCode: vk, text, unmodifiedText: text, modifiers, ...(commands ? { commands } : {}) });
    await this.send('Input.dispatchKeyEvent', { type: 'keyUp', key, code, windowsVirtualKeyCode: vk, modifiers });
    await sleep(45);
  }

  // Types printable text with real key events (goes through keydown/keypress/input like a person typing).
  async typeText(text) {
    for (const ch of text) {
      await this.send('Input.dispatchKeyEvent', { type: 'keyDown', key: ch, text: ch, unmodifiedText: ch });
      await this.send('Input.dispatchKeyEvent', { type: 'keyUp', key: ch });
    }
    await sleep(40);
  }

  // REAL mouse click at viewport coordinates (Input.dispatchMouseEvent: move, press, release), like a person with a mouse.
  async mouseClick(x, y) {
    await this.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x, y });
    await this.send('Input.dispatchMouseEvent', { type: 'mousePressed', x, y, button: 'left', buttons: 1, clickCount: 1 });
    await this.send('Input.dispatchMouseEvent', { type: 'mouseReleased', x, y, button: 'left', buttons: 0, clickCount: 1 });
    await sleep(120);
  }

  // PNG screenshot of the viewport (or of a clip rectangle) written to a file.
  async screenshot(file, clip) {
    const { data } = await this.send('Page.captureScreenshot', { format: 'png', ...(clip ? { clip: { ...clip, scale: 1 } } : {}) });
    writeFileSync(file, Buffer.from(data, 'base64'));
  }

  async enableFocus() {
    await this.send('Emulation.setFocusEmulationEnabled', { enabled: true });
  }

  async close() {
    try { this.ws?.close(); } catch { /* ignore */ }
    if (this.proc) {
      this.proc.kill('SIGKILL');
      await sleep(300);
    }
    if (this.dir) rmSync(this.dir, { recursive: true, force: true });
  }
}

// JSON helper that runs inside the page (session cookie + CSRF), for scripts that must create scratch data.
export function pageFetchSource(method, url, body) {
  return `(async () => {
    const token = document.querySelector('meta[name=csrf-token]').content;
    const res = await fetch(${JSON.stringify(url)}, {
      method: ${JSON.stringify(method)},
      headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
      ${body === undefined ? '' : 'body: JSON.stringify(' + JSON.stringify(body) + '),'}
    });
    let payload = null;
    try { payload = await res.json(); } catch (e) { payload = null; }
    return { status: res.status, payload };
  })()`;
}
