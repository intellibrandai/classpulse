const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const css = fs.readFileSync(path.join(__dirname, '../../public/css/tokens.css'), 'utf8');

// Pull the declarations of the light set (:root), the explicit dark set ([data-theme="dark"]) and the OS-dark set.
function block(selector) {
  const start = css.indexOf(selector + ' {');
  assert.ok(start >= 0, 'missing block ' + selector);
  let depth = 0;
  for (let i = css.indexOf('{', start); i < css.length; i++) {
    if (css[i] === '{') depth++;
    if (css[i] === '}' && --depth === 0) return css.slice(css.indexOf('{', start) + 1, i);
  }
  throw new Error('unterminated ' + selector);
}
function declarations(text) {
  const out = {};
  for (const m of text.matchAll(/(--[\w-]+)\s*:\s*([^;]+);/g)) out[m[1]] = m[2].trim();
  return out;
}
const light = declarations(block(':root'));
const dark = { ...light, ...declarations(block('[data-theme="dark"]')) };
const osDark = { ...light, ...declarations(block(':root:not([data-theme="light"])')) };

function resolve(tokens, value, depth = 0) {
  const m = /^var\((--[\w-]+)\)$/.exec(value);
  if (!m) return value;
  assert.ok(depth < 10 && tokens[m[1]], 'cannot resolve ' + value);
  return resolve(tokens, tokens[m[1]], depth + 1);
}
function parseColor(value) {
  let m = /^#([0-9a-f]{6})$/i.exec(value);
  if (m) return { r: parseInt(m[1].slice(0, 2), 16), g: parseInt(m[1].slice(2, 4), 16), b: parseInt(m[1].slice(4), 16), a: 1 };
  m = /^rgba?\(\s*(\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?\s*\)$/.exec(value);
  assert.ok(m, 'unsupported colour ' + value);
  return { r: +m[1], g: +m[2], b: +m[3], a: m[4] === undefined ? 1 : +m[4] };
}
function over(fg, bg) {
  return { r: fg.r * fg.a + bg.r * (1 - fg.a), g: fg.g * fg.a + bg.g * (1 - fg.a), b: fg.b * fg.a + bg.b * (1 - fg.a), a: 1 };
}
function lum(c) {
  const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
  return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b);
}
function ratio(a, b) {
  const [hi, lo] = [lum(a), lum(b)].sort((x, y) => y - x);
  return (hi + 0.05) / (lo + 0.05);
}
// A background is a list of layers, bottom first; translucent tokens are composited over what is below.
function flatten(tokens, layers) {
  return layers.map((t) => parseColor(resolve(tokens, tokens[t] || t))).reduce((under, layer) => over(layer, under));
}

const S = '--bg-surface';
const CARD = '--bg-surface-card';
const ELEV = '--bg-surface-elevated';
const APP = '--bg-app';

