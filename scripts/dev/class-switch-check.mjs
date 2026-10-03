#!/usr/bin/env node
// DEV-ONLY (not shipped): proves the class menu with REAL mouse events (CDP Input.dispatchMouseEvent) and real keys in headless Chrome.
//   node scripts/dev/class-switch-check.mjs [--out storage/app/final-v5] [--json data.json]
// For Daily, Weekly, Semester and Roster at 1287 px (dark): a real click over the class name, the chevron, the dot and the pill edges
// opens the menu (and a second click closes it); the popup lists every class with the current one marked (aria-current + check);
// a real click on each other class navigates and the page content changes (header chips, KPIs, student names); Escape closes and
// refocuses the pill; a click outside closes. Also real clicks on the eyebrow (1440 px) and the Semester chip, and screenshots
// of the open popup at 1287 / 1100 / 768 / 375 px. READ-ONLY: it never clicks anything that writes. Prints the recorded values as JSON.
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { Browser, ROOT, parseFlags, sleep } from './lib/cdp.mjs';

const flags = parseFlags(process.argv.slice(2));
const OUT = resolve(ROOT, typeof flags.out === 'string' ? flags.out : 'storage/app/final-v5');
mkdirSync(OUT, { recursive: true });
const SCREENS = { daily: '/daily', weekly: '/weekly', semester: '/semester', roster: '/roster' };
const failures = [];
const check = (ok, message) => { if (!ok) failures.push(message); console.log((ok ? 'ok   ' : 'FAIL ') + message); };

const browser = new Browser();
const record = {};
const rect = (sel) => browser.eval(`(() => { const el = document.querySelector(${JSON.stringify(sel)}); if (!el) return null; const r = el.getBoundingClientRect(); return { x: r.left, y: r.top, w: r.width, h: r.height }; })()`);
const isOpen = (sel) => browser.eval(`document.querySelector(${JSON.stringify(sel)}).open`);
const SUMMARY = '.class-menu-header > summary';
const MENU = '.class-menu-header';

async function view(width, theme, path) {
  await browser.setViewport(width, width > 600 ? 900 : 812);
  await browser.send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-color-scheme', value: theme }] });
  await browser.goto(path);
  await browser.eval(`document.documentElement.dataset.theme = ${JSON.stringify(theme)}; true`);
  await sleep(250);
}
async function navigateByClick(x, y) {
  const loaded = browser.waitEvent('Page.loadEventFired', 15000);
  await browser.mouseClick(x, y);
  await loaded;
  await browser.eval('document.fonts.ready.then(() => true)');
  await sleep(250);
}
const snapshot = () => browser.eval(`(() => {
  const text = (el) => (el ? el.textContent.replace(/\\s+/g, ' ').trim() : '');
  const names = Array.from(document.querySelectorAll('.student-name, .wm-name, .sem-name, .ro-name')).map(text);
  const kpiCards = Array.from(document.querySelectorAll('.ui-kpi')).slice(0, 4).map((k) => { const l = k.querySelector('.ui-kpi-label'); const v = k.querySelector('.ui-kpi-value'); return (l ? l.firstChild.textContent.trim() : '?') + ': ' + text(v); });
  const kpis = kpiCards.length ? kpiCards : [text(document.querySelector('.tracker-stats-banner')).slice(0, 110)];
  const chips = Array.from(document.querySelectorAll('.header-chip')).map(text);
  const current = document.querySelector('.class-menu-header .class-menu-item[aria-current="true"] .class-menu-item-code');
  return { class: new URL(location.href).searchParams.get('class'), current: text(current), url: location.pathname + location.search, chips, studentCount: names.length, firstNames: names.slice(0, 3), kpis };
})()`);

