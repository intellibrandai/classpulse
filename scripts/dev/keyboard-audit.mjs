// DEV-ONLY keyboard audit: drives the real app in headless Chrome with REAL key events (CDP Input.dispatchKeyEvent),
// sweeping Tab order and focus indicators on every screen and running the keyboard flows of every dialog/menu/sheet.
// Node 24 built-ins only. Writes nothing to the repo unless --report is given. See docs/dev-tools.md.
// Flows that must write use the TEMPORARY scratch class "ZZ Demo (temp)", deleted at the end through the roster page.
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { Browser, ROOT, parseFlags, sleep } from './lib/cdp.mjs';
import { KB_PAGE_SOURCE } from './lib/kb-page.mjs';
import { DEFAULT_CLASS_NAME, deleteScratchClass, findClassId, seedScratchClass } from './lib/scratch.mjs';
import { FLOWS } from './lib/kb-flows.mjs';

const flags = parseFlags(process.argv.slice(2));
const REAL_CLASS = flags['class-name'] || 'HLS 3O';
const SCRATCH = DEFAULT_CLASS_NAME;
const WIDTHS = flags.width ? [Number(flags.width)] : [1287, 375];
const THEMES = flags.theme ? [flags.theme] : ['dark', 'light'];
const ONLY = flags.only ? String(flags.only).split(',') : null;
const SKIP_SWEEP = Boolean(flags['no-sweep']);
const SKIP_FLOWS = Boolean(flags['no-flows']);
const KEEP = Boolean(flags.keep);
const log = (...a) => console.log(...a);

const browser = new Browser();
const counts = {};
let total = 0;
const results = { sweeps: [], flows: [] };

async function prepare(theme, width, path) {
  await browser.setViewport(width, width > 600 ? 900 : 812);
  await browser.send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-color-scheme', value: theme }, { name: 'prefers-reduced-motion', value: 'reduce' }] });
  await browser.goto(path);
  await browser.eval(`document.documentElement.dataset.theme = ${JSON.stringify(theme)}; true`);
  await browser.send('Page.bringToFront'); // the page must report document.hasFocus() or dialogs cannot restore focus
  await browser.settle(350);
  await browser.eval(KB_PAGE_SOURCE);
}

async function stopInfo() {
  return browser.eval(`(() => {
    const kb = window.__kb; const el = document.activeElement;
    const d = kb.describe(el);
    let ev = null;
    if (el !== document.body) {
      const focused = kb.snapChain(el);
      const u = kb.base.get(el);
      ev = kb.evaluate(el, focused, u || focused);
      if (!u) ev.detail = 'no baseline (dynamic element)';
    }
    // Obscured focus: something else (a sticky header/footer, an overlay) sits on top of the focused control.
    let occluded = '';
    if (el !== document.body) {
      const r0 = el.getBoundingClientRect();
      // Only the part inside the viewport counts; very large regions (scrollers) are tested at the visible centre only.
      const r = { left: Math.max(r0.left, 0), right: Math.min(r0.right, innerWidth), top: Math.max(r0.top, 0), bottom: Math.min(r0.bottom, innerHeight) };
      r.width = r.right - r.left; r.height = r.bottom - r.top;
      if (r.width > 2 && r.height > 2) {
        const bad = [];
        const big = r0.height > 200 || r0.width > 600;
        const pts = big ? [[r.left + r.width / 2, r.top + r.height / 2]] : [[r.left + r.width / 2, r.top + r.height / 2], [r.left + 3, r.top + 3], [r.right - 3, r.bottom - 3]];
        for (const [x, y] of pts) {
          const t = document.elementFromPoint(x, y);
          if (t && !(el.contains(t) || t.contains(el))) bad.push((t.tagName.toLowerCase() + '.' + (t.className || '').toString().split(' ')[0]));
        }
        occluded = Array.from(new Set(bad)).join(', ');
      }
    }
    return { d, ev, occluded, sameAsBody: el === document.body };
  })()`);
}

