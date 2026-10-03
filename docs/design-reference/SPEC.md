# ClassPulse — approved redesign + feature spec (2026-09-30)

Source: owner's message with nine reference images (Google Stitch mock-ups; not redistributed in this repository). English UI. Local only (no Hostinger, no Google, no AI).
Keep: auth, DB, autosave (`save-queue.js`, day API, `ParticipationService`), undo, the three states **Not recorded / Recorded zero /
Absent**, min points 0, absences and not-recorded days excluded from every average. Never lose existing records: new tables/columns
via NEW migrations only (never edit a migration that already ran), every new column nullable or defaulted.

## 1. Brand
Official logo = the waveform (17 bars, two glowing: white and cyan). Vector assets already built in `public/brand/`:
`classpulse-mark.svg` (dark theme, transparent), `classpulse-mark-light.svg` (light theme), `classpulse-favicon.svg`,
`classpulse-icon-512.png`, `apple-touch-icon.png`, `favicon-64.png`, `classpulse-mark-600.png`, `public/favicon.ico`.
Use it in the header, login, favicon/touch icons (`<link rel="icon">`), error pages and print views (light/print version). Never the
old ⚡ tile, no birrete/rayo. Wordmark "ClassPulse" + subtitle "Student Participation Tracker". Show the dark mark in dark theme and
the light mark in light theme (CSS swap of two <img>/CSS background, no JS flash). Never scale the 280x144 PNG.

## 2. Visual finish (all screens, both themes)
Layered dark surfaces; glass panels (translucent background + `backdrop-filter: blur()` on the panel so only what is BEHIND is
blurred, never text/charts; provide an opaque fallback via `@supports not (backdrop-filter…)`); thin borders with violet/blue/green
light; localized glow on icons, active buttons and cards; soft shadows; dividers; badges; careful type. Light theme gets the equivalent
(white glass, soft coloured borders, subtle shadows) with WCAG AA text contrast (existing `tests/js/contrast.test.js` must stay green
and be extended for new pairs). Replace ALL emojis with one coherent inline-SVG icon family (24x24 viewBox, 1.75 stroke, round caps,
`currentColor`, sizes 16/18/20; one sprite `resources/views/components/icons.blade.php` + `<x-icon name="…">`), coloured by brand tokens.
No external icon fonts/CDNs; CSP forbids inline scripts/styles (`style-src 'self'`; SVG presentation attributes are fine).
Charts (sparklines, bars, trend lines) are server-rendered inline SVG built from real data (helper in `app/Support/`), with
`<title>`/text alternatives; no JS chart library.

## 3. Shell (header on every screen), from the roster/semester/weekly screenshots
One compact row: logo + wordmark/subtitle · **ACTIVE class selector** (class code + period, chevron, "+" to create class) · tab nav
(Daily Tracker, Weekly Matrix, Semester Analytics, Class Roster & Settings) with icons · live chips **Enrolled / Present / Absent**
(real counts for today's school date of the current class: enrolled = active students, present/absent from today's entries) ·
theme switch (sun/moon segmented, real) · Undo (works on every screen that edits) · Today (weekday/date) · account avatar (initials
of the user) with a small menu: signed-in email + Log out. Footer: real save status (dot + Saved/Saving…/Error), "ClassPulse ·
Student Participation Tracker", real shortcuts only. Responsive as already built (compact mobile shell, short tab labels).
Print views: `@media print` stylesheet + print header with the light logo, class, period/date, "Printed on …"; hide chrome.

## 4. Data model additions (new migrations; nullable/defaulted; no data loss)
- `students`: `preferred_name` (string 80, null), `observations` (text, null) — roster-level notes, distinct from dated notes.
- `student_notes`: id, school_class_id, student_id, `note_date` (date), `body` (text ≤ 2000), timestamps; UNIQUE(student_id, note_date);
  FKs cascade on class delete only via existing class-deletion flow; indexed by (school_class_id, note_date). One note per
  student per day (upsert). Tenant rule: every query scoped by school_class_id; student of another class → 404.
- `school_classes`: `title` (string 120, null; course title, e.g. "Nutrition & Health"), `room` (string 60, null), `schedule`
  (string 80, null; e.g. "Period 2 (10:15 - 11:35)"). Keep existing `name` (course code), `subject_description`, `period_label`,
  `roster_cap`, `semester_start/end`.
- `academic_periods`: id, school_class_id, `kind` enum('q1','q2') , `label` (string 40; defaults "Q1 / Midterm", "Q2 / Finals"),
  `starts_on`, `ends_on` (dates), timestamps; UNIQUE(school_class_id, kind); validated: start ≤ end, inside the semester range when
  one is set, q1/q2 must not overlap. "Full Semester" = class semester_start..semester_end (or first..last recorded date if unset).
  No invented cuts: if q1/q2 are not configured the selector shows only Full Semester plus a link "Set period dates".
- Archive/restore history: keep `archived_at` (exists) and show it; add `student_events` only if needed — the requirement is that
  archive/restore is reversible and the archived list shows who/when (archived date). The trash-style icon must ARCHIVE, never delete
  records.

## 5. Definitions (write these into the UI as info text/tooltips and into `docs/`)
- **Points** = stored integer points of present entries. Never call them "taps"/"clicks".
- **Present day recorded** = entry with status present (points ≥ 0). **Absent** = status absent. **Not recorded** = no entry.
- **Total points** (period) = sum of points of present entries.
- **Average per present day** = total points ÷ present days recorded; "No data" when 0 present days. Absences and not-recorded days are
  excluded (existing rule; locked).
- **Weekly avg (class)** = total points of the week ÷ present student-days recorded that week.
- **Recorded attendance rate** = present ÷ (present + absent) over RECORDED entries only; not-recorded days are excluded and shown
  separately as "n not recorded". "No data" when no entries.
