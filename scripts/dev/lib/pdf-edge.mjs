// DEV-ONLY: measures a printed PDF against the @page margins with poppler (pdftotext -bbox, pdftoppm). Node built-ins only.
//  - word boxes (pdftotext -bbox) must be inside the printable box (horizontal, 0.5pt tolerance)
//  - ink (rendered at 300 dpi) must not reach into the left/right/top/bottom margin band
//  - the outermost vertical line of a table (its right border) must be at least 2px (0.5pt) wide: a 1px line is the
//    "half border cut off at the margin" hairline
import { mkdtempSync, readFileSync, readdirSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawnSync } from 'node:child_process';

export const MARGIN_X_PT = (12 / 25.4) * 72; // @page { margin: 14mm 12mm } in public/css/print.css
export const MARGIN_Y_PT = (14 / 25.4) * 72;
const DPI = 300;

function readPgm(file) {
  const buf = readFileSync(file);
  let pos = 0;
  const token = () => {
    while (buf[pos] === 0x20 || buf[pos] === 0x0a || buf[pos] === 0x0d || buf[pos] === 0x09) pos++;
    const start = pos;
    while (buf[pos] !== 0x20 && buf[pos] !== 0x0a && buf[pos] !== 0x0d && buf[pos] !== 0x09) pos++;
    return buf.toString('latin1', start, pos);
  };
  if (token() !== 'P5') throw new Error('not a binary PGM');
  const width = Number(token());
  const height = Number(token());
  token(); // maxval
  pos++;
  return { width, height, data: buf.subarray(pos) };
}

export function measurePdf(pdf) {
  const dir = mkdtempSync(join(tmpdir(), 'classpulse-pdf-'));
  try {
    spawnSync('pdftoppm', ['-r', String(DPI), '-gray', pdf, join(dir, 'p')], { stdio: 'ignore' });
    const bbox = spawnSync('pdftotext', ['-bbox', pdf, '-'], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }).stdout || '';
    const pageBlocks = bbox.split('<page ').slice(1);
    const files = readdirSync(dir).filter((f) => f.endsWith('.pgm')).sort();
    const pages = files.map((file, index) => {
      const { width, height, data } = readPgm(join(dir, file));
      const ptW = (width / DPI) * 72;
      const ptH = (height / DPI) * 72;
      const marginX = Math.round((MARGIN_X_PT / 72) * DPI);
      const marginY = Math.round((MARGIN_Y_PT / 72) * DPI);
      let minX = width, maxX = -1, minY = height, maxY = -1;
      const lastRun = new Map();
      for (let y = 0; y < height; y++) {
        const row = y * width;
        let first = -1, last = -1;
        for (let x = 0; x < width; x++) {
          if (data[row + x] < 215) { if (first < 0) first = x; last = x; }
        }
        if (first < 0) continue;
        if (first < minX) minX = first;
        if (last > maxX) maxX = last;
        if (y < minY) minY = y;
        maxY = y;
        if (last > width - Math.round(0.6 * DPI)) {
          let x = last;
          while (x >= 0 && data[row + x] < 215) x--;
          const run = last - x;
          lastRun.set(run, (lastRun.get(run) || 0) + 1);
        }
      }
      const words = [...(pageBlocks[index] || '').matchAll(/xMin="([\d.]+)" yMin="([\d.]+)" xMax="([\d.]+)" yMax="([\d.]+)">(.*?)<\/word>/g)]
        .map((m) => ({ xMin: +m[1], yMin: +m[2], xMax: +m[3], yMax: +m[4], text: m[5] }));
      const wordsOutside = words.filter((w) => w.xMax > ptW - MARGIN_X_PT + 0.5 || w.xMin < MARGIN_X_PT - 0.5);
      const dominant = [...lastRun.entries()].sort((a, b) => b[1] - a[1])[0];
      return {
        page: index + 1,
        inkLeftIn: minX / DPI, inkRightGapIn: (width - 1 - maxX) / DPI,
        inkIntoMarginPx: { left: Math.max(0, marginX - minX), right: Math.max(0, maxX - (width - 1 - marginX)), top: Math.max(0, marginY - minY), bottom: Math.max(0, maxY - (height - 1 - marginY)) },
        words: words.length, wordsOutside: wordsOutside.map((w) => w.text + '@' + w.xMax.toFixed(1)),
        maxWordXMaxPt: Math.max(0, ...words.map((w) => w.xMax)), limitPt: ptW - MARGIN_X_PT,
        rightBorderPx: dominant ? dominant[0] : null, rightBorderRows: dominant ? dominant[1] : 0,
      };
    });
    return pages;
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
}

// Returns a list of problems (empty = clean).
export function problemsOf(pages) {
  const problems = [];
  for (const p of pages) {
    if (p.wordsOutside.length) problems.push(`page ${p.page}: ${p.wordsOutside.length} word box(es) outside the left/right margin (${p.wordsOutside.slice(0, 3).join(', ')})`);
    for (const [side, px] of Object.entries(p.inkIntoMarginPx)) {
      if (px > 3) problems.push(`page ${p.page}: ink runs ${px}px (${(px / DPI * 25.4).toFixed(2)} mm) into the ${side} margin`);
    }
    if (p.rightBorderPx !== null && p.rightBorderRows > 20 && p.rightBorderPx < 2) {
      problems.push(`page ${p.page}: the table's right border is ${p.rightBorderPx}px wide at ${DPI} dpi (a hairline: its outer half is cut off at the margin)`);
    }
  }
  return problems;
}