try {
  await browser.launch({ width: 1287, height: 900 });
  await browser.login();
  await browser.enableFocus();
  await view(1287, 'dark', '/daily');
  const classes = await browser.eval(`Array.from(document.querySelectorAll('.class-menu-header .class-menu-item')).map(a => ({ id: Number(new URL(a.href).searchParams.get('class')), name: a.querySelector('.class-menu-item-code').textContent.trim() }))`);
  check(classes.length === 3, 'the menu lists 3 classes: ' + classes.map((c) => c.name).join(', '));
  const startId = classes.find((c) => c.name === 'HLS 3O')?.id ?? classes[0].id;

  for (const [screen, path] of Object.entries(SCREENS)) {
    record[screen] = {};
    await view(1287, 'dark', path + '?class=' + startId);
    // Real clicks over different parts of the pill: every one opens, a second one closes.
    const r = await rect(SUMMARY);
    const spots = {
      'class name': await rect(MENU + ' .class-menu-name'), 'chevron': await rect(MENU + ' .class-menu-caret'), 'dot': await rect(MENU + ' .dot'),
    };
    const points = Object.entries(spots).map(([k, b]) => [k, b.x + b.w / 2, b.y + b.h / 2]);
    points.push(['left edge', r.x + 3, r.y + r.h / 2], ['right edge', r.x + r.w - 3, r.y + r.h / 2], ['top edge', r.x + r.w / 2, r.y + 3], ['bottom edge', r.x + r.w / 2, r.y + r.h - 3]);
    for (const [what, x, y] of points) {
      await browser.mouseClick(x, y);
      check(await isOpen(MENU), `${screen}: real click on the pill ${what} (${Math.round(x)},${Math.round(y)}) opens the menu`);
      await browser.mouseClick(x, y);
      check(!(await isOpen(MENU)), `${screen}: a second real click on the ${what} closes it`);
    }
    // Open: list, current marked, focus, screenshot.
    await browser.mouseClick(spots['class name'].x + 4, spots['class name'].y + 4);
    const state = await browser.eval(`(() => {
      const links = Array.from(document.querySelectorAll('.class-menu-header .class-menu-item'));
      return { n: links.length, current: links.filter((a) => a.getAttribute('aria-current') === 'true').map((a) => a.querySelector('.class-menu-item-code').textContent.trim()), checks: document.querySelectorAll('.class-menu-header .class-menu-check').length,
        expanded: document.querySelector(${JSON.stringify(SUMMARY)}).getAttribute('aria-expanded'), focus: document.activeElement.getAttribute('aria-current'), subs: links.map((a) => a.querySelector('.class-menu-item-sub').textContent.trim()) };
    })()`);
    check(state.n === 3 && state.current.length === 1 && state.current[0] === classes.find((c) => c.id === startId).name && state.checks === 1, `${screen}: popup lists 3 classes, current "${state.current}" marked (aria-current + check icon)`);
    check(state.expanded === 'true' && state.focus === 'true', `${screen}: aria-expanded="true" and focus on the current class link`);
    record[screen].sub = state.subs;
    await browser.screenshot(`${OUT}/${screen}-1287-dark-open.png`);
    // Escape closes and returns focus to the pill.
    await browser.press('Escape');
    check(!(await isOpen(MENU)) && await browser.eval(`document.activeElement === document.querySelector(${JSON.stringify(SUMMARY)})`), `${screen}: Escape closes the menu and refocuses the pill`);
    // Outside click closes.
    await browser.mouseClick(r.x + 4, r.y + 4);
    const mainPoint = await browser.eval(`(() => { const m = document.querySelector('main').getBoundingClientRect(); return { x: m.right - 6, y: 400 }; })()`);
    await browser.mouseClick(mainPoint.x, mainPoint.y);
    check(!(await isOpen(MENU)), `${screen}: a click outside the menu closes it`);

    // Switch through every class by real clicks: start -> next -> next -> back to the start.
    const order = [startId, ...classes.filter((c) => c.id !== startId).map((c) => c.id), startId];
    record[screen][startId] = await snapshot();
    for (let i = 1; i < order.length; i++) {
      await browser.mouseClick(r.x + r.w / 2, r.y + r.h / 2);
      const link = await browser.eval(`(() => { const a = Array.from(document.querySelectorAll('.class-menu-header .class-menu-item')).find((x) => new URL(x.href).searchParams.get('class') === ${JSON.stringify(String(order[i]))}); const b = a.getBoundingClientRect(); return { x: b.left + b.width / 2, y: b.top + b.height / 2 }; })()`);
      await navigateByClick(link.x, link.y);
      const snap = await snapshot();
      const name = classes.find((c) => c.id === order[i]).name;
      check(snap.class === String(order[i]) && snap.current === name, `${screen}: real click on "${name}" navigated to ?class=${order[i]} and the menu marks it current`);
      if (i < order.length - 1) record[screen][order[i]] = snap;
      else check(JSON.stringify(snap.firstNames) === JSON.stringify(record[screen][startId].firstNames) && snap.chips.join() === record[screen][startId].chips.join(), `${screen}: back on the original class the content is the original again`);
      if (screen === 'daily' && i < order.length - 1) await browser.screenshot(`${OUT}/daily-after-${name.replace(/\s+/g, '')}.png`);
    }
    const distinct = new Set(classes.map((c) => JSON.stringify([record[screen][c.id].firstNames, record[screen][c.id].chips, record[screen][c.id].studentCount])));
    check(distinct.size === classes.length, `${screen}: each class shows different content (students, header chips)`);
    if (screen === 'daily') await browser.screenshot(`${OUT}/daily-before-HLS3O.png`);
  }

  // The eyebrow ("Active") only shows from 1400 px: real click there; and the Semester chip (same component) with a real click.
  await view(1440, 'light', '/weekly?class=' + startId);
  const eyebrow = await rect(MENU + ' .pill-eyebrow');
  check(Boolean(eyebrow && eyebrow.w > 0), 'the "Active" eyebrow is visible at 1440 px');
  await browser.mouseClick(eyebrow.x + eyebrow.w / 2, eyebrow.y + eyebrow.h / 2);
  check(await isOpen(MENU), 'weekly 1440: a real click on the "Active" eyebrow opens the menu');
  await browser.screenshot(`${OUT}/weekly-1440-light-open-eyebrow.png`);
  await view(1287, 'dark', '/semester?class=' + startId);
  const chip = await rect('.class-menu-chip > summary');
  for (const [what, fx, fy] of [['eyebrow area', 0.2, 0.2], ['name', 0.4, 0.75], ['chevron', 0.95, 0.5], ['bottom edge', 0.5, 0.95]]) {
    await browser.mouseClick(chip.x + chip.w * fx, chip.y + chip.h * fy);
    check(await isOpen('.class-menu-chip'), `semester chip: a real click on the ${what} opens the menu`);
    await browser.mouseClick(chip.x + chip.w * fx, chip.y + chip.h * fy);
  }
  await browser.mouseClick(chip.x + chip.w / 2, chip.y + chip.h / 2);
  await browser.screenshot(`${OUT}/semester-chip-1287-dark-open.png`);
  const chipLinks = await browser.eval(`Array.from(document.querySelectorAll('.class-menu-chip .class-menu-item')).map((a) => { const b = a.getBoundingClientRect(); return { href: a.href, x: b.left + b.width / 2, y: b.top + b.height / 2 }; })`);
  const other = chipLinks.find((l) => !l.href.endsWith('class=' + startId));
  await navigateByClick(other.x, other.y);
  check(await browser.eval(`new URL(location.href).searchParams.get('class') !== ${JSON.stringify(String(startId))} && document.querySelector('.class-menu-chip .class-menu-item[aria-current="true"]') !== null`), 'semester chip: a real click on another class switches the class');
  await view(375, 'light', '/semester?class=' + startId);
  const chip375 = await rect('.class-menu-chip > summary');
  await browser.mouseClick(chip375.x + chip375.w / 2, chip375.y + chip375.h / 2);
  await browser.screenshot(`${OUT}/semester-chip-375-light-open.png`);

  // Responsive screenshots with the popup open (Weekly): two-row header (1100 light), 768 dark, 375 light, 320 dark.
  for (const [w, theme] of [[1100, 'light'], [768, 'dark'], [375, 'light'], [320, 'dark']]) {
    await view(w, theme, '/weekly?class=' + startId);
    const b = await rect(SUMMARY);
    await browser.mouseClick(b.x + b.w / 2, b.y + b.h / 2);
    check(await isOpen(MENU), `weekly ${w} ${theme}: a real click on the pill opens the menu`);
    const fits = await browser.eval(`(() => { const p = document.querySelector('.class-menu-header .class-menu-panel').getBoundingClientRect(); return p.left >= 0 && p.right <= document.documentElement.clientWidth && document.documentElement.scrollWidth <= document.documentElement.clientWidth; })()`);
    check(fits, `weekly ${w}: the popup is inside the viewport and the page does not scroll sideways`);
    await browser.screenshot(`${OUT}/weekly-${w}-${theme}-open.png`);
  }
} finally {
  await browser.close();
}
const total = failures.length;
if (typeof flags.json === 'string') writeFileSync(resolve(ROOT, flags.json), JSON.stringify(record, null, 1) + '\n');
console.log('\nRecorded values:\n' + JSON.stringify(record, null, 1));
console.log(total ? `\n${total} check(s) failed` : '\nall checks passed');
process.exit(total ? 1 : 0);
