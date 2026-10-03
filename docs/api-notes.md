# ClassPulse API and helper notes (for UI work)

Backend contracts added for notes, academic periods, class/student fields and the weekly/semester statistics.
All routes sit under the `auth` middleware and the `web` group (session cookie + CSRF token, as the day API).
JSON: success `{"data": ...}`, error `{"error": {"code": "...", "message": "..."}}` (401 `unauthenticated`,
419 `csrf_mismatch`, 404 `not_found`, 422 `validation`).

## Schema additions (new migrations, all nullable/defaulted, up and down)

| Table | Columns |
|---|---|
| `students` | `preferred_name` string 80 null, `observations` text null |
| `school_classes` | `title` string 120 null, `room` string 60 null, `schedule` string 80 null |
| `student_notes` | `school_class_id`, `student_id`, `note_date` date, `body` text; UNIQUE(student_id, note_date); composite FK (student_id, school_class_id) -> students, cascade |
| `report_comment_drafts` | `school_class_id`, `student_id`, `period` string 8 (`full`/`q1`/`q2`), `body` text; UNIQUE(student_id, period); composite FK (student_id, school_class_id) -> students, cascade; FK to the class, cascade |
| `academic_periods` | `school_class_id`, `kind` enum q1/q2, `label` 40, `starts_on`, `ends_on`; UNIQUE(class, kind); CHECK ends_on >= starts_on |

## Notes API (student notes, one per student per day)

`{class}` and `{student}` use scoped bindings: a student of another class is a 404 `not_found` for every verb.
`{date}` is `YYYY-MM-DD` (real calendar date, else 422). Archived students keep and can edit their notes.

| Route | Body | Response |
|---|---|---|
| `GET /api/classes/{class}/students/{student}/notes` | - | `{"data":{"notes":[{"date":"2026-10-19","body":"...","updated_at":"ISO-8601"}]}}` newest first, `[]` when none |
| `PUT /api/classes/{class}/students/{student}/notes/{date}` | `{"body":"text"}` (key required; trimmed; 1-2000 chars) | `{"data":{"note":{date,body,updated_at},"deleted":false}}` |
| same PUT with empty/whitespace `body` | `{"body":""}` | deletes: `{"data":{"note":null,"deleted":true}}` (`false` when there was none) |
| `DELETE .../notes/{date}` | - | `{"data":{"note":null,"deleted":true\|false}}` (idempotent) |

Deleting a class removes its notes (FK cascade). Archiving never touches notes.

## Report comment drafts API (Report Card Comments dialog)

Same conventions as the notes API (scoped bindings: a student of another class is a 404 `not_found`; guests 401; CSRF on writes).
`{period}` is `full`, `q1` or `q2`; anything else, or `q1`/`q2` when the class has no such quarter configured, is 422 `validation`.
One draft per student and period. Archived students keep and can edit their drafts. Deleting a class removes them (FK cascade).

| Route | Body | Response |
|---|---|---|
| `GET /api/classes/{class}/report-comments?period=full` | - | `{"data":{"period":"full","drafts":{"<student_id>":{"body":"...","updated_at":"ISO-8601"}}}}`; `drafts` is `{}` when none; `period` defaults to `full` |
| `PUT /api/classes/{class}/students/{student}/report-comments/{period}` | `{"body":"text"}` (key required; trimmed; 1-4000 chars) | `{"data":{"draft":{body,updated_at},"deleted":false}}` |
| same PUT with blank `body` | `{"body":""}` | deletes (back to the generated template): `{"data":{"draft":null,"deleted":true}}` (`false` when there was none) |
| `DELETE .../report-comments/{period}` | - | `{"data":{"draft":null,"deleted":true\|false}}` (idempotent) |

The dialog shows the saved draft (badge "Saved draft" + time) or else the generated template (badge "Template"); it autosaves 800 ms after
typing stops and on blur (Saving... / Saved / Save failed + Retry), "Reset to template" sends DELETE, Copy comment / Copy all copy the text on screen.
The state machine lives in `public/js/semester-lib.js` (`draftInit`, `draftReduce`, `draftRequest`).

## Class details and periods