- **Peak day** = weekday with the highest total points that week (ties → earliest; "No data" if none), with its points.
- **Comparisons** ("vs previous week/period") only when the previous span has ≥ 1 present day recorded; otherwise omit. No rankings,
  targets or percentages without a defined calculation. Rank column only if sorted by an explicit chosen metric and labelled.
- **Participation weight / grade**: NOT implemented (no defined conversion scale). Roster shows the rule text only; never present
  points as a percentage of a grade.
- Sparkline/trend series: weekly average per present day across the selected period's ISO weeks (weeks with no present days are gaps,
  not zeros).

## 6. Screens
### Daily Tracker (refs: daily-toolbar.webp, daily-student-panel.png)
Toolbar: date navigator (‹ date ›), Today, **+ Student** (quick-add dialog: name, optional number; existing store route; roster cap
enforced), **Day Slip** (print the day: class, date, roster with points/absent/not recorded; print stylesheet), search "/", filter chips
with counters: **All n · Active n** (present with points > 0) **· Zero n** (present with 0) **· Absent n · Not recorded n**.
Cards as built. Side panel (ref image 7): student header + status badge, current-week breakdown (real), **Semester cadence trend**
mini bar chart (weekly avg per present day, real; last week highlighted; empty state if < 2 weeks), **Session anecdotal remark**
textarea + **Save Student Note** (persists to `student_notes` for student + class + selected date; shows saved state; survives
reload/login; delete/clear allowed; max 2000 chars; CSRF; JSON `{data}`/`{error}` shape). Empty state when nothing selected.

### Weekly Matrix (refs: weekly-*.{png,webp})
Top bar: week navigator (‹ Week n: range ›, term label optional), class chip, **Copy Summary** (copies a plain-text weekly summary to
the clipboard, real), **Export CSV** (existing, byte-exact fixture must keep passing), **Print Weekly Sheet**. Four KPI cards with
mini SVG charts: **Class weekly avg** (pts/present day, sparkline over the week's days, vs previous week only if defined), **Total points**
(sum; per-day bars), **Recorded attendance rate** (progress bar; "p of n recorded student-sessions"), **Peak day** (weekday + points +
"n present"). Info strip with the rules ("Absent sessions (A) are omitted from weekly divisors…") + scale legend. Search box, **Sort**
(Name A–Z, Z–A, Total desc, Avg desc, Absences desc), "Showing n active students" counter, matrix with per-day cells, Weekly Total,
Weekly Avg (present days only, shows "pts / d days"), Absences badge; footer row with total points, class avg, total absences. **Cells
open a quick editor popover** (points −/+/type value, Record 0, Mark Absent / Clear, close with Esc) that saves through the existing
day API operations (same `op_id`/undo semantics), updates the row/footer/KPIs from the server response, and is covered by header Undo.
Not recorded = dashed "—" (distinct from 0 and A). Keyboard accessible.

### Semester Analytics (refs: semester-*)
Toolbar: current-course chip (code • title) with dropdown of classes, academic-period box (label + dates, editable link to settings),
segmented **Full Semester / Q1 / Q2** (only configured periods), actions **Report Card Comments** (opens a dialog to compose/copy
manual comments per student from saved notes; no AI), **Gradebook CSV** (existing semester export), **Print Summary**. Four KPI cards
(defined metrics only; e.g. Total points, Mean per present day, Session coverage = school days with ≥1 entry, Attendance health =
recorded attendance rate + absences count) with change badges only when a comparable previous span exists. Left: master table (search,
sort, columns: student, total pts, present days/recorded days, absences, avg, optional trend vs previous period only when defined,
action "Inspect"); selecting a row highlights it and fills the right **Inspector**: student header, four stats (total points, present
days, absences, average), **weekly evolution chart** (SVG), **chronological audit log** (entries newest first with status/points),
**qualitative log**: list of saved notes (date, body) with add/edit/delete (uses `student_notes`). Pagination or "show more" for
long rosters. Responsive: inspector becomes a sheet on small screens.

### Class Roster & Settings (ref: roster-full.webp)
Class selector as visual cards (code, title/subject • n students, active dot) + **Create New Class** (opens create form). Left column:
**Class Details** (course code, title, period/schedule, room/lab, subject description, roster cap, semester start/end, Q1/Q2 period
dates with labels) with Save; **Calculation Rules** card listing the rules as locked (lock icon, no toggles): "Floor limit — points
never drop below 0", "Exclude absent and not-recorded days from averages" and a note that participation weight is not applied
without a defined conversion scale; Delete class (existing confirmation). Right: **Class Roster** header (n active students badge,
capacity meter "n / cap seats (p%)" real), quick add (name + number), **Bulk CSV Import** (existing flow), print roster, search by
name/number, sort (Last name A–Z, Name, Participation avg desc [semester average per present day]), table: order, avatar initials, name
+ "Preferred: …", student id, observations, semester average (badge), status today (Present/Absent/Not recorded), actions: edit
(inline/dialog: name, preferred name, number, observations), archive (reversible; NOT delete), restore from the archived list with
archived date. Rows stack on mobile.

## 7. Verification
Feature tests for every new endpoint/calculation (tenant scoping, validation, idempotency, no-loss migrations up/down on the test DB,
notes persistence across sessions), JS unit tests for pure helpers, the existing gate (pint, phpunit, JS, release scripts). Browser
checks at 1287 / 768 / 375 px, light and dark, with TEMPORARY fictitious data (create a scratch class via the UI or seed into a
class named "ZZ Demo (temp)" and remove it afterwards; never modify the existing demo classes' recorded points except reversible test
clicks). Compare to the reference images at the same size and report differences.
