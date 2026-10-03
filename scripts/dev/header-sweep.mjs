#!/usr/bin/env node
// DEV-ONLY (not shipped): measures the app header at every width with real layout in headless Google Chrome.
//   node scripts/dev/header-sweep.mjs [--pages daily,weekly,semester,roster] [--themes light,dark] [--zoom 1,1.25,1.5]
//                                      [--widths 320,360,...] [--step 4] [--long-name] [--report docs/header-sweep.md] [--json out.json] [--verbose] [--quick]
// --step N measures every N px from 320 to 2200 instead (dense break-point hunt); --long-name swaps the selected class option's text
// for a 60-character name in the DOM only (nothing is saved) to prove the selector shrinks instead of overlapping.
// Logs in as the local test account (password read from storage/app/local-test-login.txt, never printed) and only READS.
// For Daily, Weekly, Semester and Roster, in light and dark, at 22 named widths plus every 20 px from 1000 to 1500,
// and at browser zoom 125% / 150% (emulated: the CSS viewport is W / zoom, deviceScaleFactor = zoom), it checks:
//   1. no two header controls (brand, class menu, "+", four tabs, chips, theme, Undo, Today, avatar) overlap;
//   2. every control is visible (chips and Today may collapse, nothing else), inside the viewport and inside every clipping ancestor;
//   3. documentElement.scrollWidth <= clientWidth (no horizontal PAGE scroll);
//   4. the header is at most 2 rows (<= 120 px);
//   5. no visible tab label (or brand text) is truncated (scrollWidth <= clientWidth);
//   6. Tab order equals visual order (focusable header controls: DOM order vs (row, x) order);
//   7. click targets: document.elementFromPoint over a 5x3 grid of the class pill (plus its eyebrow, dot, name and chevron, and on Semester
//      the course chip) always resolves to the <summary> or one of its descendants, never to another element;
//   8. opened menu: the popup is fully inside the viewport, not clipped by any ancestor, above the header / sticky bars / footer
//      (elementFromPoint at its corners and centre resolves to the panel), at most min(360px, 100vw - 24px) wide, causes no horizontal
//      page scroll, and the summary reports aria-expanded="true".
// Exit code 1 on any failure. Node 24 built-ins only.
import { writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { Browser, ROOT, parseFlags, sleep } from './lib/cdp.mjs';

const flags = parseFlags(process.argv.slice(2));
const csv = (value, fallback) => (typeof value === 'string' ? value.split(',').map((v) => v.trim()).filter(Boolean) : fallback);

const PAGES = { daily: '/daily', weekly: '/weekly', semester: '/semester', roster: '/roster' };
const NAMED = [320, 360, 375, 414, 480, 600, 768, 820, 900, 1000, 1024, 1100, 1180, 1280, 1340, 1366, 1440, 1536, 1600, 1680, 1920, 2200];
const WIDTHS = [...new Set([...NAMED, ...Array.from({ length: 26 }, (_, i) => 1000 + i * 20)])].sort((a, b) => a - b);
const pages = csv(flags.pages, Object.keys(PAGES));
const themes = csv(flags.themes, ['light', 'dark']);
const zooms = csv(flags.zoom, ['1', '1.25', '1.5']).map(Number);
const STEP = Number(flags.step) || 0;
const widths = STEP ? Array.from({ length: Math.floor((2200 - 320) / STEP) + 1 }, (_, i) => 320 + i * STEP) : csv(flags.widths, flags.quick ? [320, 375, 768, 1024, 1100, 1280, 1340, 1440, 1920] : WIDTHS.map(String)).map(Number);
const HEIGHT = 900;
const MAX_HEADER = 120;

// Runs inside the page. Returns { problems: [...], info: {...} } for the current viewport.
const MEASURE = String.raw`(() => {
  const nav = document.querySelector('.top-nav');
  const problems = [];
  if (!nav) return { problems: ['no .top-nav header'], info: {} };
  const SELECTORS = [
    ['brand', '.brand-cluster', true], ['class menu', '.class-menu-header', true], ['plus', '.class-add', true],
    ['tab Daily', '.screen-tab-btn:nth-child(1)', true], ['tab Weekly', '.screen-tab-btn:nth-child(2)', true],
    ['tab Semester', '.screen-tab-btn:nth-child(3)', true], ['tab Roster', '.screen-tab-btn:nth-child(4)', true],
    ['chip Enrolled', '.header-chip:nth-child(1)', false], ['chip Present', '.header-chip:nth-child(2)', false], ['chip Absent', '.header-chip:nth-child(3)', false],
    ['theme', '.theme-switch', true], ['undo', '.btn-undo', true], ['today', '.today-block', false], ['avatar', '.avatar-btn', true],
  ];
  const vw = document.documentElement.clientWidth;
  const visible = (el) => {
    if (!el) return false;
    const cs = getComputedStyle(el);
    if (cs.display === 'none' || cs.visibility === 'hidden') return false;
    const r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0;
  };
  const box = (r) => ({ l: +r.left.toFixed(1), r: +r.right.toFixed(1), t: +r.top.toFixed(1), b: +r.bottom.toFixed(1) });
  const items = [];
  for (const [name, selector, required] of SELECTORS) {
    const el = nav.querySelector(selector);
    if (!el) { if (required) problems.push('missing control: ' + name); continue; }
    if (!visible(el)) { if (required) problems.push('hidden control: ' + name); continue; }
    items.push({ name, el, rect: el.getBoundingClientRect() });
  }
  // 1. overlap
  for (let i = 0; i < items.length; i++) {
    for (let j = i + 1; j < items.length; j++) {
      const a = items[i].rect, b = items[j].rect;
      const w = Math.min(a.right, b.right) - Math.max(a.left, b.left);
      const h = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top);
      if (w > 0.5 && h > 0.5) problems.push('overlap: ' + items[i].name + ' x ' + items[j].name + ' (' + w.toFixed(1) + 'x' + h.toFixed(1) + 'px)');
    }
  }
  // 2. inside the viewport, the header and every clipping ancestor
  const navRect = nav.getBoundingClientRect();
  for (const { name, el, rect } of items) {
    if (rect.left < -0.5 || rect.right > vw + 0.5) problems.push('outside viewport: ' + name + ' [' + rect.left.toFixed(1) + ', ' + rect.right.toFixed(1) + '] of ' + vw);
    if (rect.top < navRect.top - 0.5 || rect.bottom > navRect.bottom + 0.5) problems.push('outside header: ' + name);
    for (let a = el.parentElement; a && a !== document.body && a !== document.documentElement; a = a.parentElement) {
      const cs = getComputedStyle(a);
      if (cs.overflowX === 'visible' && cs.overflowY === 'visible') continue;
      const ar = a.getBoundingClientRect();
      const clipX = cs.overflowX !== 'visible' && (rect.left < ar.left - 0.5 || rect.right > ar.right + 0.5);
      const clipY = cs.overflowY !== 'visible' && (rect.top < ar.top - 0.5 || rect.bottom > ar.bottom + 0.5);
      if (clipX || clipY) problems.push('clipped: ' + name + ' by .' + String(a.className).split(' ')[0]);
    }
  }
  // 3. no horizontal page scroll
  const doc = document.documentElement;
  if (doc.scrollWidth > doc.clientWidth) problems.push('page scrolls sideways: scrollWidth ' + doc.scrollWidth + ' > ' + doc.clientWidth);
  if (document.body.scrollWidth > doc.clientWidth + 0.5) problems.push('body wider than the viewport: ' + document.body.scrollWidth + ' > ' + doc.clientWidth);
  // 4. height
  const rows = new Set(items.map((i) => Math.round((i.rect.top + i.rect.bottom) / 2 / 20))).size;
  if (navRect.height > ${MAX_HEADER}) problems.push('header too tall: ' + navRect.height.toFixed(1) + 'px');
  // 5. text not truncated
  const truncated = [];
  for (const tab of nav.querySelectorAll('.screen-tab-btn')) {
    if (!visible(tab)) continue;
    if (tab.scrollWidth > tab.clientWidth + 0.5) truncated.push(tab.getAttribute('aria-label') + ' (tab box)');
    for (const label of tab.querySelectorAll('.tab-full, .tab-short')) {
      if (visible(label) && label.scrollWidth > label.clientWidth + 0.5) truncated.push(label.textContent + ' (label)');
    }
    const fullShown = visible(tab.querySelector('.tab-full'));
    const shortShown = visible(tab.querySelector('.tab-short'));
    if (!fullShown && !shortShown) continue;
  }
  for (const text of nav.querySelectorAll('.brand-title, .brand-subtitle, .pill-eyebrow, .today-weekday, .today-date, .btn-label, .header-chip')) {
    if (visible(text) && text.scrollWidth > text.clientWidth + 0.5) truncated.push(String(text.className) + ' "' + text.textContent.trim().slice(0, 24) + '"');
  }
  for (const t of truncated) problems.push('truncated: ' + t);
  // 6. Tab order vs visual order
  const focusables = Array.from(nav.querySelectorAll('a[href], button:not([disabled]), summary, select:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])')).filter((el) => visible(el) && !el.closest('.visually-hidden') && !el.closest('.class-menu-panel'));
  const named = focusables.map((el) => ({ el, id: (el.getAttribute('aria-label') || el.id || el.textContent.trim() || el.tagName).slice(0, 28), r: el.getBoundingClientRect() }));
  const byCenter = named.slice().sort((a, b) => (a.r.top + a.r.bottom) - (b.r.top + b.r.bottom));
  const rowsList = [];
  for (const n of byCenter) {
    const cy = (n.r.top + n.r.bottom) / 2;
    const row = rowsList.find((x) => Math.abs(x.cy - cy) < 14);
    if (row) row.items.push(n); else rowsList.push({ cy, items: [n] });
  }
  rowsList.sort((a, b) => a.cy - b.cy);
  const visual = rowsList.flatMap((row) => row.items.sort((a, b) => a.r.left - b.r.left));
  const domIds = named.map((n) => n.id), visIds = visual.map((n) => n.id);
  if (domIds.join('|') !== visIds.join('|')) problems.push('tab order differs from visual order: DOM [' + domIds.join(' > ') + '] visual [' + visIds.join(' > ') + ']');
  // 7. click targets: every point of the pill (and of the Semester chip) lands on the summary or a descendant
  const targetOf = (summary, label) => {
    if (!summary || !visible(summary)) { problems.push('click target: ' + label + ' summary is not visible'); return; }
    const r = summary.getBoundingClientRect();
    const miss = [];
    const probe = (x, y, what) => {
      const hit = document.elementFromPoint(x, y);
      if (!hit || !summary.contains(hit)) miss.push(what + ' -> ' + (hit ? hit.tagName.toLowerCase() + '.' + String(hit.className).split(' ')[0] : 'nothing'));
    };
    for (let i = 0; i < 5; i++) for (let j = 0; j < 3; j++) probe(r.left + r.width * (0.04 + 0.92 * i / 4), r.top + r.height * (0.15 + 0.7 * j / 2), 'grid ' + i + ',' + j);
    for (const part of summary.querySelectorAll('.pill-eyebrow, .dot, .class-menu-name, .class-menu-caret, .sem-course-dot, .ui-eyebrow')) {
      if (!visible(part)) continue;
      const pr = part.getBoundingClientRect();
      probe(pr.left + pr.width / 2, pr.top + pr.height / 2, String(part.className).split(' ')[0]);
    }
    if (r.height < 37.5) miss.push('summary only ' + r.height.toFixed(1) + 'px high (min 38)');
    for (const m of miss) problems.push('click target (' + label + '): ' + m);
  };
  const pill = nav.querySelector('.class-menu-header > summary');
  targetOf(pill, 'header pill');
  const chip = document.querySelector('.class-menu-chip > summary');
  if (location.pathname === '/semester') targetOf(chip, 'semester chip');
  // 8. the opened menu: inside the viewport, unclipped, above everything, no sideways scroll
  const openCheck = (menu, label) => {
    if (!menu) return;
    const summary = menu.querySelector('summary'), panel = menu.querySelector('.class-menu-panel');
    const wasOpen = menu.open;
    menu.open = true;
    const r = panel.getBoundingClientRect();
    const vh = window.innerHeight;
    if (r.left < -0.5 || r.right > vw + 0.5) problems.push('menu (' + label + ') outside viewport horizontally: [' + r.left.toFixed(1) + ', ' + r.right.toFixed(1) + '] of ' + vw);
    if (r.top < -0.5 || r.bottom > vh + 0.5) problems.push('menu (' + label + ') outside viewport vertically: [' + r.top.toFixed(1) + ', ' + r.bottom.toFixed(1) + '] of ' + vh);
    const maxW = Math.min(360, vw - 24);
    if (r.width > maxW + 0.5) problems.push('menu (' + label + ') wider than min(360px, 100vw - 24px): ' + r.width.toFixed(1));
    if (r.height < 40) problems.push('menu (' + label + ') has no height');
    const sb = summary.getBoundingClientRect();
    if (r.top < sb.bottom - 0.5) problems.push('menu (' + label + ') overlaps its own pill');
    const pts = [[r.left + 8, r.top + 8], [r.right - 8, r.top + 8], [r.left + 8, r.bottom - 8], [r.right - 8, r.bottom - 8], [(r.left + r.right) / 2, (r.top + r.bottom) / 2], [(r.left + r.right) / 2, r.top + 8]]; // 8px in: the panel corners are rounded (radius 12), a point on the very corner belongs to what is behind it
    for (const [x, y] of pts) {
      const hit = document.elementFromPoint(x, y);
      if (!hit || !panel.contains(hit)) problems.push('menu (' + label + ') covered or clipped at ' + Math.round(x) + ',' + Math.round(y) + ' by ' + (hit ? hit.tagName.toLowerCase() + '.' + String(hit.className).split(' ')[0] : 'nothing'));
    }
    const rows = panel.querySelectorAll('.class-menu-item');
    for (const row of rows) {
      const rr = row.getBoundingClientRect();
      if (rr.height < 40) problems.push('menu (' + label + ') item only ' + rr.height.toFixed(1) + 'px high');
    }
    for (let a = panel.parentElement; a && a !== document.body && a !== document.documentElement; a = a.parentElement) {
      const cs = getComputedStyle(a);
      if (cs.overflowX === 'visible' && cs.overflowY === 'visible') continue;
      const ar = a.getBoundingClientRect();
      if ((cs.overflowX !== 'visible' && (r.left < ar.left - 0.5 || r.right > ar.right + 0.5)) || (cs.overflowY !== 'visible' && (r.top < ar.top - 0.5 || r.bottom > ar.bottom + 0.5))) problems.push('menu (' + label + ') clipped by overflow on .' + String(a.className).split(' ')[0]);
    }
    if (doc.scrollWidth > doc.clientWidth) problems.push('page scrolls sideways with the ' + label + ' menu open: ' + doc.scrollWidth + ' > ' + doc.clientWidth);
    if (!wasOpen) menu.open = false;
  };
  openCheck(nav.querySelector('.class-menu-header'), 'header');
  if (location.pathname === '/semester') openCheck(document.querySelector('.class-menu-chip'), 'semester chip');
  return {
    problems,
    info: { height: +navRect.height.toFixed(1), rows: rowsList.length, labels: Array.from(nav.querySelectorAll('.screen-tab-btn')).map((t) => visible(t.querySelector('.tab-full')) ? 'full' : visible(t.querySelector('.tab-short')) ? 'short' : 'icon')[0], boxes: flags_dump ? items.map((i) => [i.name, box(i.rect)]) : undefined },
  };
})()`.replace('flags_dump', process.env.SWEEP_DUMP ? 'true' : 'false');

function label(zoom) {
  return zoom === 1 ? '100%' : Math.round(zoom * 100) + '%';
}

async function main() {
  const browser = new Browser();
  const results = []; // { page, theme, zoom, width, css, problems, info }
  try {
    await browser.launch({ width: 1287, height: HEIGHT });
    await browser.login();
    for (const theme of themes) {
      for (const page of pages) {
        if (!PAGES[page]) throw new Error('Unknown page ' + page);
        await browser.setViewport(1287, HEIGHT);
        await browser.goto(PAGES[page]);
        await browser.theme(theme);
        if (flags['long-name']) await browser.eval(`(() => { const o = document.querySelector('.class-menu-header .class-menu-name'); if (o) o.textContent = 'ZZ Nutrition and Health, Period 2 (afternoon block, Rm 214)'; return true; })()`);
        for (const zoom of zooms) {
          for (const width of widths) {
            const css = Math.round(width / zoom);
            if (css < 320) continue;
            await browser.send('Emulation.setDeviceMetricsOverride', { width: css, height: HEIGHT, deviceScaleFactor: zoom, mobile: false });
            await sleep(40);
            const out = await browser.eval(MEASURE);
            results.push({ page, theme, zoom, width, css, problems: out.problems, info: out.info });
            if (flags.verbose) console.log(`${page} ${theme} ${label(zoom)} ${width}px (css ${css}) ${out.problems.length ? 'FAIL ' + out.problems.join('; ') : 'ok'} ${JSON.stringify(out.info)}`);
          }
        }
      }
    }
  } finally {
    await browser.close();
  }
  return results;
}

function summarize(results) {
  const failures = results.filter((r) => r.problems.length);
  const lines = [];
  lines.push('| Page | Theme | Zoom | Widths checked | Pass | Fail | Failing widths (CSS px) |');
  lines.push('|---|---|---|---|---|---|---|');
  for (const page of pages) {
    for (const theme of themes) {
      for (const zoom of zooms) {
        const rows = results.filter((r) => r.page === page && r.theme === theme && r.zoom === zoom);
        if (rows.length === 0) continue;
        const bad = rows.filter((r) => r.problems.length);
        lines.push(`| ${page} | ${theme} | ${label(zoom)} | ${rows.length} | ${rows.length - bad.length} | ${bad.length} | ${bad.length ? bad.map((r) => r.css).join(', ') : '-'} |`);
      }
    }
  }
  return { failures, table: lines.join('\n') };
}

const results = await main();
const { failures, table } = summarize(results);
console.log(table);
const distinct = new Map();
for (const f of failures) {
  for (const p of f.problems) {
    const key = p.replace(/\d+(\.\d+)?/g, '#');
    const entry = distinct.get(key) || { sample: p, where: [] };
    entry.where.push(`${f.page}/${f.theme}/${label(f.zoom)}/${f.css}`);
    distinct.set(key, entry);
  }
}
if (failures.length) {
  console.log('\nFailure kinds (' + distinct.size + '):');
  for (const { sample, where } of distinct.values()) console.log(`- ${sample}  [${where.length}x, e.g. ${where.slice(0, 4).join(', ')}]`);
}
console.log(`\nTotal: ${results.length} measurements, ${results.length - failures.length} pass, ${failures.length} fail.`);

if (typeof flags.json === 'string') writeFileSync(resolve(ROOT, flags.json), JSON.stringify(results, null, 1) + '\n');
if (typeof flags.report === 'string') {
  const heights = results.map((r) => r.info.height).filter(Boolean);
  const two = results.filter((r) => r.info.rows >= 2).length;
  const doc = [
    '# Header sweep',
    '',
    'Dev report (English). Tool: `node scripts/dev/header-sweep.mjs --report docs/header-sweep.md` (see `docs/dev-tools.md`). Not shipped in the release zips.',
    '',
    `Real layout measured in headless Chrome against the local app (read-only). ${results.length} measurements: Daily, Weekly, Semester and Roster, light and dark, `,
    `${widths.length} widths (${widths[0]} to ${widths[widths.length - 1]} px: the 22 named widths plus every 20 px from 1000 to 1500), each at 100%, 125% and 150% browser zoom `,
    '(emulated: CSS viewport = width / zoom with deviceScaleFactor = zoom; combinations under 320 CSS px are skipped).',
    '',
    '## Checks (each one is a failure when violated)',
    '',
    '1. No two header controls overlap (brand, class menu, "+", four tabs, chips, theme switch, Undo, Today, avatar).',
    '2. Brand, class menu, "+", the four tabs, theme switch, Undo and avatar are always visible (only the chips and Today may collapse), inside the viewport, inside the header and not clipped by any `overflow` ancestor.',
    '3. `document.documentElement.scrollWidth <= clientWidth` (no horizontal page scroll) and the body is not wider than the viewport.',
    `4. The header is at most two rows (height <= ${MAX_HEADER}px).`,
    '5. No visible tab label, brand text, Undo label or Today text is truncated (`scrollWidth <= clientWidth`).',
    '6. Tab order equals visual order: the focusable header controls in DOM order equal the same controls sorted by (row, x).',
    '7. Click targets: `document.elementFromPoint` over a 5x3 grid of the class pill (and over its eyebrow, dot, name and chevron; on Semester also the course chip) always resolves to the `<summary>` or one of its descendants, never to another element, and the summary is at least 38px high.',
    '8. Opened class menu (header, and the Semester chip): the popup is fully inside the viewport, at most `min(360px, 100vw - 24px)` wide, below its pill, not clipped by any `overflow` ancestor, above the header, sticky bars, footer and page content (`elementFromPoint` at six points of the panel resolves to the panel), every item is at least 40px high, and the page does not scroll sideways.',
    '',
    '## Result',
    '',
    failures.length === 0 ? `**PASS: ${results.length} of ${results.length} measurements, 0 failures.**` : `**FAIL: ${failures.length} of ${results.length} measurements failed.**`,
    '',
    table,
    '',
    `Header height over all measurements: min ${Math.min(...heights)} px, max ${Math.max(...heights)} px (${two} measurements use two rows, the rest one).`,
    '',
    '## Layout decisions',
    '',
    'See the comment block at the top of the "Responsive" section of `public/css/shell.css`: one row while everything fits, then two rows',
    '(row 1 logo, class menu, "+" and the chips; row 2 the four tabs, then theme, Undo, Today and the avatar). The two-row layout is a CSS grid whose DOM order',
    'already equals the visual reading order of the focusable controls (logo, class menu, "+", tabs, theme, Undo, account), so no `order` trick changes the Tab order.',
    'The class menu is a `<details>/<summary>` disclosure: the whole pill is the summary and the panel is positioned from the pill (header, up to 768px: from the left gutter of the header) with `z-index` 120 inside the sticky header, so nothing paints over it.',
    'Chips and Today collapse first; the class menu, "+", tabs, theme switch, Undo and account never do.',
    '',
  ].join('\n');
  writeFileSync(resolve(ROOT, flags.report), doc);
  console.log('wrote ' + flags.report);
}
process.exit(failures.length ? 1 : 0);