`PUT /classes/{class}` (redirect flow, validation errors in the session bag as before) now also accepts
`title` (120), `room` (60), `schedule` (80) and the period fields `q1_label`, `q1_start`, `q1_end`, `q2_label`,
`q2_start`, `q2_end` (label max 40, dates `Y-m-d`). `POST /classes` accepts title/room/schedule.

- Period fields are applied only when at least one of them is sent. Start and end both filled saves the period
  (blank label becomes `Q1 / Midterm` / `Q2 / Finals`); both blank removes it; only one of the two is an error.
- Rules (`AcademicPeriods::validate`): start <= end; a quarter inside the semester bounds that are set; Q1 and Q2 must
  not overlap. Error keys: `semester_end`, `q1_start`, `q1_end`, `q2_start`, `q2_end`. Moving the semester dates is
  validated against the stored quarters too.
- `POST/PUT /students...` (`StudentRequest`) also accept `preferred_name` (max 80) and `observations` (max 2000);
  omitted keys are kept, blank clears. `POST /students/{id}/archive|restore` unchanged (reversible).
- `GET /roster` now also passes `rosterStats` (see `ReportData::roster`) and `periods` to the view; `archivedStudents`
  is ordered newest-archived first and `$student->archivedOn()` returns e.g. `Oct 20, 2026`.

## Header chips

`App\Services\TodaySnapshot::for(?SchoolClass): array{date, enrolled, present, absent, not_recorded}` for
`SchoolCalendar::defaultDate()` (today, or the previous Friday on a weekend); enrolled = active students; only active
students are counted. A view composer on `components.layouts.app` passes it as `$todaySnapshot` (uses the layout's
`$currentClass`). The layout markup is not changed yet.

## Pure calculators (`app/Support`, no DB/request)

Row: `['student_id'=>int,'work_date'=>'Y-m-d','status'=>'present'|'absent','points'=>?int]`. Averages are floats or
`null` (= "No data"); format with `ParticipationStats::formatAverage()`. Rates are floats 0..1 or `null`.

- `WeeklyStats::compute(array $days, array $rows, array $activeStudentIds, array $previousRows = [], ?string $today = null)` returns
  `days`, `class` (summarize shape), `day_points[5]`, `day_present[5]`, `day_absent[5]`, `day_averages[5]` (null = gap),
  `attendance{present,absent,recorded,not_recorded,possible,rate}`, `peak_day{date,weekday,points,present}|null`,
  `comparison{previous_average,average_delta,previous_total_points,total_points_delta}|null`,
  `students[id]{total_points,present_days,absences,average}`, `has_data`. `possible` = active students x elapsed days
  (days after `$today` are not yet possible); `not_recorded` = possible - recorded by active students.
  Also `WeeklyStats::formatRate(?float): "83%"|"No data"` and `summaryText($className, $weekLabel, $week)` for Copy Summary.
- `SemesterStats::compute(array $period{from,to}, array $rows, array $studentIds, string $today, ?array $previous)` returns
  `period{from,to,effective_to}`, `has_data`, `kpi{total_points,present_days_recorded,absences,mean_per_present_day}`,
  `coverage{days_with_entries,school_days_elapsed,rate}`, `attendance{present,absent,recorded,rate}`,
  `comparison{previous_mean,mean_delta,previous_attendance_rate,attendance_rate_delta}|null`, `weeks[{monday,from,to}]`,
  `class_series[?float per week]`, `class_totals[?int points per week, null without a present day]`, `students[id]{student_id,total_points,present_days,days_recorded,absences,participation_days,average,trend{previous_average,delta}|null}`,
  `series[id][?float per week]` (same length as `weeks`), `log[id][{date,status,points}]` newest first.
  Also `weeklyAverages($rows,$from,$to)` and `schoolDays($from,$to)`.
- `StudentRoster::build(array $studentIds, array $rows, string $schoolDate, ?string $from, ?string $to)` returns per id
  `{student_id,average,present_days,absences,today:'present'|'absent'|'none',today_points}`.
- `AcademicPeriods::resolve($semStart,$semEnd,$configured,$firstRecorded,$lastRecorded)` -> `['full'=>..., 'q1'=>..., 'q2'=>...]`
  each `{key,label,from,to,configured}` (only configured quarters; Full falls back to first..last recorded date);
  `select($periods,$key)` (unknown -> full), `previous($periods,$period)` (Q2 -> Q1, otherwise null), `validate(...)`.