// Tab through the whole page; returns the stops and the measured indicators.
async function sweep(label) {
  const stops = [];
  const indicators = new Map();
  const obscured = [];
  const note = (info) => { if (info.occluded) obscured.push(`${info.d.tag} "${info.d.name}" (${info.d.sel}) is covered by ${info.occluded}`); if (info.ev) indicators.set(info.d.sel + '|' + info.d.name + '|' + info.d.x + ',' + info.d.y, { desc: info.d, ev: info.ev }); };
  let firstKey = null;
  let repeats = 0;
  let last = null;
  let trapped = false;
  await browser.eval('window.__kb.baseline(); window.scrollTo(0, 0); true');
  const initial = await stopInfo(); // pages with autofocus (sign-in) start on a control
  const initialAutofocus = !initial.sameAsBody;
  if (!initial.sameAsBody) { note(initial); firstKey = initial.d.sel + '|' + initial.d.name + '|' + initial.d.x + ',' + initial.d.y; last = firstKey; stops.push(initial.d); }
  for (let i = 0; i < 420; i++) {
    await browser.press('Tab');
    const info = await stopInfo();
    note(info);
    if (info.sameAsBody && i > 0) { break; } // wrapped around to the browser/document
    const key = info.d.sel + '|' + info.d.name + '|' + info.d.x + ',' + info.d.y;
    if (firstKey === null) { firstKey = key; } else if (key === firstKey) { break; }
    if (key === last) { repeats++; if (repeats >= 2) { trapped = true; break; } } else { repeats = 0; }
    last = key;
    stops.push(info.d);
  }
  const offenders = [];
  for (const p of indicators.values()) {
    const bad = p.desc.tag !== 'body' && (p.ev.ratio < 3 || p.ev.kind === 'none');
    if (bad) offenders.push(`${p.desc.tag} "${p.desc.name}" (${p.desc.sel}) -> ${p.ev.kind} ${p.ev.ratio}`);
    else if (p.ev.clip) offenders.push(`${p.desc.tag} "${p.desc.name}" (${p.desc.sel}) -> ${p.ev.kind} ${p.ev.ratio} but the ring is clipped at ${p.ev.clip}`);
  }
  const invisible = stops.filter((s) => !s.visible).map((s) => `${s.tag} "${s.name}" (${s.sel}) is focusable but has no visible box`);
  const backwards = [];
  for (let i = 1; i < stops.length; i++) {
    const a = stops[i - 1], b = stops[i];
    if (a.inDialog || b.inDialog) continue;
    if (initialAutofocus) continue; // the sign-in page starts on an autofocused field, so its cycle wraps
    // Reading order: next stop is to the right, or lower down. A card grid moves right then wraps to the next row.
    if ((b.y < a.y - 40 && b.x <= a.x + 10) || (Math.abs(b.y - a.y) <= 40 && b.x < a.x - 40 && b.y <= a.y)) {
      backwards.push(`"${a.name}" (${a.x},${a.y}) -> "${b.name}" (${b.x},${b.y})`);
    }
  }
  const positive = stops.filter((s) => s.tabindex && Number(s.tabindex) > 0).map((s) => s.sel);
  for (const o of Array.from(new Set(obscured))) offenders.push('OBSCURED ' + o);
  return { label, stops: stops.length, trapped, offenders, invisible, backwards, positive, first: stops[0] };
}

async function skipLinkCheck() {
  await browser.eval(`document.activeElement && document.activeElement.blur(); window.scrollTo(0, 0); true`);
  await browser.eval(`location.hash = ''; true`);
  await browser.press('Tab');
  const first = await browser.eval(`(() => { const a = document.activeElement; return { cls: a.className, text: a.textContent.trim(), href: a.getAttribute('href') }; })()`);
  const shown = await browser.eval(`(() => { const r = document.activeElement.getBoundingClientRect(); return r.top >= 0 && r.bottom > 0 && r.width > 10; })()`);
  if (!/skip-link/.test(first.cls)) return { ok: false, detail: 'first Tab stop is "' + first.text + '", not the skip link' };
  await browser.press('Enter');
  await sleep(120);
  const hash = await browser.eval('location.hash');
  await browser.press('Tab');
  const inMain = await browser.eval(`Boolean(document.activeElement.closest('main'))`);
  return { ok: shown && hash === '#main' && inMain, detail: `skip link visible on focus: ${shown}; Enter -> ${hash}; next Tab stays inside main: ${inMain}` };
}

const SCREENS = [
  { name: 'Login', path: '/login', auth: false },
  { name: 'Daily', path: '/daily?class=:real', auth: true },
  { name: 'Weekly', path: '/weekly?class=:real', auth: true },
  { name: 'Semester', path: '/semester?class=:real', auth: true },
  { name: 'Roster', path: '/roster?class=:real', auth: true },
  { name: 'Import upload', path: '/classes/:real/import', auth: true },
  { name: 'Import preview', path: null, auth: true, preview: true },
  { name: 'Student history', path: '/students/:student', auth: true },
];

