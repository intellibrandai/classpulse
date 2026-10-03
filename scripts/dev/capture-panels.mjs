#!/usr/bin/env node
// DEV-ONLY (not shipped): full-panel and full-page screenshots of the LOCAL app through headless Google Chrome.
//   node scripts/dev/capture-panels.mjs [--class-name "ZZ Demo (temp)"] [--student-name "Alex Rivera"] [--out storage/app/capture-full]
//                                        [--keep] [--delete-after] [--no-seed] [--cleanup] [--width 1287] [--date 2026-09-30] [--week 2026-09-21]
// It logs in as the local test account, creates a TEMPORARY scratch class with fictitious students/records/notes/drafts
// (only when the class does not exist yet), captures every panel in dark and light, then deletes the scratch class with
// the app's own "Delete this class" flow. --keep leaves it in place (print-pdfs.mjs --class-name can use it);
// --header [--screens weekly,daily] [--widths 1100,1280] [--themes dark] [--zoom 1.25] [--height 240] clips only the top of the page (header
// legibility shots, read-only, no scratch class); --login shoots the login page (dark + light desktop, dark + light mobile). Both skip seeding.
// --no-seed uses the class as it is; --delete-after also deletes a reused scratch class; --cleanup only deletes it. Only classes whose name starts with "ZZ " are ever deleted. Node 24 built-ins only.
import { mkdirSync } from 'node:fs';
import { writeFile } from 'node:fs/promises';
import { join, resolve } from 'node:path';
import { Browser, ROOT, parseFlags, sleep } from './lib/cdp.mjs';
import { DEFAULT_CLASS_NAME, deleteScratchClass, findClassId, listClasses, seedScratchClass } from './lib/scratch.mjs';

const flags = parseFlags(process.argv.slice(2));
const className = typeof flags['class-name'] === 'string' ? flags['class-name'] : DEFAULT_CLASS_NAME;
const studentName = typeof flags['student-name'] === 'string' ? flags['student-name'] : 'Alex Rivera';
const outDir = resolve(ROOT, typeof flags.out === 'string' ? flags.out : 'storage/app/capture-full');
const width = Number(flags.width) || 1287;
const DATE = typeof flags.date === 'string' ? flags.date : '2026-09-30';
const WEEK = typeof flags.week === 'string' ? flags.week : '2026-09-21';
const log = (line) => console.log(line);

// Grows the viewport until the element and its scrollable content fit, then returns its document box.
async function fit(browser, selector) {
  let height = 1000;
  for (let i = 0; i < 8; i++) {
    await browser.setViewport(width, height);
    await browser.eval('window.scrollTo(0, 0); true');
    await sleep(120);
    const m = await browser.eval(`(() => {
      const el = document.querySelector(${JSON.stringify(selector)});
      if (!el) return null;
      const box = el.getBoundingClientRect();
      const inner = el.querySelector('.sem-comments-list');
      const overflow = Math.max(0, el.scrollHeight - el.clientHeight) + (inner ? Math.max(0, inner.scrollHeight - inner.clientHeight) : 0);
      return { x: box.left + scrollX, y: box.top + scrollY, w: box.width, h: box.height, overflow, doc: document.documentElement.scrollHeight };
    })()`);
    if (!m) throw new Error('Nothing matches ' + selector);
    if (m.overflow <= 1 && m.y + m.h <= height) return m;
    height = Math.min(16000, Math.ceil(Math.max(height + m.overflow + 40, m.y + m.h + 40)));
  }
  throw new Error('Could not fit ' + selector);
}

async function shoot(browser, file, clip) {
  const { data } = await browser.send('Page.captureScreenshot', {
    format: 'png', captureBeyondViewport: true, fromSurface: true,
    clip: { x: Math.floor(clip.x), y: Math.floor(clip.y), width: Math.ceil(clip.w), height: Math.ceil(clip.h), scale: 1 },
  });
  await writeFile(join(outDir, file), Buffer.from(data, 'base64'));
  log('  wrote ' + join(outDir, file).replace(ROOT + '/', '') + '  (' + Math.ceil(clip.w) + 'x' + Math.ceil(clip.h) + ')');
}