// Glass panels are translucent: text is checked over the page background, each decorative glow and the panel tint.
const GLOWS = ['--glow-violet-soft', '--glow-blue-soft', '--glow-green-soft'];
const GLASS = (glow) => [APP, glow, '--glass-bg'];
const HEADER = [APP, '--glass-bg-strong'];
const GLASS_TEXT = [];
for (const glow of GLOWS) {
  for (const fg of ['--text-primary', '--text-secondary', '--text-muted']) GLASS_TEXT.push([fg, GLASS(glow), 'text on a glass panel over ' + glow]);
  for (const fg of ['--text-accent', '--text-success', '--text-danger', '--text-warning', '--text-info']) GLASS_TEXT.push([fg, GLASS(glow), 'accent text on glass over ' + glow]);
  GLASS_TEXT.push(
    ['--text-success', [...GLASS(glow), '--color-emerald-bg'], 'Present badge / green icon chip on glass'],
    ['--text-danger', [...GLASS(glow), '--color-crimson-bg-strong'], 'Absent badge on glass'],
    ['--text-danger', [...GLASS(glow), '--color-crimson-bg'], 'red icon chip on glass'],
    ['--text-warning', [...GLASS(glow), '--color-amber-bg'], 'amber icon chip on glass'],
    ['--text-info', [...GLASS(glow), '--info-bg'], 'info badge / blue icon chip on glass'],
    ['--text-primary', [...GLASS(glow), '--info-bg'], 'Files & backups "not a backup" note on glass'],
    ['--text-info', [...GLASS(glow), '--info-bg'], 'Files & backups note icon'],
    ['--text-primary', [...GLASS(glow), '--input-bg'], 'Files & backups export rows (name text) on glass'],
    ['--text-accent', [...GLASS(glow), '--color-indigo-tint'], 'violet badge / icon chip on glass'],
    ['--badge-zero-text', [...GLASS(glow), '--badge-zero-bg'], 'Present 0 badge on glass'],
    ['--text-muted', [...GLASS(glow), '--kbd-bg'], 'kbd hint on glass'],
    ['--text-muted', [...GLASS(glow), '--input-bg'], 'search / input placeholder on glass'],
    ['--text-primary', [...GLASS(glow), '--input-bg'], 'input text on glass'],
    ['--text-secondary', [...GLASS(glow), '--input-bg'], 'chip, segmented item, pill on glass'],
    ['--text-muted', [...GLASS(glow), '--input-bg', '--kbd-bg'], 'kbd hint inside a search field'],
    ['--text-secondary', [...GLASS(glow), '--input-bg', '--hover-tint'], 'chip count'],
    ['--text-primary', [...GLASS(glow), '--input-bg', '--hover-tint'], 'hovered segmented item'],
    ['--text-secondary', [...GLASS(glow), '--hover-tint-soft'], 'table header on glass'],
    ['--text-primary', [...GLASS(glow), '--hover-tint-soft'], 'hovered table row'],
    ['--text-primary', [...GLASS(glow), '--color-indigo-tint'], 'selected table row'],
    ['--text-muted', [...GLASS(glow), '--color-indigo-tint'], 'selected table row secondary text'],
    ['--text-accent', [...GLASS(glow), '--color-indigo-tint'], 'Daily note flash line'],
    ['--text-success', [...GLASS(glow), '--color-emerald-bg'], 'Daily "Added" confirmation'],
    ['--text-warning', GLASS(glow), 'note counter near the 2000 limit'],
    ['--text-danger', GLASS(glow), 'note counter over the limit, note error text'],
    ['--text-secondary', [...GLASS(glow), '--bg-surface-elevated'], 'note recent list, cadence empty state'],
    ['--on-accent', ['--color-indigo-solid', '--glow-violet-soft'], 'Save Student Note primary button'],
    // Semester Analytics
    ['--text-primary', [...GLASS(glow), '--color-indigo-tint', '--hover-tint'], 'Semester average pill on the selected row'],
    ['--text-muted', [...GLASS(glow), '--hover-tint'], 'Semester average pill, No data'],
    ['--text-accent', [...GLASS(glow), '--input-bg'], 'Set period dates link in the period box'],
    ['--text-secondary', [...GLASS(glow), '--input-bg'], 'period box value, audit log rows, note text'],
    ['--text-danger', [...GLASS(glow), '--input-bg'], 'note form error, absent status'],
    ['--text-warning', [...GLASS(glow), '--input-bg'], 'note counter near the limit inside the note form'],
    ['--text-success', [...GLASS(glow), '--input-bg'], 'note form Saved status'],
    ['--text-primary', [...GLASS(glow), '--input-bg', '--hover-tint'], 'Inspect button hovered'],
    // Class Roster & Settings
    ['--text-primary', [...GLASS(glow), '--hover-tint'], 'Roster semester average pill'],
    ['--text-primary', [...GLASS(glow), '--color-indigo-tint'], 'current class card code'],
    ['--text-secondary', [...GLASS(glow), '--color-indigo-tint'], 'current class card subject and student count'],
    ['--text-danger', [...GLASS(glow), '--glass-bg', '--color-crimson-bg-strong'], 'Full badge inside the capacity meter'],
    ['--text-secondary', [...GLASS(glow), '--glass-bg'], 'capacity meter caption'],
    ['--text-primary', [...GLASS(glow), '--glass-bg'], 'capacity meter figures'],
    ['--text-success', [...GLASS(glow), '--input-bg'], 'lock icon on a calculation rule'],
    ['--text-secondary', [...GLASS(glow), '--input-bg'], 'calculation rule description and period row text'],
    ['--text-muted', [...GLASS(glow), '--input-bg'], 'period row field labels and hints'],
    ['--text-danger', [...GLASS(glow), '--input-bg'], 'inline period error inside a period row'],
    ['--text-primary', [...GLASS(glow), '--glass-bg-strong'], 'archive confirmation text'],
    // Account group (change email / password)
    ['--text-primary', GLASS(glow), 'Account: signed-in email, group title'],
    ['--text-muted', GLASS(glow), 'Account: Signed in as eyebrow, password hint'],
    ['--text-danger', GLASS(glow), 'Account: inline field errors'],
    ['--text-primary', [...GLASS(glow), '--input-bg'], 'Account: typed email and password text'],
    ['--text-muted', [...GLASS(glow), '--input-bg'], 'Account: input placeholder'],
    ['--text-primary', [...GLASS(glow), '--input-bg', '--hover-tint'], 'Account: hovered show/hide password button'],
    ['--text-secondary', [...GLASS(glow), '--input-bg', '--hover-tint'], 'Account: show/hide password button'],
    ['--on-accent', ['--color-indigo-solid', glow], 'Account: Change email / Change password primary buttons'],
    // Weekly Matrix chips: translucent state colours over the translucent matrix glass, also under the hovered row tint.
    ...[['0', 'recorded zero'], ['low', '1-2 points'], ['mid', '3-5 points'], ['high', '6+ points'], ['absent', 'Absent A']].flatMap(([k, what]) => [
      [`--chip-${k}-text`, [...GLASS(glow), `--chip-${k}-bg`], `Weekly chip ${what}`],
      [`--chip-${k}-text`, [...GLASS(glow), '--hover-tint-soft', `--chip-${k}-bg`], `Weekly chip ${what} in a hovered row`],
    ]),
    ['--chip-none-text', GLASS(glow), 'Weekly chip not recorded dash'],
  );
}