async function runSweeps(realId, scratchId, publicOnly) {
  let studentId = null;
  if (!publicOnly) {
    await browser.goto('/daily?class=' + realId);
    studentId = await browser.eval(`Number(document.querySelector('article.student-card').dataset.studentId)`);
  }
  for (const theme of THEMES) {
    for (const width of WIDTHS) {
      for (const screen of SCREENS) {
        if (ONLY && !ONLY.includes(screen.name)) continue;
        if (screen.auth === publicOnly) continue;
        try {
          let path = screen.path;
          if (screen.preview) {
            // Preview a pasted list (read-only: nothing is imported) using real key presses in the form.
            await prepare(theme, width, '/classes/' + scratchId + '/import');
            await browser.eval(`document.querySelector('#import-pasted').focus(); true`);
            await browser.typeText('Test One\nTest Two');
            await browser.eval(`document.querySelector('.imp-panel form button[type=submit]').focus(); true`);
            const loaded = browser.waitEvent('Page.loadEventFired');
            await browser.press('Enter');
            await loaded;
            await browser.send('Page.bringToFront');
            await browser.settle(300);
            await browser.eval(KB_PAGE_SOURCE);
          } else {
            path = path.replace(':real', realId).replace(':student', studentId);
            await prepare(theme, width, path);
          }
          const skip = screen.auth ? await skipLinkCheck() : { ok: true, detail: 'no skip link on the sign-in page (single form)' };
          if (screen.auth && !screen.preview) await prepare(theme, width, path);
          const res = await sweep(`${screen.name} ${theme} ${width}`);
          res.screen = screen.name; res.theme = theme; res.width = width; res.skip = skip;
          results.sweeps.push(res);
          log(`[sweep] ${res.label}: ${res.stops} stops, offenders ${res.offenders.length}, invisible ${res.invisible.length}, order ${res.backwards.length}, trapped ${res.trapped}, skip ${res.skip.ok}`);
        } catch (err) {
          results.sweeps.push({ label: `${screen.name} ${theme} ${width}`, screen: screen.name, theme, width, error: String(err.message || err) });
          log(`[sweep] ${screen.name} ${theme} ${width}: ERROR ${err.message}`);
        }
      }
    }
  }
}

async function runFlows(ctx) {
  for (const theme of THEMES) {
    for (const width of WIDTHS) {
      for (const flow of FLOWS) {
        if (ONLY && !ONLY.includes(flow.id) && !ONLY.includes(flow.screen)) continue;
        const res = { id: flow.id, screen: flow.screen, title: flow.title, theme, width, steps: [] };
        try {
          if (flags.verbose) log('  ... ' + flow.id + ' start');
          await prepare(theme, width, flow.path(ctx));
          if (flags.verbose) log('  ... prepared');
          const t = {
            browser, sleep, steps: res.steps,
            check(ok, what) { res.steps.push({ ok: Boolean(ok), what }); },
            info(what) { res.steps.push({ ok: true, what, info: true }); },
            sleep,
            active: () => browser.eval(`(() => { const a = document.activeElement; return a ? (a.tagName.toLowerCase() + (a.id ? '#' + a.id : '') + ' "' + (a.getAttribute('aria-label') || a.textContent || a.value || '').replace(/\\s+/g, ' ').trim().slice(0, 40) + '"') : 'none'; })()`),
            ev: (src) => browser.eval(src),
            typeText: (x) => browser.typeText(x),
            key: (k, o) => browser.press(k, o),
            type: (s) => browser.typeText(s),
            ctx, theme, width,
          };
          await Promise.race([flow.run(t), new Promise((_, no) => setTimeout(() => no(new Error('flow timed out after 90 s; last completed step: ' + JSON.stringify(res.steps.length ? res.steps[res.steps.length - 1].what : 'none'))), 90000))]);
        } catch (err) {
          res.steps.push({ ok: false, what: 'flow aborted: ' + String(err.message || err) });
          if (flags['debug-hang']) { await browser.send('Debugger.pause', {}, 5000).catch(() => {}); await sleep(1500); }
        }
        res.ok = res.steps.every((s) => s.ok);
        results.flows.push(res);
        log(`[flow] ${flow.id} ${theme} ${width}: ${res.ok ? 'PASS' : 'FAIL'}` + (res.ok ? '' : ' -> ' + res.steps.filter((s) => !s.ok).map((s) => s.what).join(' | ')));
      }
    }
  }
}

