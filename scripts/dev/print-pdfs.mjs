#!/usr/bin/env node
// DEV-ONLY (not shipped): renders the print layouts of the LOCAL app to PDF through headless Google Chrome (CDP
// Emulation.setEmulatedMedia print + Page.printToPDF, Letter, @page margins, backgrounds on) so they can be reviewed.
//   node scripts/dev/print-pdfs.mjs [--class-name "HLS 3O"] [--student-name "Alex Rivera"] [--date 2026-09-30]
//                                    [--week 2026-09-21] [--out storage/app/print-check] [--full-roster] [--keep]
// --full-roster creates the TEMPORARY scratch class "ZZ Full (temp)" (30 students, long names and observations, so the
// roster spans two pages), prints it and deletes it again through the roster page (--keep leaves it).
// Read-only: it only clicks "select student" and opens pages; it never saves anything. Needs the app on
// http://localhost:8090 and the local test account (storage/app/local-test-login.txt). Node 24 built-ins only.
import { mkdirSync } from 'node:fs';
import { writeFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { join, resolve } from 'node:path';
import { Browser, ROOT, parseFlags, sleep } from './lib/cdp.mjs';
import { deleteScratchClass, findClassId, seedFullRoster } from './lib/scratch.mjs';
import { measurePdf, problemsOf } from './lib/pdf-edge.mjs';

const flags = parseFlags(process.argv.slice(2));
const FULL = Boolean(flags['full-roster']);
const className = FULL ? 'ZZ Full (temp)' : (typeof flags['class-name'] === 'string' ? flags['class-name'] : 'HLS 3O');
const studentName = typeof flags['student-name'] === 'string' ? flags['student-name'] : null;
const outDir = resolve(ROOT, typeof flags.out === 'string' ? flags.out : 'storage/app/print-check');
const DATE = typeof flags.date === 'string' ? flags.date : '2026-09-30';
const WEEK = typeof flags.week === 'string' ? flags.week : '2026-09-21';
const slug = className.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');

const browser = new Browser();

async function selectFirstStudent(selector, clickSelector, readyExpression) {
  const name = await browser.eval(`(() => {
    const wanted = ${JSON.stringify(studentName)};
    const items = Array.from(document.querySelectorAll(${JSON.stringify(selector)}));
    const pick = wanted ? items.find((el) => el.textContent.includes(wanted)) : items[0];
    if (!pick) return null;
    pick.click();
    return pick.textContent.trim().split('\\n')[0].trim();
  })()`);
  if (!name) throw new Error('No student to select with ' + selector);
  await browser.waitFor(readyExpression, 15000);
  await browser.settle(700);
  return name;
}

async function printTo(file) {
  await browser.send('Emulation.setEmulatedMedia', { media: 'print' });
  await browser.eval("window.dispatchEvent(new Event('beforeprint')); true");
  await browser.settle(300);
  const { data } = await browser.send('Page.printToPDF', {
    paperWidth: 8.5, paperHeight: 11, marginTop: 0.55, marginBottom: 0.55, marginLeft: 0.47, marginRight: 0.47,
    printBackground: true, preferCSSPageSize: true, displayHeaderFooter: false,
  });
  await browser.send('Emulation.setEmulatedMedia', { media: '' });
  const path = join(outDir, file);
  await writeFile(path, Buffer.from(data, 'base64'));
  const info = spawnSync('pdfinfo', [path], { encoding: 'utf8' });
  const pages = /Pages:\s+(\d+)/.exec(info.stdout || '');
  const size = /Page size:\s+(.+)/.exec(info.stdout || '');
  console.log('  ' + path.replace(ROOT + '/', '') + (pages ? '  ' + pages[1] + ' page(s), ' + (size ? size[1].trim() : '') : ''));
  // Edge guard at 300 dpi: words and ink inside the @page margins, and no hairline right border on tables.
  const problems = problemsOf(measurePdf(path));
  for (const problem of problems) console.log('    EDGE PROBLEM: ' + problem);
  if (problems.length) edgeProblems += problems.length;
}

let scratchToDelete = null;
let edgeProblems = 0;
try {
  mkdirSync(outDir, { recursive: true });
  await browser.launch({ width: 1287, height: 1000 });
  await browser.login();
  let classId = await findClassId(browser, className);
  let createdHere = false;
  if (FULL && classId === null) {
    classId = (await seedFullRoster(browser, className)).classId;
    createdHere = true;
    if (!flags.keep) scratchToDelete = classId;
  }
  if (classId === null) throw new Error('No class named "' + className + '"');
  console.log('class ' + className + ' (id ' + classId + '), printing to ' + outDir.replace(ROOT + '/', ''));

  await browser.goto('/daily?class=' + classId + '&date=' + DATE);
  await browser.settle(800);
  await printTo(slug + '-daily-day-slip.pdf');
  const picked = await selectFirstStudent('.student-card .student-name', null, 'document.querySelector(".inspector-student:not([hidden])")');
  await printTo(slug + '-daily-day-slip-student-selected.pdf');

  await browser.goto('/weekly?class=' + classId + '&week=' + WEEK);
  await browser.settle(800);
  await printTo(slug + '-weekly.pdf');

  await browser.goto('/semester?class=' + classId + '&period=full');
  await browser.settle(800);
  await printTo(slug + '-semester-full.pdf');
  await browser.eval(`(() => { const rows = Array.from(document.querySelectorAll('tr[data-student-id]')); const wanted = ${JSON.stringify(studentName)}; const row = (wanted && rows.find((r) => r.textContent.includes(wanted))) || rows[0]; row.querySelector('[data-inspect]').click(); return true; })()`);
  await browser.waitFor('document.querySelector("[data-inspector-body]") && !document.querySelector("[data-inspector-body]").hidden', 15000);
  await browser.settle(700);
  await printTo(slug + '-semester-full-student-selected.pdf');

  await browser.goto('/roster?class=' + classId);
  await browser.settle(800);
  await printTo(slug + '-roster.pdf');
  console.log('done (student used for the selected variants: ' + (studentName || picked) + ')');

  if (edgeProblems > 0) {
    console.error('print edge check: ' + edgeProblems + ' problem(s)');
    process.exitCode = 1;
  } else {
    console.log('print edge check: clean (words and ink inside the @page margins, no hairline table border)');
  }
} catch (error) {
  console.error('print-pdfs failed: ' + error.message);
  process.exitCode = 1;
} finally {
  if (scratchToDelete !== null) {
    try {
      await deleteScratchClass(browser, scratchToDelete, className);
      console.log('scratch class deleted');
    } catch (error) {
      console.error('could not delete the scratch class (remove it with "Delete this class"): ' + error.message);
    }
  }
  await browser.close();
}
