# Browser compatibility notes

Status on 2026-09-30. Read this before telling the owner that "it works in Safari".

## Tested in a real browser

| What | How | Result |
|---|---|---|
| Google Chrome desktop (the installed version), headless | `node scripts/dev/keyboard-audit.mjs` drives `/Applications/Google Chrome.app` through the Chrome DevTools Protocol with real key events: Tab sweep and every dialog, menu and sheet, on Login, Daily, Weekly, Semester, Roster, Import and Student history, dark and light, at 1287 px and 375 px (emulated viewport) | Passing at the last run (`docs/keyboard-audit.md`) |
| Chrome headless, print | `node scripts/dev/print-pdfs.mjs` (`Page.printToPDF`, print media) for Daily, Weekly, Semester and Roster, edge check at 300 dpi | Passing; PDFs reviewed as images |
| Chrome headless, screenshots | `node scripts/dev/capture-panels.mjs` (full panels, dark and light) and the Chrome pane used during the design review | Reviewed visually |
| Server and logic | PHPUnit (feature and unit tests, including the dialog and keyboard markup tests in `tests/Feature/KeyboardFocusTest.php`) and `node --test` for the JS helpers | Passing. These are not browser tests |

## Not tested (no browser, device or printer available)

| What | Why |
|---|---|
| Safari on macOS | Safari.app exists on this Mac, but `screencapture` is refused by macOS (no Screen Recording permission, "could not create image from display") and automation (`safaridriver --enable`, Develop menu, "Allow Remote Automation") was deliberately not enabled. Opening the page in Safari was possible but nothing could be observed, so nothing is claimed |
| Firefox (ESR 115 and current) | Not installed on this Mac; nothing was installed |
| iPhone and iPad Safari, Chrome on iOS | No device or simulator session |
| Android Chrome and Samsung Internet | No device |
| Real printers and printer drivers | Only Chrome's PDF output was checked. Margins (Letter, 14 mm / 12 mm) may differ slightly on a real printer |
| Hostinger itself (HTTPS, LiteSpeed, headers) | Nothing was uploaded; see `docs/hosting-requirements.md` |

## Static analysis findings (NOT a browser test)

Found by grepping `public/css`, `public/js` and `resources/views`. Versions are from general knowledge of browser support
tables; anything marked **verify** should be confirmed on caniuse.com or MDN before relying on it.