// [foreground token, background layers, where it is used, minimum ratio]
const TEXT = [
  ...GLASS_TEXT,
  ['--text-primary', HEADER, 'header text'],
  ['--text-secondary', HEADER, 'header links'],
  ['--text-muted', HEADER, 'header subtitle, today label'],
  ['--text-secondary', [...HEADER, '--input-bg'], 'tabs, class pill, undo, theme switch'],
  ['--text-primary', [...HEADER, '--input-bg'], 'class pill, chips'],
  ['--text-muted', [...HEADER, '--input-bg'], 'pill eyebrow'],
  ['--text-success', [...HEADER, '--input-bg'], 'header chip Present'],
  ['--text-danger', [...HEADER, '--input-bg'], 'header chip Absent'],
  ['--text-accent', [...HEADER, '--input-bg', '--primary-soft'], 'active theme switch half'],
  ['--text-secondary', [...HEADER, '--hover-tint'], 'hovered account item'],
  ['--text-primary', [...HEADER, '--hover-tint'], 'hovered account item'],
  ['--on-accent', ['--color-indigo-solid', '--chip-count-active-bg'], 'active chip count'],
  ['--text-primary', [APP, '--glass-bg-strong'], 'dialog and popover text'],
  // Class menu (header pill and Semester chip): the panel is opaque glass (--glass-solid); items, the current item (indigo tint), hover and the check icon.
  ['--text-primary', ['--glass-solid'], 'class menu item name'],
  ['--text-secondary', ['--glass-solid'], 'class menu item period, subject and student count'],
  ['--text-primary', ['--glass-solid', '--color-indigo-tint'], 'current class in the class menu: name'],
  ['--text-secondary', ['--glass-solid', '--color-indigo-tint'], 'current class in the class menu: detail line'],
  ['--text-accent', ['--glass-solid', '--color-indigo-tint'], 'current class in the class menu: check icon'],
  ['--text-primary', ['--glass-solid', '--hover-tint'], 'hovered or focused class menu item: name'],
  ['--text-secondary', ['--glass-solid', '--hover-tint'], 'hovered or focused class menu item: detail line'],
  ['--text-primary', ['--glass-solid', '--color-indigo-tint', '--hover-tint'], 'hovered current class menu item: name'],
  ['--text-secondary', ['--glass-solid', '--color-indigo-tint', '--hover-tint'], 'hovered current class menu item: detail line'],
  ['--text-accent', ['--glass-solid', '--color-indigo-tint', '--hover-tint'], 'hovered current class menu item: check icon'],
  ['--text-muted', [...HEADER, '--input-bg'], 'class pill period label and chevron'],
  // Semester master table: badges sit on an opaque base so neither the hover nor the selected row tint can lower their contrast.
  ['--text-success', ['--glass-solid', '--color-emerald-bg'], 'Semester trend badge (up)'],
  ['--text-danger', ['--glass-solid', '--color-crimson-bg-strong'], 'Semester absences and trend badge (down)'],
  ['--badge-zero-text', ['--glass-solid', '--badge-zero-bg'], 'Semester 0 absences badge'],
  // Report Card Comments dialog: draft cards sit on the input tint inside the strong glass dialog.
  ['--text-primary', [APP, '--glass-bg-strong', '--input-bg'], 'Report card comment name, note dates, draft text'],
  ['--text-secondary', [APP, '--glass-bg-strong', '--input-bg'], 'Report card comment stats and saved notes'],
  ['--text-muted', [APP, '--glass-bg-strong', '--input-bg'], 'Report card comment eyebrow labels'],
  ['--text-success', [APP, '--glass-bg-strong', '--input-bg'], 'Report card comment Saved status'],
  ['--text-danger', [APP, '--glass-bg-strong', '--input-bg'], 'Report card comment Save failed status'],
  ['--text-success', [APP, '--glass-bg-strong', '--input-bg', '--color-emerald-bg'], 'Report card comment Saved draft badge'],
  ['--text-primary', [APP, '--glass-bg-strong', '--info-bg'], 'Report card comments explanation note'],
  ['--text-success', [APP, '--glass-bg-strong'], 'Report card comments copy status'],
  ['--text-secondary', [APP, '--glass-bg-strong'], 'Delete note dialog text'],
  ['--text-danger', [APP, '--glass-bg-strong'], 'Reset Day item in the day menu, inline errors in the roster edit dialog'],
  ['--text-danger', [APP, '--glass-bg-strong', '--input-bg'], 'inline error text beside a dialog field'],
  ['--text-danger', [APP, '--glass-bg-strong', '--hover-tint'], 'Reset Day item hovered'],
  ['--text-secondary', [APP, '--glass-bg-strong'], 'dialog field labels and body text'],
  ['--text-muted', [APP, '--glass-bg-strong'], 'dialog and popover secondary text'],
  ['--text-primary', [APP], 'page text'],
  ['--text-primary', [S], 'surface text'],
  ['--text-primary', [CARD], 'card text'],
  ['--text-primary', [CARD, '--color-crimson-bg'], 'absent card text'],
  ['--text-secondary', [S], 'banner description'],
  ['--text-secondary', [ELEV], 'tab / button text'],
  ['--text-secondary', [APP], 'text on app background'],
  ['--text-secondary', [CARD, '--color-crimson-bg'], 'absent card buttons'],
  ['--text-muted', [APP], 'text on app background (footer)'],
  ['--text-muted', [S], 'brand subtitle, KPI labels, footer'],
  ['--text-muted', [CARD], 'History link, student-sub'],
  ['--text-muted', [CARD, '--color-emerald-card-tint'], 'History link on recorded card'],
  ['--text-muted', [CARD, '--color-crimson-bg'], 'History link on absent card'],
  ['--text-muted', [ELEV], 'POINTS TODAY, mini-day rows, inspector labels, not-recorded badge'],
  ['--text-muted', [S, '--color-indigo-tint'], 'selected mini-day row'],
  ['--text-muted', ['--bg-surface-input'], 'search placeholder'],
  ['--text-muted', ['--badge-zero-bg'], 'zero badge'],
  ['--badge-zero-text', ['--badge-zero-bg'], 'Present 0 badge'],
  ['--badge-low-text', ['--badge-low-bg'], 'low badge'],
  ['--text-success', [CARD, '--color-emerald-bg'], 'Present badge'],
  ['--text-success', [S], 'Present ratio / mean'],
  ['--text-danger', [CARD, '--color-crimson-bg-strong'], 'Absent badge'],
  ['--text-danger', [S], 'absent count'],
  ['--text-danger', [ELEV], 'absent mini-day'],
  ['--text-warning', [S], 'saving status'],
  ['--text-warning', [S, '--color-amber-bg'], 'import WARNING pill, roster full badge'],
  ['--text-success', [S, '--color-emerald-bg'], 'import OK pill'],
  ['--text-danger', [S, '--color-crimson-bg-strong'], 'import ERROR pill'],
  ['--text-danger', [S, '--color-crimson-bg'], 'archive confirmation'],
  ['--text-primary', [S, '--color-crimson-bg'], 'archive confirmation text'],
  ['--text-muted', [S, '--color-crimson-bg'], 'archive confirmation'],
  ['--text-secondary', [S], 'form labels, table headers'],
  ['--text-muted', [ELEV], 'table note text'],
  ['--text-accent', [APP], 'error page code, login accent'],
  ['--text-secondary', [APP], 'login tagline'],
  ['--text-muted', ['--bg-surface-input'], 'input placeholder'],
  ['--text-accent', [S, '--color-indigo-tint'], 'Today / selected mini-day tag'],
  ['--text-accent', [S], 'hover link'],
  ['--text-accent', [CARD], 'hover link on card'],
  ['--on-accent', ['--color-indigo-solid'], 'active tab, primary button'],
  ['--on-accent', ['--color-indigo-solid-hover'], 'primary button hover'],
  ['--on-accent', ['--color-danger-solid'], 'Marked Absent, danger button'],
  ['--on-accent', ['--color-emerald-solid'], 'plus hover'],
  ['--primary-fg', ['--primary'], 'legacy primary button'],
  ['--success', ['--success-bg'], 'legacy success text'],
  ['--destructive', ['--destructive-bg'], 'legacy error text'],
  ['--heat-fg', ['--heat-low'], 'heat cells'],
  ['--heat-fg', ['--heat-mid'], 'heat cells'],
  ['--heat-fg', ['--heat-high'], 'heat cells'],
  ['--heat-fg', ['--heat-0'], 'heat cells'],
  ['--heat-absent-fg', ['--heat-absent'], 'heat absent cells'],
  ['--text-secondary', [APP, '--color-indigo-tint'], 'login hero footer'],
  ['--text-secondary', [S, '--glass-bg'], 'login "Remember my email" label on the glass card'],
  ['--text-muted', [S, '--glass-bg'], 'login "Remember my email" hint on the glass card'],
  // Weekly Matrix: sticky header, first column and footer sit on the opaque glass token.
  ['--text-primary', ['--glass-solid'], 'Weekly Matrix student name, weekday and footer numbers'],
  ['--text-secondary', ['--glass-solid'], 'Weekly Matrix column heads, tooltip text, footer label'],
  ['--text-muted', ['--glass-solid'], 'Weekly Matrix date under the weekday, student number, footer captions'],
  ['--text-accent', ['--glass-solid'], 'Weekly Matrix current weekday'],
  ['--text-primary', ['--glass-solid', '--hover-tint-soft'], 'Weekly Matrix hovered student cell'],
  ['--text-muted', ['--glass-solid', '--hover-tint-soft'], 'Weekly Matrix hovered student number'],
  ['--text-primary', ['--glass-solid', '--color-indigo-tint'], 'Weekly Matrix class average footer cell'],
  ['--text-muted', ['--glass-solid', '--color-indigo-tint'], 'Weekly Matrix class average footer caption'],
  ['--text-primary', ['--glass-solid', '--hover-tint'], 'Weekly Matrix average pill'],
  ['--text-danger', ['--glass-solid', '--color-crimson-bg-strong'], 'Weekly Matrix Absences badge'],
  ['--badge-zero-text', ['--glass-solid', '--badge-zero-bg'], 'Weekly Matrix 0 absences badge'],
  ['--text-muted', ['--glass-solid', '--badge-zero-bg'], 'Weekly Matrix Archived badge'],
  ['--text-muted', ['--glass-solid'], 'Weekly Matrix not recorded dash'],
  ['--text-danger', ['--glass-bg-strong'], 'Quick editor error line'],
  ['--text-success', [APP, '--input-bg'], 'Copy Summary "Copied" state'],
  ['--text-secondary', [APP, '--badge-zero-bg'], 'Showing n active students counter'],
  ['--text-primary', [APP, '--badge-zero-bg'], 'Showing n active students number'],
  ['--text-muted', [APP, '--input-bg'], 'Week navigator term label and Sort eyebrow'],
  ['--text-secondary', [APP, '--input-bg'], 'Class chip title'],
  ['--text-danger', [APP, '--color-crimson-bg-strong'], 'Sub-zero comparison on KPI card'],
  // Weekly chips and legend swatches on the opaque sticky surface and the plain page background.
  ...['0', 'low', 'mid', 'high', 'absent'].flatMap((k) => [
    [`--chip-${k}-text`, ['--glass-solid', `--chip-${k}-bg`], `Weekly chip ${k} on the opaque matrix surface`],
    [`--chip-${k}-text`, [APP, '--glass-bg', `--chip-${k}-bg`], `Weekly legend swatch ${k} on the info strip`],
  ]),
];
// Non-text UI: borders that identify controls need 3:1.
const UI = [
  ['--border-strong', [S], 'input and control borders'],
  ['--border-strong', [APP], 'control borders on the page'],
  ['--border-strong', [...GLASS('--glow-violet-soft'), '--input-bg'], 'input borders on glass'],
  ['--border-focus', [APP], 'focus ring on the page'],
  ['--border-focus', GLASS('--glow-violet-soft'), 'focus ring on glass'],
  ['--border-focus', [S, '--glass-bg'], 'focus ring on the login card (Remember my email checkbox)'],
  ['--text-accent', [S, '--glass-bg'], 'ticked Remember my email checkbox against the login card'],
  ['--primary', [APP], 'global focus outline on the page'],
  ['--primary', GLASS('--glow-violet-soft'), 'global focus outline on glass'],
  ['--text-accent', GLASS('--glow-violet-soft'), 'violet icon on glass'],
  ['--text-success', GLASS('--glow-green-soft'), 'green icon on glass'],
  ['--text-info', GLASS('--glow-blue-soft'), 'blue icon on glass'],
  ['--text-secondary', [...GLASS('--glow-violet-soft'), '--input-bg'], 'Account: show/hide password icon inside the field'],
  ['--text-primary', [...GLASS('--glow-violet-soft'), '--input-bg', '--hover-tint'], 'Account: hovered show/hide password icon'],
  ['--border-focus', [...GLASS('--glow-violet-soft'), '--input-bg'], 'Account: focus ring of the show/hide button over the field'],
  ['--color-danger-solid', [...GLASS('--glow-violet-soft'), '--input-bg'], 'Account: border of an invalid field'],
];

const sets = { light, dark, 'OS dark': osDark };

for (const [name, tokens] of Object.entries(sets)) {
  test(`${name}: normal text pairs meet WCAG AA 4.5:1`, () => {
    const failures = [];
    for (const [fg, layers, use] of TEXT) {
      const r = ratio(flatten(tokens, [fg]), flatten(tokens, layers));
      if (r < 4.5) failures.push(`${fg} on ${layers.join(' + ')} (${use}) = ${r.toFixed(2)}`);
    }
    assert.deepEqual(failures, []);
  });

  test(`${name}: control borders meet 3:1 non-text contrast`, () => {
    const failures = [];
    for (const [fg, layers, use] of UI) {
      const r = ratio(flatten(tokens, [fg]), flatten(tokens, layers));
      if (r < 3) failures.push(`${fg} on ${layers.join(' + ')} (${use}) = ${r.toFixed(2)}`);
    }
    assert.deepEqual(failures, []);
  });
}

test('the OS dark set carries the same values as the explicit dark set', () => {
  for (const key of Object.keys(dark)) {
    if (key in light && dark[key] === light[key]) continue;
    assert.equal(osDark[key], dark[key], key);
  }
});
