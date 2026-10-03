# Dev-only tools: print PDFs, full-panel captures, the header sweep and the keyboard audit

The scripts live in `scripts/dev/`, use only Node 24 built-ins (no npm packages) and drive the host's Google Chrome
(`/Applications/Google Chrome.app`, headless, temporary profile) through the Chrome DevTools Protocol. They need the
local app running (`docker compose up -d`, http://localhost:8090; set `CLASSPULSE_URL` when you changed the port) and log in as `teacher@classpulse.test` with the
password on the `Password:` line of `storage/app/local-test-login.txt` (never printed). `scripts/` and `docs/` are in
`scripts/release-exclude.txt`, so none of this ships in the Hostinger zips (`./scripts/verify-release.sh` proves it).

## `node scripts/dev/print-pdfs.mjs`

Prints the four print layouts to Letter PDFs with `Emulation.setEmulatedMedia print` + `Page.printToPDF`
(`preferCSSPageSize`, backgrounds on) into `storage/app/print-check/` (git-ignored):
`<class>-daily-day-slip.pdf` (+ `-student-selected`), `<class>-weekly.pdf`, `<class>-semester-full.pdf`
(+ `-student-selected`, which adds the student's audit log and notes) and `<class>-roster.pdf`.
It is read-only (it only selects a student on screen). Flags: `--class-name "HLS 3O"` (default), `--student-name`,
`--date 2026-09-30`, `--week 2026-09-21`, `--out`. Review with `pdftoppm -r 80 -png file.pdf out` and `pdftotext -layout`.
All four documents are portrait Letter; the page rules live in `public/css/print.css` and the page CSS files.

`--full-roster` creates the TEMPORARY scratch class `ZZ Full (temp)` (30 students with long names, long preferred names
and long observations, so the roster spans several pages), prints everything for it and deletes it again through the
roster page's "Delete this class" form (`--keep` leaves it). After every PDF the script runs an **edge check at 300 dpi**
(`scripts/dev/lib/pdf-edge.mjs`, poppler `pdftotext -bbox` + `pdftoppm`): words and ink must stay inside the `@page`
margins (14 mm top/bottom, 12 mm left/right) and a table's right border must not be a 1 px hairline (its outer half cut
off at the margin). It prints `EDGE PROBLEM: ...` lines and exits 1 when something fails.

## `node scripts/dev/capture-panels.mjs`

Saves PNGs into `storage/app/capture-full/` (git-ignored) at 1287 px wide, dark and light: the complete Daily student
panel, the complete Semester inspector, the Report Card Comments dialog at full height, and full-page Daily, Weekly,
Semester and Roster. When the class does not exist it first creates a TEMPORARY scratch class `ZZ Demo (temp)` with
fictitious students, six weeks of records, notes and two report-comment drafts, and deletes it at the end through the
roster page's own "Delete this class" form. Flags: `--class-name`, `--student-name`, `--out`, `--keep` (leave the scratch
class), `--delete-after`, `--no-seed`, `--cleanup` (only delete the scratch class), `--width`, `--date`, `--week`.
Only classes whose name starts with `ZZ ` can be created or deleted by the script; the real classes are never changed.

## `node scripts/dev/header-sweep.mjs`

Measures the app header with real layout (read-only, logs in without printing the password). For Daily, Weekly, Semester
and Roster, in light and dark, at the 22 named widths (320 ... 2200) plus every 20 px from 1000 to 1500, and at browser
zoom 125% / 150% (emulated: CSS viewport = width / zoom, `deviceScaleFactor` = zoom), it fails on: overlapping header
controls, a control hidden (only the chips and Today may collapse), outside the viewport or clipped by an ancestor,
horizontal page scroll, a header taller than 120 px (2 rows), a truncated tab or brand label, and a Tab order that differs
from the visual order (DOM order vs (row, x) order). It also checks the **click targets** (`document.elementFromPoint` over a 5x3 grid of
the class pill, plus its eyebrow, dot, name and chevron, and on Semester the course chip, must resolve to the `<summary>` or a
descendant) and the **opened class menu** (popup inside the viewport, unclipped, above the header and page content, no sideways
scroll). Prints a pass/fail table and exits 1 on any failure.
Flags: `--pages`, `--themes`, `--zoom 1,1.25,1.5`, `--widths 320,360,...`, `--step 5` (every 5 px from 320 to 2200, for break-point
hunting), `--long-name` (swaps the current class name in the pill for a 60-character name in the DOM only), `--quick`, `--verbose`,
`--json <file>`, `--report docs/header-sweep.md` (writes the short report). `SWEEP_DUMP=1` adds every control's box to the JSON.
The break points it was tuned with are documented at the top of the "ADAPTIVE HEADER" block in `public/css/shell.css`.

## `node scripts/dev/class-switch-check.mjs`

Proves the class menu with **real mouse events** (`Input.dispatchMouseEvent`) and real keys, read-only. For Daily, Weekly,
Semester and Roster at 1287 px (dark) it clicks over the class name, the dot, the chevron and the four edges of the pill (each
click opens the menu, a second one closes it), checks that the popup lists every class with the current one marked
(`aria-current="true"` + check icon, focus on it), that Escape closes and refocuses the pill and an outside click closes it,
then clicks each other class and records header chips, KPIs and first student names per class (they must differ; back on the
first class they must match the first reading). Also the "Active" eyebrow (1440 px), the Semester course chip, and screenshots
of the open popup at 1100 / 768 / 375 / 320 px into `storage/app/final-v5/` (git-ignored, `--out` to change).
Flags: `--out`, `--json <file>` (the recorded values). Exit code 1 on any failed check.

