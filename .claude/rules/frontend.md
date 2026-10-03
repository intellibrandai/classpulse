---
description: Blade views, hand-written CSS and classic-script JavaScript conventions
paths:
  - "resources/views/**"
  - "public/**"
---

# Frontend rules

- No Node, npm, Vite, bundler or CDN. Fonts are self-hosted only (`public/fonts/*.woff2` via `public/css/fonts.css`:
  Plus Jakarta Sans, JetBrains Mono); never link Google Fonts. CSS and JS are hand-written files in `public/css` and
  `public/js`, served as-is.
- The CSP has no `unsafe-inline` (`script-src 'self'`, `style-src 'self'`). Therefore in every Blade file: no `style=`
  attribute, no `<style>` element, no `<script>` without `src`, no `onclick=`-style attributes, no `@vite`.
  `tests/Feature/LayoutShellTest.php` fails the build on any of them.
- Output user data with `{{ }}` only. `{!! !!}` is forbidden in `resources/views` (the same test fails on it).
- JS files are classic scripts loaded with `<script src="/js/NAME.js" defer>` (only `theme-init.js` is blocking).
  Never `import`/`export`, never `type="module"`. `public/js/save-queue.js` ends with the UMD footer:
  `if (typeof module !== 'undefined' && module.exports) { module.exports = api; } else { globalThis.ClassPulseSaveQueue = api; }`.
- Pass data to JS through `data-*` attributes and the day-state API, never through an inline JSON script tag.
- JS changes classes and attributes only; never sets a `style` attribute.
- Colours come from CSS custom properties in `public/css/tokens.css` (Stitch light/dark sets plus legacy names). No raw
  hex or rgb() in `app.css`, `shell.css`, `daily.css`, `screens.css` or views. `shell.css` = header/footer, `glass.css` = glass kit (`.glass`, `.ui-*`) loaded last, `print.css` = print base (`media="print"`), `daily.css` = Daily Tracker,
  `screens.css` = Weekly, Semester, Roster, Import, Student history, Login and error pages.
- Every status is readable as text as well as colour: `Not recorded`, `Present · 0`, `Present`, `Absent`,
  `Saving…`, `Saved`, `Save failed — Retry`. Weekly cells always show the number, `A` or `—`.
- Touch targets: card point buttons 44x44 CSS px (Stitch); other Daily Tracker controls follow the Stitch sizes.
- Every button has an accessible name that includes the student when it acts on one
  (`Add one point to Alex Rivera`).
- Destructive or bulk actions confirm in a `<dialog>` that names the class, the date and the count.
- Theme: `<html data-theme="light|dark">` set by `public/js/theme-init.js` (blocking, in `<head>`), default
  follows `prefers-color-scheme`; only the key `classpulse-theme` goes to `localStorage`, wrapped in try/catch.
- Motion: 120ms ease-out on `transform`/`opacity` only; `@media (prefers-reduced-motion: reduce)` removes it.
- Icons: only `<x-icon name="…">` (sprite in `components/icons.blade.php`); never emoji or text arrows in views.
- All app UI text is English.