| Feature | Where | Safari (macOS / iOS) | Firefox | Verdict |
|---|---|---|---|---|
| `backdrop-filter` | `shell.css`, `glass.css`, `daily.css`, `roster.css` (12 uses) | needs `-webkit-backdrop-filter` until Safari 18; supported since 9 prefixed | 103 (ESR 115 ok) | Every unprefixed use already has the `-webkit-` line next to it; `glass.css` has an `@supports not (...)` opaque fallback. No defect |
| `@supports` | `glass.css:95` | 9 | 22 | OK |
| `inset` shorthand | `daily.css:330`, `glass.css:30,601` | 14.1 | 66 | OK (iOS 14.0 and older lose a focus-ring hit area only) |
| `:focus-visible` | `app.css:26` and others (20 uses) | 15.4 | 85 | Needs Safari/iOS 15.4 or newer for the keyboard focus ring (older: rule ignored). Minimum supported: 15.4 |
| `<dialog>` + `showModal()` + `::backdrop` | Daily, Semester, Roster, `dialogs.js` | 15.4 | 98 (ESR 115 ok) | Needs Safari/iOS 15.4 or newer. Only `roster.js` has a no-`showModal` fallback; the other dialogs do not. Minimum supported: 15.4 |
| `100dvh` / `75dvh` / `78dvh` | `semester.css:279,331`, `daily.css:736` | 15.4 | 101 | Each one has a `vh` line before it as fallback. OK |
| `scrollbar-width: thin` | `daily.css:768`, `semester.css:118,211` | 18.2 (ignored before) | 64 | Cosmetic only; older Safari shows the normal scrollbar |
| `overscroll-behavior` | 6 uses | 16 (**verify**; ignored before) | 59 | Progressive enhancement only |
| `accent-color` | `screens.css:370` | 15.4 | 92 | Cosmetic (default checkbox colour before) |
| `env(safe-area-inset-bottom)` | `weekly.css:287` | 11.2 | not applicable | OK |
| `min()` | 7 uses | 11.1 | 75 | OK |
| Flexbox/grid `gap` | 198 uses | 14.1 | 63 | OK. iOS 14.0 and older would lose spacing. Minimum supported: 15.4 anyway |
| `-webkit-line-clamp` with `-webkit-box` | `roster.css:181` | yes | 68 | OK |
| `:not(...)`, `:focus-within`, `position: sticky` | several | 10.1 to 13 | 52 and later | OK |
| `prefers-reduced-motion`, `prefers-color-scheme`, `color-scheme` | several | 10.1 / 12.1 / 13 | 63 / 67 / 96 | OK |
| `prefers-reduced-transparency`, `forced-colors` | `glass.css:119`, 4 uses | **verify** (not relied upon) | **verify** | An unknown media query simply does not match; no defect |
| `print-color-adjust` with `-webkit-` prefix | `print.css` | prefix 6, standard 15.4 | 97 | Both written. OK |
| `break-inside`, `page-break-*`, `@page` | print CSS | `break-inside` 10 (**verify** in print); `@page` size and margins are supported | 65 | Not testable here; see the printer row above |
| `scroll-padding`, `scroll-behavior` | several | 14.1 / 15.4 | 68 / 36 | OK |
| `Intl.DateTimeFormat` with `timeZone`, `hour12`, `en-CA` / `en-GB` | `shell.js:101`, `daily-notes.js:45` | 10 | 52 | Supported. The exact text of the time (for example "a.m." versus "AM" in `en-CA`) differs between engines: **verify** on Safari and Firefox |
| `Date.prototype.toLocaleString('en-CA', { timeZone })` | `semester-lib.js:320` | 10 | 52 | Same remark as above |
| `navigator.clipboard.writeText` | `weekly.js:700`, `semester.js:91` | 13.1, needs HTTPS and a user gesture | 63 | Both places fall back to a hidden-textarea copy. OK |
| `crypto.randomUUID()` | `daily.js`, `weekly.js` | 15.4, HTTPS only | 95 | `weekly.js` already had a `getRandomValues` fallback; **`daily.js` did not and was fixed in this release** (see below) |
| `fetch`, `AbortController`, `Array.from`, `padStart`, `matchMedia(...).addEventListener` | JS | 11.1 / 14 (guarded in `theme.js`) | 57 | OK |
| `<input type="date">` | `semester.blade.php:345` | desktop 14.1, iOS yes | yes (date picker since 57) | OK |
| Not used anywhere | `:has()`, container queries, CSS nesting, `color-mix()`, `@layer`, `?.` and `??`, `replaceAll`, `.at()`, `structuredClone`, top-level `await`, ES modules, private class fields, regex lookbehind | - | - | Nothing to fix |

Safest summary: the app should work on Safari/iOS 15.4 or newer, Firefox ESR 115 and current Chrome/Edge. Older Safari
(15.3 and below) is not supported because of `<dialog>` and `:focus-visible`.

### Fix made in this release

`public/js/daily.js`: the save queue called `crypto.randomUUID()` with no fallback. It is undefined on iOS/Safari older
than 15.4 and on any non-HTTPS page, which would stop every point from saving there. It now falls back to
`crypto.getRandomValues` exactly as `weekly.js` already did. No behaviour changed where `randomUUID` exists.

## Manual checklist for the owner (10 steps, Safari, Firefox and iPhone)

Use the real site after publishing, with a practice class (a fake class name and fake students), never real data for the
first run. Repeat on each browser and write "OK" or what you saw.

1. Open `https://your-domain/login`: the page is readable, the logo and fonts appear, and the address bar shows the lock.
2. Sign in: you land on the Daily screen. Reload the page: you stay signed in.
3. Create a practice class with three fake students (Roster). Try the dark and the light theme toggle.
4. On Daily, tap a card's plus three times, then minus once: the number changes at once and is still right after a reload.
5. Mark one student Absent and back to Present: the status text changes (not only the colour).
6. Open a student's details panel and the dialogs (add note, reset day): the window opens, Esc or Cancel closes it, and the page behind does not scroll (iPhone: swipe does not move the page).
7. Weekly: the matrix shows the points; press Copy Summary and paste it into a note: the text arrives.
8. Semester: the heat map and the Report Card Comments dialog open; edit a draft, close, reopen: the text is kept.
9. Print each screen (Daily, Weekly, Semester, Roster) with the browser's print preview: Letter, portrait, nothing cut off at the right edge, no sticky bars in the way.
10. iPhone only: turn the phone sideways and back, then use the keyboard "Done" button in a text field: the layout does not jump and the buttons stay at least finger sized. Finally log out and check that the Back button does not show private data.