## `node scripts/dev/capture-panels.mjs --header`

Screenshots only the header (clip of the top bar, deviceScaleFactor 1, plus the top 240 px of the page) of a screen at chosen
widths and themes: `--header --screens weekly,daily --widths 1100,1280 --themes dark --zoom 1.25 --out storage/app/final-v4`.
Login page: `--login`. See the script header for every flag.

## `node scripts/dev/keyboard-audit.mjs`

Drives the real app in headless Chrome with **real key events** (`Input.dispatchKeyEvent`: Tab, Shift+Tab, Enter, Space,
Escape, arrows, typed text) and writes nothing unless `--json <file>` is given. Two parts, per screen (Login, Daily,
Weekly, Semester, Roster, Import upload and preview, Student history), dark and light, at 1287 px and 375 px:

1. **Tab sweep**: presses Tab through the whole page and checks the skip link, that Tab never gets trapped, the Tab order
   against the visual order, and, for every focusable control, that the focus indicator exists (outline, ring, glow,
   border or background change measured from computed styles, compared with the unfocused style of the same control),
   reaches 3:1 against its surroundings, is not cut off by an `overflow` ancestor or the viewport, and is not covered by
   the sticky header or footer (`elementFromPoint`).
2. **Flows** (`scripts/dev/lib/kb-flows.mjs`): every dialog, popover, menu and sheet opened with Enter/Space, Tab trap,
   Escape and focus return, `aria-expanded` / `aria-pressed` states, note and draft saves, and the focus after a
   save. Controls are reached with `element.focus()` as a shortcut; every action under test is a key press.

Flows that write use the TEMPORARY scratch class `ZZ Demo (temp)` (created through the roster forms, deleted at the end
with "Delete this class"; `--keep` leaves it for re-runs, remove it with `node scripts/dev/capture-panels.mjs --cleanup`).
The real classes are only read. Flags: `--class-name` (class for read-only sweeps, default `HLS 3O`), `--theme dark|light`,
`--width 1287|375`, `--only <screen or flow ids>`, `--no-sweep`, `--no-flows`, `--json <file>`, `--keep`, `--verbose`,
`--keep-blur`. Exit code 1 when anything fails. The last result and the list of fixes are in `docs/keyboard-audit.md`.

Headless-Chrome quirks the tool works around: `Emulation.setFocusEmulationEnabled` wedges the browser when keys are sent
to an open modal dialog (the page is focused with `Page.bringToFront` instead); a Windows key code sent as
`nativeVirtualKeyCode` is read as a macOS key code and floods the page with bogus keydown events (only
`windowsVirtualKeyCode` is sent); native `<select>` popups (Weekly and Roster sort) are not driven by synthetic keys (type-ahead is); the class menu is not a `<select>` any more and is driven with real keys; the login
route allows 5 attempts per minute, so do not start the tool more often than that.

## `node scripts/dev/account-check.mjs`

End-to-end proof of **Account settings** (change email / change password) against the LOCAL app, with REAL mouse clicks and key
events in three separate headless Chrome profiles. Flags: `--no-shots`, `--shots-dir storage/app/final-v6` (default, git-ignored).

It creates a scratch user (`ZZ scratch`, `zz-scratch-<random>@example.test`) through `php artisan tinker` fed over stdin; every
password is random, lives only in memory, is never printed and never written to disk. It never touches `teacher@classpulse.test`, the
classes, students, notes or drafts. What it proves (about 80 PASS lines): wrong current password blocks both forms and the
credentials still work; email change keeps the session, old email stops working, new one works; the 5-failures-per-minute lock (429,
even with the right password); duplicate emails incl. the upper-case variant; the 11/12 character boundary; mismatch and
same-as-current; the remembered email is replaced only when it held the OLD email and nothing is stored when "Remember my email" was off;
a second browser's session is signed out after the password change and the `sessions` rows are gone; the old password is rejected at
`/login`; show/hide toggles (mouse, Space, Enter); a keyboard-only run (avatar menu, Tab order, Enter submits, focus on the first
error or on the success message); no password in cookies, localStorage, sessionStorage, URL or page HTML. At the end it deletes the
scratch user and compares the dev database with the start (1 user, 3 classes, 51 students, 0 notes, 0 drafts; the teacher's stored hash
is compared as a SHA-256, never printed). It clears the file cache (`artisan cache:clear`, dev only) to reset the rate limiter.
Screenshots: `account-<state>-<width>-<dark|light>.png` (avatar menu, group open, errors, success, toggles, login after a password change, 375/768/1287).

The keyboard audit has two new flows (`account-via-menu`, `account-fields-toggles`; the old `account-menu` flow now also Tabs from
Account settings to Log out). They never change credentials: the submit they make is an empty form, which only produces errors.
`node scripts/dev/keyboard-audit.mjs` and `node scripts/dev/header-sweep.mjs` stay at 0 findings.

## Update kit for an installed site

`./scripts/package-update.sh` builds `dist/update/` (never uploads anything) and `CLASSPULSE_PREVIOUS_DIR=<folder with the previous full
package> ./scripts/verify-update.sh` proves it, including an overlay of the kit over a copy of the previous package. Guide for the owner:
`docs/hostinger-update.md` (copied into the kit as `LEEME-ACTUALIZAR-HOSTINGER.md`).