async function shootElement(browser, selector, file) {
  await shoot(browser, file, await fit(browser, selector));
}

async function shootPage(browser, file) {
  let height = 1000;
  for (let i = 0; i < 4; i++) {
    await browser.setViewport(width, height);
    await sleep(150);
    const doc = await browser.eval('Math.max(document.documentElement.scrollHeight, document.body.scrollHeight)');
    if (doc <= height) { height = doc; break; }
    height = Math.min(16000, doc);
  }
  await browser.setViewport(width, height);
  await sleep(200);
  await shoot(browser, file, { x: 0, y: 0, w: width, h: height });
}

// Runs on the Daily page: the card whose name button matches.
async function pickStudent(browser) {
  return browser.eval(`(() => {
    const card = Array.from(document.querySelectorAll('.student-card')).find((c) => c.querySelector('.student-name').textContent.trim() === ${JSON.stringify(studentName)});
    return card ? Number(card.dataset.studentId) : null;
  })()`);
}

// ---- --login / --header: read-only shots of the top of the page, no scratch class ----
async function clipShot(browser, file, cssWidth, cssHeight, scale) {
  await browser.send('Emulation.setDeviceMetricsOverride', { width: cssWidth, height: cssHeight, deviceScaleFactor: scale, mobile: false });
  await sleep(250);
  const { data } = await browser.send('Page.captureScreenshot', { format: 'png', fromSurface: true, clip: { x: 0, y: 0, width: cssWidth, height: cssHeight, scale: 1 } });
  await writeFile(join(outDir, file), Buffer.from(data, 'base64'));
  log('  wrote ' + join(outDir, file).replace(ROOT + '/', '') + '  (' + cssWidth + 'x' + cssHeight + ' css px, scale ' + scale + ')');
}

async function topShots() {
  mkdirSync(outDir, { recursive: true });
  const b = new Browser();
  const csvList = (v, fallback) => (typeof v === 'string' ? v.split(',').map((x) => x.trim()).filter(Boolean) : fallback);
  try {
    await b.launch({ width: 1440, height: 900 });
    if (flags.login) {
      for (const [theme, w, h, tag] of [['dark', 1440, 900, 'desktop'], ['light', 1440, 900, 'desktop'], ['dark', 375, 812, 'mobile'], ['light', 375, 812, 'mobile']]) {
        await b.send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: false });
        await b.goto('/login');
        await b.theme(theme);
        await clipShot(b, 'login-' + theme + '-' + tag + '.png', w, h, 1);
      }
    }
    if (flags.header) {
      await b.login();
      const screens = csvList(flags.screens, ['weekly']);
      const widths = csvList(flags.widths, ['1280']).map(Number);
      const themes = csvList(flags.themes, ['dark']);
      const zoom = Number(flags.zoom) || 1;
      const height = Number(flags.height) || 240;
      for (const theme of themes) {
        for (const screen of screens) {
          await b.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
          await b.goto('/' + screen);
          await b.theme(theme);
          for (const w of widths) {
            const css = Math.round(w / zoom);
            await clipShot(b, 'header-' + screen + '-' + theme + '-' + w + (zoom === 1 ? '' : '-zoom' + Math.round(zoom * 100)) + '.png', css, height, zoom);
          }
        }
      }
    }
  } finally {
    await b.close();
  }
}

if (flags.login || flags.header) {
  try {
    await topShots();
  } catch (error) {
    console.error('capture-panels failed: ' + error.message);
    process.exitCode = 1;
  }
  process.exit(process.exitCode || 0);
}