try {
  await browser.launch({ width: 1287, height: 900 });
  // No Emulation.setFocusEmulationEnabled here: with it, headless Chrome wedges on keys sent to open modal dialogs.
  // A beforeunload/alert dialog would block the headless page: accept it and remember it happened.
  browser.listeners.push((msg) => {
    counts[msg.method] = (counts[msg.method] || 0) + 1;
    if (flags.verbose && ++total % 3000 === 0) log('  events: ' + JSON.stringify(Object.entries(counts).sort((a, b) => b[1] - a[1]).slice(0, 4)) + ' last=' + JSON.stringify(msg).slice(0, 300));
    if (msg.method === 'Page.javascriptDialogOpening') {
      log('  (page dialog: ' + msg.params.type + ' "' + String(msg.params.message).slice(0, 60) + '")');
      browser.send('Page.handleJavaScriptDialog', { accept: true }).catch(() => {});
    }
  });
  if (flags['debug-hang']) {
    await browser.send('Debugger.enable');
    browser.listeners.push((msg) => {
      if (msg.method === 'Debugger.paused') {
        log('  PAUSED: ' + msg.params.callFrames.slice(0, 6).map((f) => `${f.functionName || '(anon)'} ${f.url.split('/').pop()}:${f.location.lineNumber + 1}`).join(' <- '));
        browser.send('Debugger.resume').catch(() => {});
      }
    });
  }
  if (!SKIP_SWEEP) await runSweeps(null, null, true);
  await browser.login();
  // Headless Chrome renders on the CPU and sometimes wedges on the stacked backdrop-filter blurs of the glass UI while
  // dialogs open and close. A constructed stylesheet (allowed by the CSP) switches only the blur off for the audit.
  if (!flags['keep-blur']) {
    await browser.send('Page.addScriptToEvaluateOnNewDocument', { source: `(() => { try { const sheet = new CSSStyleSheet(); sheet.replaceSync('*, *::before, *::after, ::backdrop { backdrop-filter: none !important; -webkit-backdrop-filter: none !important; }'); document.adoptedStyleSheets = [sheet]; } catch (e) { /* ignore */ } })()` });
  }
  const realId = await findClassId(browser, REAL_CLASS);
  if (!realId) throw new Error('Class not found: ' + REAL_CLASS);
  let scratchId = await findClassId(browser, SCRATCH);
  let createdHere = false;
  if (!scratchId) {
    scratchId = (await seedScratchClass(browser, SCRATCH, log)).classId;
    createdHere = true;
  }
  try {
    if (!SKIP_SWEEP) await runSweeps(realId, scratchId, false);
    if (!SKIP_FLOWS) await runFlows({ realId, scratchId, fullId: await findClassId(browser, 'ZZ Full (temp)') });
  } finally {
    if (createdHere && !KEEP) {
      const left = await deleteScratchClass(browser, scratchId, SCRATCH, log);
      log('classes after cleanup: ' + left.length);
    }
  }
  if (flags.json) writeFileSync(String(flags.json), JSON.stringify(results, null, 2));
} finally {
  await browser.close();
}
const badSweeps = results.sweeps.filter((s) => s.error || s.offenders.length || s.invisible.length || s.trapped || !s.skip.ok);
const badFlows = results.flows.filter((f) => !f.ok);
log(`\nSummary: ${results.sweeps.length} sweeps (${badSweeps.length} with findings), ${results.flows.length} flow runs (${badFlows.length} failing)`);
for (const s of results.sweeps) {
  for (const o of s.offenders || []) log(`  OFFENDER ${s.label}: ${o}`);
  for (const o of s.invisible || []) log(`  INVISIBLE ${s.label}: ${o}`);
  for (const o of s.backwards || []) log(`  ORDER? ${s.label}: ${o}`);
  if (s.trapped) log(`  TRAPPED ${s.label}`);
  if (s.skip && !s.skip.ok) log(`  SKIP ${s.label}: ${s.skip.detail}`);
}
process.exit(badSweeps.length || badFlows.length ? 1 : 0);
