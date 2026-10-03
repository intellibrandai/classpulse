# Keyboard audit and Roster PDF edge check

Dev documentation (English). Tool: `node scripts/dev/keyboard-audit.mjs` (see `docs/dev-tools.md`). Not shipped in the
release zips (`docs/` and `scripts/` are in `scripts/release-exclude.txt`).

## What was tested

The real app (http://localhost:8090, local account) driven in headless Chrome with **real key events**
(`Input.dispatchKeyEvent`: Tab, Shift+Tab, Enter, Space, Escape, arrows, typed text), never `element.click()`.
Matrix: 8 screens (Login, Daily, Weekly, Semester, Roster, Import upload, Import preview, Student history) x dark and
light x 1287 px and 375 px = **32 Tab sweeps**, plus **27 keyboard flows** x the same four combinations = **108 flow
runs**. Sweeps read the real class `HLS 3O` only; flows that write use the temporary scratch class
`ZZ Demo (temp)` (created through the roster forms, deleted at the end with "Delete this class"). The dev database ended
with 3 classes and 51 students.

Each Tab sweep measures, for every focusable control (Daily 103-104 stops, Weekly 86-88, Semester 58-59, Roster 68-70,
Import upload 15-17, preview 6, Student history 14-16, Login 3):

- (a) the first Tab stop is the skip link, it is visible on focus, Enter goes to `#main` and the next Tab lands inside `main`;
- (b) Tab never gets trapped, Tab order against visual order, no positive `tabindex`;
- (c) a visible focus indicator: computed outline / ring / glow / border / background of the focused control (and of up to
  three ancestors, for `:focus-within` rings) against the same control unfocused, at least 3:1 against its surroundings,
  not clipped by an `overflow` ancestor or the viewport, and not covered by the sticky header, footer or sticky table rows.

The flows check (d) open from the keyboard, Tab and Shift+Tab stay inside the open dialog / popover / sheet, Escape closes,
focus returns to the opener; (e) focus after saving is not dropped to `<body>`; (f) `aria-expanded`, `aria-pressed`,
`aria-invalid`, `aria-current` and status text update.

## Result (after the fixes)

32 sweeps: 0 focus-indicator offenders, 0 covered or clipped rings, 0 invisible stops, 0 traps, skip link OK on every
screen that has one. 108 flow runs: 108 pass (re-run 2026-09-30 after the class selector became an accessible menu). Table (same result in dark and light, 1287 px and 375 px unless noted):

| Screen | (a) skip link / first stop | (b) order, no trap | (c) indicator >= 3:1, visible, not covered | (d) dialogs, menus, sheets | (e) focus after save | (f) aria states |
|---|---|---|---|---|---|---|
| Login | no skip link (single form, autofocus on e-mail) | OK | OK | n/a | n/a | `aria-invalid` via server error text |
| Daily | OK | OK | OK | account menu, class menu (`<details>`: Enter / Space / ArrowDown open, focus on the current class, Home / End / arrows, Tab out closes, Enter on another class navigates, Escape refocuses the pill) and the Semester course chip, + Student dialog, Day Slip (`print` stubbed), More day actions menu, Mark remaining / Reset Day dialogs (Escape, Cancel, confirm), filter chips, card select, details panel and bottom sheet (375 px) | note save, card buttons (-, filters, Undo), add student | `aria-expanded`, `aria-pressed` (chips and card select), `aria-invalid` |
| Weekly | OK | OK | OK | cell quick editor (Enter/Space, edit, Set, Escape, Close, trap), info tooltips (focus shows, Escape hides), Copy Summary | editor returns focus to the cell | `aria-haspopup`, live status |
| Semester | OK | OK (one documented exception below) | OK | period links, Inspect + inspector (sheet at 375 px), note add / edit / delete confirm, Report Card Comments (edit, Reset, Copy, Escape, Close, X), Show more | note save / delete, draft Reset | `aria-pressed` on Inspect, `aria-expanded` on the toggle, `aria-current` |
| Roster | OK | OK | OK | class cards, create-class form, settings accordions (Enter/Space), edit-student dialog (Escape, Cancel, Save), archive confirm + Restore | Save class details, edit, archive (focus lands on the status message) | `details/summary` state, `aria-current` |
| Import upload + preview | OK | OK | OK | paste list + Preview by keyboard, row checkboxes (Space), table region | n/a | checkbox state |
| Student history | OK | OK | OK | back link, focusable scroll regions | n/a | n/a |

## Defects found and fixed

Evidence: the same tool run on the unfixed code (dark, both widths) reported 12 of 16 sweeps with findings and 19 of 48
flow runs failing. All fixes are minimal and keep the visual design.

| # | Defect (screen) | Fix |
|---|---|---|
| 1 | The skip link was painted under the sticky header when focused (every screen): z-index 100 = header | `public/css/app.css`: `.skip-link { z-index: 200 }` |
| 2 | Focused controls scrolled under the sticky footer / header and the sticky totals row of the matrix (Daily cards, Weekly rows, Semester rows, Roster rows, Import buttons) | `app.css` `html { scroll-padding-top/bottom }`, `weekly.css` and `semester.css` `scroll-padding-block`, `roster.css` `scroll-margin-bottom` for the fields above the sticky save bar |
| 3 | Focus ring cut off by clipping scrollers: Daily filter chips and Roster class cards at 375 px, Semester period row and last table row, Weekly last row | room inside the scroller with the same net layout (`padding` + negative `margin`), `padding-bottom` where the scroller ends |
| 4 | Chrome did not scroll a partly visible chip / class card into view on Tab (ring cut off, control half hidden) | new `public/js/focus-scroll.js` (loaded by the layout; scroll-snap aware), unit test `tests/js/focus-scroll.test.js` |
| 5 | Daily: Mark remaining as 0 / Reset Day dialogs did not return focus to More day actions (the menu closes, so the native restore failed) | `daily.js` returns focus to the menu button after the dialog closes |
| 6 | Daily: focus fell to `<body>` when "-" became disabled at 0, when a card left the active filter, when Undo became disabled | `daily.js` `rescueFocus()` |
| 7 | Daily: the note Save button disables itself after saving, focus fell to `<body>` | focus moves to the note text |
| 8 | Daily: Escape cleared the selection while focus was in the panel, focus fell to `<body>` | focus returns to the selected card's name button |
| 9 | Daily: the card name button (select) had no pressed state | `aria-pressed` (markup and `selectStudent`) |
| 10 | Daily details bottom sheet and Semester inspector sheet (375 px) did not trap Tab, so focus went to controls hidden under the sheet; Semester sheet opened with the toggle did not return focus on Escape | `dialogs.js` `trapTab()` / `trapIndex()` (unit test `tests/js/dialogs.test.js`), `semester.js` returns focus to the toggle |
| 11 | Semester Report Card Comments: "Reset to template" disabled itself while the edit was being saved (the save starts when focus moves to the button), so the click or Enter was swallowed; Reset / Retry also dropped focus when they switched themselves off | `semester-lib.js` `resetDisabled()` (unit test), `semester.js` keeps focus in the draft |
| 12 | Semester "Show more" hid itself under focus | focus goes to the first revealed row |
| 13 | Roster: closing the create-class form from its summary hid the whole form (CSS) and dropped focus | focus goes to the "Create New Class" card |
| 14 | After a save that reloads the page (roster, Daily add student) focus started at the top of the document | `shell.js` focuses the status message |
| 15 | Semester toolbar: tab order did not match the visual order (period row before the action buttons) | markup order changed; CSS `order` keeps the layout identical |

Guards: `tests/Feature/KeyboardFocusTest.php` (markup and key CSS / JS rules), `tests/js/dialogs.test.js`,
`tests/js/focus-scroll.test.js`, `tests/js/semester-lib.test.js`. The contrast tests are untouched and green.

## Remaining, accepted and not verifiable

- **Header order (fixed 2026-10-01).** The header is now a CSS grid whose DOM order equals the visual reading order at every
  width (row 1 logo, class menu, "+"; row 2 the four tabs, then theme, Undo, account), so Tab order = reading order on phones
  too. `node scripts/dev/header-sweep.mjs` checks it at every width (see `docs/header-sweep.md`).
- Native `<select>` popups (Weekly sort, Roster sort) are not opened by synthetic keys in headless Chrome. Focus, label and the
  change handler (type-ahead) are verified; the popup itself was not. The class selector is no longer a `<select>`: it is a
  `<details>`/`<summary>` menu (`<x-class-menu>`), so its popup IS driven with real keys (flows `class-selector` and
  `class-chip-semester`) and with real mouse clicks (`scripts/dev/class-switch-check.mjs`).
- The file input of the Import upload page cannot be opened by a key press headlessly; pasting a list and previewing it is
  covered.
- Day Slip and Print buttons were checked up to the `window.print()` call only (stubbed).
- Screen-reader announcements (live regions) were checked as text content, not with a screen reader.

## Roster PDF right edge (Task 2)

Measured with `node scripts/dev/print-pdfs.mjs` (Letter, `@page` 14 mm / 12 mm) on `HLS 3O` and on a scratch FULL roster
(`--full-roster`: 30 students, long names, long preferred names, 5-line observations; 5 pages), at 300 dpi
(`pdftoppm`) plus `pdftotext -bbox` word boxes, and looked at with crops of the right edge, the last column, the page-2
header and the top corners.

| Item | Before | After |
|---|---|---|
| Roster table right border (full roster 5 pages, HLS 3O 1 page) | **1 px = 0.24 pt** (inner lines 3-4 px): outer half cut off at the margin | 3 px on every page |
| Weekly matrix right border | 1 px (hidden by a second frame, below) | 3 px |
| Weekly matrix | a rounded second outline 2 px outside the square table border (print.css `.glass` border won a tie) | single square border |
| Roster "ORDER" heading | touched the cell border (38 pt column) | 46 pt column, clear of the border |
| Semester, Day Slip right border | 3 px | 3 px (unchanged) |
| Ink inside the right 12 mm margin | 0 px | 0 px; ink ends 0.473-0.480 in from the page edge (margin 0.472 in) |
| Word boxes beyond the right margin | max word xMax 578.2 pt (limit 578.0: the "Printed on ... p.m." advance width, 0.2 pt, no ink) | same (font metrics, not clipping) |
| Ink left of the left margin | 0.17 mm (half of a collapsed border) | 0.08-0.17 mm, unchanged by design |
| Page-2+ header repetition | repeats (verified for roster, weekly, semester) | repeats |

Cause: `glass.css` (`.ui-table { width: 100% }`) loads after `roster.css`, so the roster's `width: calc(100% - 1pt)` inset never
applied, the table sat flush with the margin and Chrome drew only the inner half of the outer border. Fix: a two-class
selector `.ui-table.ro-table` in `public/css/roster.css`, the same inset on `.wm-table` in `public/css/weekly.css`,
`.wm-scroll.glass` border and radius removed in print. Guards: `tests/Feature/PrintStylesGuardTest.php` (new
`test_roster_print_table_keeps_its_right_border_inside_the_page_margin` and Weekly asserts) and the **automatic edge
check** that `print-pdfs.mjs` now runs after every PDF (`scripts/dev/lib/pdf-edge.mjs`; exits 1 on a word or ink outside
the margins or a 1 px right border). Final state: all 12 PDFs (6 for HLS 3O, 6 for the full roster) pass the edge check.
The scratch class was deleted afterwards.

## Account settings (added with the Account group)

New flows: `account-via-menu` (avatar menu > Account settings by Enter/Space, from any page and in place on the Roster page; focus lands on the group heading), `account-fields-toggles` (opens from `#account`, Tab order email, password + show/hide, Change email, the three password fields with their toggles, Change password; Space and Enter on a toggle; Enter submits an empty form, the group stays open and focus moves to the first invalid field, whose error is `role="alert"` and linked with `aria-describedby`) and an extended `account-menu` (Tab from Account settings to Log out, then out of the menu). The flows never change credentials. Result: 32 sweeps (0 with findings), 116 flow runs (0 failing).

Defects fixed on the way: focus set while the page loads at `#account` was undone by the browser's scroll-to-fragment step (account.js now focuses after `load`); and a Weekly Matrix cell scrolled under the sticky student column or its ring clipped at the right edge (a date-dependent finding, unrelated to Account: `public/js/focus-scroll.js` now accounts for a sticky first column, `weekly.css` adds right scroll padding).