const browser = new Browser();
let scratchId = null;
let created = false;
try {
  mkdirSync(outDir, { recursive: true });
  await browser.launch({ width, height: 1000 });
  await browser.login();
  log('logged in');

  let classId = await findClassId(browser, className);
  if (flags.cleanup) {
    if (classId === null) log('no class named "' + className + '"; nothing to delete');
    else await deleteScratchClass(browser, classId, className, log);
    await browser.close();
    process.exit(0);
  }
  if (classId === null) {
    if (flags['no-seed']) throw new Error('Class "' + className + '" does not exist and --no-seed was given');
    if (!className.startsWith('ZZ ')) throw new Error('Only scratch classes named "ZZ ..." are created by this script');
    ({ classId } = await seedScratchClass(browser, className, log));
    created = true;
  } else {
    log('using existing class ' + className + ' (id ' + classId + ')');
  }
  scratchId = classId;

  await browser.goto('/daily?class=' + classId + '&date=' + DATE);
  const studentId = await pickStudent(browser);
  if (!studentId) throw new Error('Student "' + studentName + '" not found in ' + className);

  for (const mode of ['dark', 'light']) {
    log('== ' + mode);
    // (a) Daily student panel
    await browser.goto('/daily?class=' + classId + '&date=' + DATE);
    await browser.theme(mode);
    await browser.setViewport(width, 1000);
    await browser.click('.student-card[data-student-id="' + studentId + '"] .student-name');
    await browser.waitFor('document.querySelector(\'.inspector-student[data-panel-student="' + studentId + '"]\') && !document.querySelector(\'.inspector-student[data-panel-student="' + studentId + '"]\').hidden');
    await browser.settle(700);
    await shootElement(browser, '.inspector-panel', 'daily-student-panel-' + mode + '.png');
    await shootPage(browser, 'page-daily-' + mode + '.png');

    // (b) Semester inspector, (c) comments dialog, page
    await browser.goto('/semester?class=' + classId + '&period=full');
    await browser.theme(mode);
    await browser.setViewport(width, 1000);
    await browser.click('tr[data-student-id="' + studentId + '"] [data-inspect]');
    await browser.waitFor('document.querySelector("[data-inspector-body]") && !document.querySelector("[data-inspector-body]").hidden');
    await browser.settle(700);
    await browser.click('[data-note-add]');
    await browser.settle(300);
    await shootElement(browser, '[data-inspector]', 'semester-inspector-' + mode + '.png');
    await shootPage(browser, 'page-semester-' + mode + '.png');
    await browser.click('[data-inspector-close]').catch(() => {});
    await browser.click('[data-open-comments]');
    await browser.waitFor('document.querySelector("[data-comments-dialog]").open');
    await browser.waitFor('!document.querySelector("[data-comments-status]").textContent.includes("Loading")', 8000);
    await browser.settle(500);
    await shootElement(browser, '[data-comments-dialog]', 'semester-comments-dialog-' + mode + '.png');

    // (d) remaining full pages
    await browser.goto('/weekly?class=' + classId + '&week=' + WEEK);
    await browser.theme(mode);
    await browser.settle(600);
    await shootPage(browser, 'page-weekly-' + mode + '.png');
    await browser.goto('/roster?class=' + classId);
    await browser.theme(mode);
    await browser.settle(500);
    await shootPage(browser, 'page-roster-' + mode + '.png');
  }
  log('screenshots are in ' + outDir.replace(ROOT + '/', ''));
} catch (error) {
  console.error('capture-panels failed: ' + error.message);
  process.exitCode = 1;
} finally {
  try {
    if ((created || flags['delete-after']) && !flags.keep && scratchId !== null) {
      await browser.goto('/daily');
      const left = await deleteScratchClass(browser, scratchId, className, log);
      const names = left.map((c) => c.name);
      log('remaining classes: ' + names.join(', '));
    } else if (created || scratchId !== null) {
      log('kept scratch class "' + className + '" (id ' + scratchId + '); delete it with: node scripts/dev/capture-panels.mjs --cleanup --class-name "' + className + '"');
    }
  } catch (error) {
    console.error('Scratch class cleanup failed: ' + error.message + ' (delete "' + className + '" from Class Roster & Settings)');
    process.exitCode = 1;
  }
  await browser.close();
}
