---
description: Participation operations, calendar and statistics rules
paths:
  - "app/Support/**"
  - "app/Services/**"
  - "tests/**"
---

# Calculation rules

- Row semantics: no row = Not recorded; `present` + points N (N >= 0, a recorded zero counts) = Present with N;
  `absent` = Absent. Absent points never enter any total or average.
- total_points = SUM(points) over present rows; present_days_recorded = COUNT(present rows); absences =
  COUNT(absent rows); days_recorded = present_days_recorded + absences; participation_days = COUNT(present rows
  with points > 0).
- average_per_present_day = total_points / present_days_recorded, or the text `No data` when
  present_days_recorded = 0. Never 0 for "no data".
- The semester average is computed over the whole period, never as the mean of weekly averages.
- Store integers only. Round only when presenting: `number_format($value, 2, '.', '')`.
- Nothing is cached or stored as a derived total; every page recomputes from rows.
- Operations are deltas (`increment`, `decrement`, ...), never absolute values. `op_id` (UUID v4) is the
  idempotency key; a replayed `op_id` changes nothing and returns `replayed: true`.
- Every mutating call runs in one DB transaction that first locks the `school_classes` row with `lockForUpdate()`.
- Every change writes one `participation_events` row per affected student with the full before-snapshot, so
  `undo` can restore it exactly.
- Dates: only `App\Support\SchoolCalendar` computes "today" (America/Toronto). Weekends are rejected (`weekend`),
  dates after today are rejected (`future_date`).
- Points are 0..99 (`points_min`, `points_max`). Roster cap is at most `config('classpulse.max_roster')` (35) active students; archived students do not count.
- Tests never touch the dev database (`EnvironmentGuardTest` asserts `classpulse_test`), use factories, and use
  `Carbon::setTestNow()` for any date-dependent assertion.
