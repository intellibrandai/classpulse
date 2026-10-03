# Header sweep

Dev report (English). Tool: `node scripts/dev/header-sweep.mjs --report docs/header-sweep.md` (see `docs/dev-tools.md`). Not shipped in the release zips.

Real layout measured in headless Chrome against the local app (read-only). 952 measurements: Daily, Weekly, Semester and Roster, light and dark, 
42 widths (320 to 2200 px: the 22 named widths plus every 20 px from 1000 to 1500), each at 100%, 125% and 150% browser zoom 
(emulated: CSS viewport = width / zoom with deviceScaleFactor = zoom; combinations under 320 CSS px are skipped).

## Checks (each one is a failure when violated)

1. No two header controls overlap (brand, class menu, "+", four tabs, chips, theme switch, Undo, Today, avatar).
2. Brand, class menu, "+", the four tabs, theme switch, Undo and avatar are always visible (only the chips and Today may collapse), inside the viewport, inside the header and not clipped by any `overflow` ancestor.
3. `document.documentElement.scrollWidth <= clientWidth` (no horizontal page scroll) and the body is not wider than the viewport.
4. The header is at most two rows (height <= 120px).
5. No visible tab label, brand text, Undo label or Today text is truncated (`scrollWidth <= clientWidth`).
6. Tab order equals visual order: the focusable header controls in DOM order equal the same controls sorted by (row, x).
7. Click targets: `document.elementFromPoint` over a 5x3 grid of the class pill (and over its eyebrow, dot, name and chevron; on Semester also the course chip) always resolves to the `<summary>` or one of its descendants, never to another element, and the summary is at least 38px high.
8. Opened class menu (header, and the Semester chip): the popup is fully inside the viewport, at most `min(360px, 100vw - 24px)` wide, below its pill, not clipped by any `overflow` ancestor, above the header, sticky bars, footer and page content (`elementFromPoint` at six points of the panel resolves to the panel), every item is at least 40px high, and the page does not scroll sideways.

## Result

**PASS: 952 of 952 measurements, 0 failures.**

| Page | Theme | Zoom | Widths checked | Pass | Fail | Failing widths (CSS px) |
|---|---|---|---|---|---|---|
| daily | light | 100% | 42 | 42 | 0 | - |
| daily | light | 125% | 39 | 39 | 0 | - |
| daily | light | 150% | 38 | 38 | 0 | - |
| daily | dark | 100% | 42 | 42 | 0 | - |
| daily | dark | 125% | 39 | 39 | 0 | - |
| daily | dark | 150% | 38 | 38 | 0 | - |
| weekly | light | 100% | 42 | 42 | 0 | - |
| weekly | light | 125% | 39 | 39 | 0 | - |
| weekly | light | 150% | 38 | 38 | 0 | - |
| weekly | dark | 100% | 42 | 42 | 0 | - |
| weekly | dark | 125% | 39 | 39 | 0 | - |
| weekly | dark | 150% | 38 | 38 | 0 | - |
| semester | light | 100% | 42 | 42 | 0 | - |
| semester | light | 125% | 39 | 39 | 0 | - |
| semester | light | 150% | 38 | 38 | 0 | - |
| semester | dark | 100% | 42 | 42 | 0 | - |
| semester | dark | 125% | 39 | 39 | 0 | - |
| semester | dark | 150% | 38 | 38 | 0 | - |
| roster | light | 100% | 42 | 42 | 0 | - |
| roster | light | 125% | 39 | 39 | 0 | - |
| roster | light | 150% | 38 | 38 | 0 | - |
| roster | dark | 100% | 42 | 42 | 0 | - |
| roster | dark | 125% | 39 | 39 | 0 | - |
| roster | dark | 150% | 38 | 38 | 0 | - |

Header height over all measurements: min 64 px, max 115 px (624 measurements use two rows, the rest one).

## Layout decisions

See the comment block at the top of the "Responsive" section of `public/css/shell.css`: one row while everything fits, then two rows
(row 1 logo, class menu, "+" and the chips; row 2 the four tabs, then theme, Undo, Today and the avatar). The two-row layout is a CSS grid whose DOM order
already equals the visual reading order of the focusable controls (logo, class menu, "+", tabs, theme, Undo, account), so no `order` trick changes the Tab order.
The class menu is a `<details>/<summary>` disclosure: the whole pill is the summary and the panel is positioned from the pill (header, up to 768px: from the left gutter of the header) with `z-index` 120 inside the sticky header, so nothing paints over it.
Chips and Today collapse first; the class menu, "+", tabs, theme switch, Undo and account never do.