- `Sparkline::build(array $values, float $w, float $h, float $padding = 2, ?float $min = 0, ?float $max = null)` returns
  `{width,height,has_data,count,min,max,points[{index,x,y,value}],segments[string],path,last}`; nulls are gaps
  (the path is split into `M..L..` segments, gap points are absent). Render `path` in `<path d>` and `points` as dots.
- `BarSeries::build(array $values, float $w, float $h, float $gap = 4, float $padding = 0, ?float $max = null)` returns
  `{width,height,has_data,max,baseline_y,peak_index,last_index,bars[{index,x,y,width,height,value,is_gap,is_peak,is_last}]}`
  (one bar per index; gap bars have height 0 and `is_gap`; ties for the peak go to the earliest; peak needs value > 0).

## Assembler service (`app/Services/ReportData`, read-only, every query scoped by class)

- `weekly(SchoolClass, string $monday)` -> `{monday, days, stats (WeeklyStats), students[{id,name,preferred_name,student_number,archived,cells[{status:'present'|'absent'|'none',points}]}], charts{average: Sparkline, points: BarSeries}}`.
  Students = active + archived ones with entries that week, name A-Z.
- `semester(SchoolClass, ?string $periodKey)` -> `{periods, selected, previous, stats (SemesterStats), students[{id,name,preferred_name,student_number,archived}], notes[studentId][{date,body}]}`.
- `periods(SchoolClass)`, `roster(SchoolClass)` (semester average per present day + today's status of active students),
  `studentCadence(SchoolClass, Student)` (weekly averages over the Full Semester for the Daily panel chart), `rows(...)`.

## Weekly Matrix: quick edit operations and the week JSON

Operations `POST /api/classes/{class}/days/{date}/operations` (client `op_id`, idempotent replay, class row lock, undoable
through events, response = the full day state, same as every other kind). Two kinds were added for the Weekly quick editor:

| Kind | Body | State table | Errors |
|---|---|---|---|
| `set_points` | `{"op_id","kind":"set_points","student_id":1,"points":7}` (`points` required, integer) | NR -> P(n); P(m) -> P(n) | `points_min` 422 (< 0), `points_max` 422 (> 99), `student_absent` 409 (mark present first with `absent_off`), `unchanged` 409 (same value, nothing written), `validation` 422 (missing or non-integer), `student_archived` 422, `not_found` 404 (student of another class) |
| `clear` | `{"op_id","kind":"clear","student_id":1}` | P(n) or A(r) -> NR (row deleted; undo restores it exactly) | `not_recorded` 422 |

The 0..99 bounds are business rules in `ParticipationService` (error bodies carry the current `day`), not request rules.
Weekend and future dates stay rejected (`weekend`, `future_date`). `undo` is still per date: the Weekly page keeps an in-memory
stack (per page load, max 20) of the date of each edit and sends `undo` to that date.

`GET /api/classes/{class}/weeks/{monday}` (`Api\WeekController`, `WeeklyView::build`) returns `{"data": ...}`; 422 `validation` for
a bad date or a non-Monday, 404 for an unknown class, 401 for guests. Every query is scoped by the class in the URL. Shape:
`monday`, `range_label`, `days[{date,weekday,weekday_long,label,editable,today}]`, `has_data`,
`kpis{average{value,has_data,delta{text,direction,label}|null,chart},total_points{value,sub,chart},attendance{rate_text,has_data,percent,detail,not_recorded_text},peak{weekday,points_text,present_text}|null}`,
`students[{id,archived,cells[{date,status,points}],total_points,total_text,present_days,absences,absences_text,average,average_text,average_sub}]`,
`footer{total_points,average_text,absences}` and `summary_text` (the Copy Summary text). `chart` is the geometry from
`Sparkline`/`BarSeries` (`path`/`dots` or `bars`, `alt`); the page and `public/js/weekly.js` draw it identically. The JavaScript
paints these strings and never computes a total.

Decisions: archived students that have entries in the shown week stay visible, read-only and labelled "Archived", because
`WeeklyStats` counts their entries in the KPIs and the footer must add up (their numbers are not editable). The page "week n" is
the semester week when the class has semester dates and the week falls inside them, otherwise the ISO week number.

## Semester Analytics page, inspector endpoint and CSV per period

`GET /semester?class=<id>&period=full|q1|q2` (`SemesterController::index` -> `ReportData::semester` -> `SemesterView::build`). An unknown or
unconfigured `period` is Full Semester. Only configured periods appear in the segmented control; without Q1/Q2 a hint links to
`/roster?class=<id>#periods` ("Set period dates", or "Edit period dates" once one exists). The table is server-rendered for every
student; search, sort (Name, Total points, Mean, Absences, Present days), rank (only for a metric sort, ties share a rank) and the
25-row "Show more" paging run in `public/js/semester-lib.js` over the `data-*` attributes and never compute a total. Sort and search
are not in the URL; class and period are. KPI cards: Total points (weekly bars from `class_totals`), Mean per present day (weekly
sparkline), Session coverage (`n of m school days`), Attendance health (recorded rate, absences recorded, not recorded = active
students x school days elapsed - their recorded entries). Change badges ("vs Q1 / Midterm") appear only when `SemesterStats` returns a
comparison; the trend column and its header appear only then too (delta of the average per present day).

`GET /api/classes/{class}/students/{student}/semester?period=` (`Api\StudentSemesterController`, scoped binding: a student of another
class is 404 `not_found`; guests 401; `period` must be a string, unknown keys fall back to full) returns `SemesterView::student`:
`student{id,name,preferred_name,student_number,archived,initials}`, `period{key,label,from,to,range_label}`,
`today{date,status:present|absent|none,points,text}` (entry of the school date), `stats{total_points,present_days,days_recorded,present_text,absences,average_text,has_data}`,
`chart{width,height,has_data,path,dots[{x,y,title,is_last}],alt,enough,weeks,weeks_with_data,empty_text,baseline_y,top_y,max_text,labels[{x,text}],summary{lowest,peak}|null}`
(`enough` = at least 2 weeks with data; otherwise the page shows `empty_text`), `log[{date,label,weekday,status,status_text,points,points_text}]` newest
first, `notes[{date,label,weekday,body}]` newest first (all dates). The inspector saves notes through the Notes API above (PUT/DELETE, CSRF,
422 messages shown inline); one note per student and day, so adding a note on a date that has one replaces it (the form says so).

`GET /export/semester.csv?class=<id>&period=q1|q2` exports the configured quarter (end clamped to today); full semester, unknown and
unconfigured periods keep the original output byte for byte (same columns, same file name).

Report Card Comments: a `<dialog>` built from the server rows (real numbers of the period) plus the student's saved notes inside the period
dates. `ClassPulseSemester.buildComment` writes a neutral, editable draft ("<Name> recorded n participation points over m present days
(average x) in <period>. Notes: ..."); Copy comment / Copy all use the clipboard. Drafts are saved in the database through the Report comment drafts API below; no AI, no network.
Print Summary uses the print header (class, period, printed date), the KPI row as text, every student row (paging and search are
overridden in print) and the log and notes of the selected student only.

## Definitions (SPEC section 5)

- **Points**: stored integer points of present entries (never "taps" or "clicks").
- **Present day recorded**: entry with status present (points >= 0; a recorded zero counts). **Absent**: status absent. **Not recorded**: no entry.
- **Total points** of a period: sum of points of present entries.
- **Average per present day**: total points / present days recorded; "No data" with 0 present days. Absent and not-recorded days are excluded.
- **Weekly avg (class)**: total points of the week / present student-days recorded that week.
- **Recorded attendance rate**: present / (present + absent) over recorded entries only; not-recorded sessions are shown separately as "n not recorded"; "No data" without entries.
- **Session coverage** (semester): school days with at least one entry / school days elapsed in the period (Monday-Friday up to today; there is no holiday calendar).
- **Peak day**: weekday with the highest total points that week among days with a present entry; ties go to the earliest; none -> "No data".
- **Comparisons** ("vs previous week/period"): only when the previous span has at least one present day recorded (and the current one too). Previous period: Q2 vs Q1; Q1 and Full Semester have none. No rankings, targets or percentages of a grade.
- **Participation weight / grade**: not implemented (no defined conversion scale).
- **Trend series**: weekly average per present day across the ISO weeks (Monday start) of the period; weeks with no present day are gaps, not zeros.
- **Full Semester** = class semester dates, or first..last recorded date when unset. Q1/Q2 exist only when configured.
