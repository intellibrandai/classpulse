# Epic 02: Roster and daily tracking

> After this epic, the teacher manages classes and each class roster under its cap, and the Daily Tracker records
> points, zeros and absences through one idempotent, lock-protected, undoable operations engine exposed as a JSON API
> and driven from the page by a tested save queue.

| | |
|---|---|
| **Epic id** | `02-roster-daily-tracking` |
| **Tasks** | `E2-T1` … `E2-T9` (build steps 10-18) |
| **Depends on** | `01-foundation-access` |
| **Unlocks** | `03-reports-import-release` |
| **Parallel with** | none (shares `routes/web.php` with 01 and 03) |

You do not need any other file to complete this epic. Everything below is repeated here on purpose.

---

## Stack

Laravel 13 · PHP 8.3 in Docker image `php:8.3-cli-bookworm` · Blade · hand-written CSS/JS classic scripts in
`public/` (no Node runtime, no npm, no Vite, no bundler) · MariaDB 10.11 (`mariadb:10.11` container) · Eloquent ·
Laravel session auth · PHPUnit 12 · Pint · JS unit tests with `node --test` in the throwaway `node:24-alpine`
container (`jstest` service, zero packages, no `package.json`). Package manager: Composer inside the image.
Dependency versions are in `composer.lock` — read it, never guess one. PHP, Composer, MariaDB and Node are NOT
installed on the host; every command goes through Docker.

| Task | Command |
|---|---|
| Start services | `docker compose up -d --build` (web http://localhost:8090) |
| Migrate dev DB | `docker compose exec -T app php artisan migrate --force` |
| Test (one file) | `docker compose exec -T app vendor/bin/phpunit tests/Feature/DayApiTest.php` |
| List a file's tests | `docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/DayApiTest.php` |
| Test (all) | `docker compose exec -T app vendor/bin/phpunit` |
| JS tests | `docker compose --profile test run --rm jstest` (runs `node --test tests/js/*.test.js`) |
| JS syntax check | `docker compose --profile test run --rm jstest node --check public/js/daily.js` |
| Format check | `docker compose exec -T app vendor/bin/pint --test` |
| Local services | up: `docker compose up -d --build` · down: `docker compose stop` (never `down -v`) |

**Gate for this epic:** `docker compose exec -T app vendor/bin/pint --test && docker compose exec -T app vendor/bin/phpunit`
passes before any task here is marked done. From `E2-T9` (step 18), when `tests/js/` first exists, the gate also
runs the JS suite and requires that at least one test passed and none failed (a run that matches no test file
prints `pass 0` and exits 0, so the exit code alone proves nothing):
`docker compose --profile test run --rm jstest > /tmp/classpulse-jstest.txt && grep -qE '(ℹ|#) pass [1-9]' /tmp/classpulse-jstest.txt && grep -qE '(ℹ|#) fail 0' /tmp/classpulse-jstest.txt`
(`/tmp` is the host's temp directory; the `&&` chain stops if the container exits non-zero).

The database services come from `docker-compose.yml`, already at the project root (shipped in `workspace/`). Tests
use `classpulse_test` via `phpunit.xml`, never the dev database `classpulse`.

## Directory subtree

Only the parts this epic touches:

```
app/
  Console/Commands/DemoSeedCommand.php        # NEW (E2-T6) local-only demo data through the service
  Exceptions/ParticipationException.php       # NEW (E2-T5) code + HTTP status + message
  Http/
    Controllers/ClassController.php           # NEW (E2-T1) store/update; E2-T2 adds index/destroy
    Controllers/StudentController.php         # NEW (E2-T3)
    Controllers/Api/DayController.php         # NEW (E2-T7)
    Controllers/DailyController.php           # NEW (E2-T8)
    Requests/StoreClassRequest.php            # NEW (E2-T1)
    Requests/UpdateClassRequest.php           # NEW (E2-T1)
    Requests/DestroyClassRequest.php          # NEW (E2-T2)
    Requests/StudentRequest.php               # NEW (E2-T3) shared by add and rename
    Requests/DayOperationRequest.php          # NEW (E2-T7)
  Models/*.php                                # exists (01), read-only
  Providers/AppServiceProvider.php            # exists — E2-T4 adds the SchoolCalendar singleton
  Services/ParticipationService.php           # NEW (E2-T5), extended in E2-T6
  Support/SchoolCalendar.php                  # NEW (E2-T4)
  Support/ParticipationStats.php              # NEW (E2-T4)
  Support/CurrentClass.php                    # exists (01), read-only
bootstrap/app.php                             # exists — E2-T7 adds the JSON exception rendering
routes/web.php                                # exists — E2-T1, E2-T2, E2-T3, E2-T7, E2-T8 add routes
public/js/
  save-queue.js                               # NEW (E2-T9) pure FIFO queue, UMD footer
  daily.js                                    # NEW (E2-T9) DOM wiring for the Daily page
  dialogs.js                                  # NEW (E2-T9) <dialog> confirm helper
resources/views/
  roster/index.blade.php                      # NEW (E2-T2) — E2-T3 adds the student tables
  daily/index.blade.php                       # NEW (E2-T8) — E2-T9 adds the three script tags
  daily/card.blade.php                        # NEW (E2-T8)
  components/layouts/app.blade.php            # exists (01), read-only
tests/
  Feature/ClassManagementTest.php             # NEW (E2-T1)
  Feature/RosterPageTest.php                  # NEW (E2-T2)
  Feature/StudentRosterTest.php               # NEW (E2-T3)
  Unit/SchoolCalendarTest.php                 # NEW (E2-T4)
  Unit/ParticipationStatsTest.php             # NEW (E2-T4)
  Feature/ParticipationServiceTest.php        # NEW (E2-T5)
  Feature/ParticipationIdempotencyTest.php    # NEW (E2-T5)
  Feature/ParticipationBatchUndoTest.php      # NEW (E2-T6)
  Feature/DemoSeedTest.php                    # NEW (E2-T6)
  Feature/DayApiTest.php                      # NEW (E2-T7)
  Feature/DailyPageTest.php                   # NEW (E2-T8)
  js/save-queue.test.js                       # NEW (E2-T9) node --test
  Feature/LayoutShellTest.php                 # exists (01) — re-run as a gate, never edited here
```

Everything outside this subtree is out of scope. If a task seems to require editing a file not listed here, stop
and report — it means the epic boundary is wrong.

## Data model touched here

| Entity | Fields this epic adds or reads | Notes |
|---|---|---|
| `school_classes` | all | created/edited/deleted on Roster & Settings; the class row is locked with `lockForUpdate()` at the start of every operation |
| `students` | `id`, `school_class_id`, `display_name`, `student_number`, `archived_at` | archived students keep entries, never appear on Daily, never receive operations |
| `participation_entries` | all | no row = Not recorded; `present`+points N = Present with N (0 counts); `absent` = Absent; `restore_points` = points held before the absence (NULL = nothing was recorded) |
| `participation_operations` | all | `seq` doubles as the per-day version; `op_id` UNIQUE is the idempotency key |
| `participation_events` | all | one row per affected student with the before-snapshot; used only by `undo` |

## Contracts

**Consumed** — already exists, do not rebuild:

| From | Interface | Guarantee |
|---|---|---|
| `01-foundation-access` | `SchoolClass::activeStudents()`, `activeStudentCount()`, `remainingCapacity()`; factories for tests | counts students with `archived_at` NULL |
| `01-foundation-access` | `CurrentClass::resolve(?int $requested): ?SchoolClass` | session key `classpulse.current_class_id` |
| `01-foundation-access` | `<x-layouts.app title active classes currentClass>` with an `actions` slot | CSP-clean shell with nav tabs |
| `01-foundation-access` | `SecurityHeaders` global middleware; `auth` route group; `login` route name | guests redirect to `/login` |
| `01-foundation-access` | `config('classpulse.max_roster')` = 30, `config('classpulse.max_points')` = 99 | |

**Produced** — later epics depend on exactly these signatures. Changing one breaks them:

| Export | Signature | Used by |
|---|---|---|
| routes | `GET /roster`, `POST /classes`, `PUT/DELETE /classes/{class}`, `POST /classes/{class}/students`, `PUT /students/{student}`, `POST /students/{student}/archive`, `POST /students/{student}/restore`, `GET /daily`, the two `/api` routes | 03 |
| `app/Support/SchoolCalendar.php` → `SchoolCalendar` | `__construct(string $timezone = 'America/Toronto')`, `today(): string`, `isWeekday(string $date): bool`, `isFuture(string $date): bool`, `previousWeekday(string $date): string`, `nextWeekday(string $date): string`, `weekStart(string $date): string`, `weekDays(string $monday): array`, `defaultDate(): string`; all dates `Y-m-d` strings | 03 |
| `app/Support/ParticipationStats.php` → `ParticipationStats` | `summarize(array $rows, ?string $from = null, ?string $to = null): array` returning keys `total_points`, `present_days_recorded`, `absences`, `days_recorded`, `participation_days`, `average` (?float); `weekly(array $rows, string $from, string $to, SchoolCalendar $calendar): array` keyed by Monday with `from`, `to` and the same keys; `formatAverage(?float $average): string` | 03 |
| `app/Services/ParticipationService.php` | `apply(SchoolClass $class, string $date, string $opId, string $kind, ?int $studentId = null): array` and `dayState(SchoolClass $class, string $date, bool $replayed = false): array` — both return the `data` object below | 03 |
| `app/Exceptions/ParticipationException.php` | public readonly `string $errorCode`, `int $status`; `getMessage()` is the user message | 03 |
| `POST /api/classes/{class}/days/{date}/operations` / `GET /api/classes/{class}/days/{date}` | schemas below | `public/js/daily.js` |
| Daily page DOM | the DOM contract table in `E2-T8` | `public/js/daily.js` (E2-T9) |
| `public/js/save-queue.js` → `ClassPulseSaveQueue` / `module.exports` | `createSaveQueue({ send, newId, onApply, onStateChange, onError })` returning `{ enqueue(kind, studentId), retry(), applyServerState(day), pendingCount(), state(), highestVersion() }`; `shouldWarnBeforeUnload(queue)` | `public/js/daily.js`, `tests/js/save-queue.test.js` |

**Day state (`data`)**:
`{"day_version": int, "entries": [{"student_id": int, "status": "none|present|absent", "points": int|null, "revision": int}], "summary": {"active_students": int, "recorded": int, "present_recorded": int, "absent": int, "not_recorded": int, "total_points": int}, "can_undo": bool, "replayed": bool}`.
`entries` has one item per ACTIVE student ordered by the roster order (below); `day_version` is the max `seq` of
the class/date operations or 0; `recorded` = `present_recorded` + `absent`; `not_recorded` = `active_students` −
`recorded`; `total_points` sums present points; `can_undo` is true when a not-undone, non-`undo` operation exists.

**Roster order** (every list in the app): `strcmp(mb_strtolower($a->display_name), mb_strtolower($b->display_name))`,
ties by `id`.

**Operation request**: `{"op_id": "<uuid v4>", "kind": "increment|decrement|set_zero|absent_on|absent_off|zero_remaining|reset_day|undo", "student_id": int}`
(`student_id` required for the first five kinds, ignored for the rest).
**Success**: 200 `{"data": <day state>}`. **Error**: `{"error": {"code": string, "message": string, "day": <day state>}}`
where `day` is present for the participation business codes and absent for `validation`, `weekend`, `future_date`,
`not_found`, `unauthenticated`, `csrf_mismatch`.

| Code | HTTP | Message (literal) |
|---|---|---|
| `validation` | 422 | the first validation message |
| `weekend` | 422 | `Weekends are not school days.` |
| `future_date` | 422 | `Future dates cannot be edited.` |
| `points_max` | 422 | `Points cannot go above 99.` |
| `points_min` | 422 | `Points cannot go below 0.` |
| `not_recorded` | 422 | `Nothing is recorded for this student yet.` |
| `student_archived` | 422 | `This student is archived.` |
| `nothing_to_do` | 422 | `Nothing to do for this day.` |
| `student_absent` | 409 | `This student is marked absent. Mark present first.` |
| `already_recorded` | 409 | `This student already has a record for this day.` |
| `already_absent` | 409 | `This student is already marked absent.` |
| `not_absent` | 409 | `This student is not marked absent.` |
| `nothing_to_undo` | 409 | `Nothing to undo for this day.` |
| `not_found` | 404 | `Not found.` |
| `unauthenticated` | 401 | `Session expired — sign in again` |
| `csrf_mismatch` | 419 | `Session expired — sign in again` |

## Conventions that bite in this area

- **`ParticipationService` is the only writer** of entries, operations and events — including the demo seed.
  Factories may create entries only inside tests.
- **Lock first.** Every mutating call is `DB::transaction(fn () => ...)` whose first query is
  `SchoolClass::whereKey($class->id)->lockForUpdate()->first()`; then the replay check; then the work. Weekend and
  future checks run before the transaction.
- **Deltas, never absolutes.** The client never sends a points value.
- **Dates are strings `Y-m-d`** in America/Toronto computed only by `SchoolCalendar`; parse with
  `CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC')` for arithmetic so DST never shifts a date.
- **Tests freeze time** with `CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-21 10:00', 'America/Toronto'))`
  and reset it in `tearDown`.
- **JSON routes live in `routes/web.php`** under `/api` (session auth + CSRF), not in a stateless `routes/api.php`.
- **Links to pages that arrive later use `url()`, never `route()`** — `route()` throws for a name that does not exist
  yet (`/classes/<id>/import` arrives in step 23, `/students/<id>` in step 21).
- **No inline script or style in Blade.** `LayoutShellTest` scans every view; it is a gate for every view step.
- **Classic scripts only.** `save-queue.js` has no `import`/`export` and ends with
  `if (typeof module !== 'undefined' && module.exports) { module.exports = api; } else { globalThis.ClassPulseSaveQueue = api; }`.
  Node treats it as CommonJS because the project has no `package.json`. The browser loads it with
  `<script src="/js/save-queue.js" defer>`.
- **Every test file names the method its step's gate lists** (`vendor/bin/phpunit --list-tests <file> | grep -q
  '<Class>::<method>'`).

Full project rules: `CLAUDE.md`. Area rules: `.claude/rules/calculations.md`, `.claude/rules/security.md`,
`.claude/rules/frontend.md`, `.claude/rules/database.md`. All sit in the project root — the builder copied them there
from the bundle's `workspace/` before task one.

---

## Tasks

Listed in the same order as `tasks.json`. That order is the build order — work top to bottom and do not re-rank by
priority or by what looks quick.

### `E2-T1` — Add class create and edit

**Depends on:** `E1-T9` · **Priority:** p0 — metadata for scope cuts, not a running order

`ClassController` with `store` (`POST /classes`, redirect to `url('/roster?class='.$class->id)`) and `update`
(`PUT /classes/{class}`, redirect back), both in the `auth` group of `routes/web.php`. FormRequests:
`StoreClassRequest` (`name` required|string|max:60|unique:school_classes,name; `subject_description`
nullable|string|max:120; `period_label` nullable|string|max:40; `roster_cap` required|integer|between:1,30 using
`config('classpulse.max_roster')`; `semester_start` nullable|date_format:Y-m-d; `semester_end`
nullable|date_format:Y-m-d|after_or_equal:semester_start), `UpdateClassRequest` (same rules, `unique` ignoring the
current id, plus an `after` hook that adds a `roster_cap` error when the new cap is below
`$class->activeStudentCount()`). The `/roster` page itself arrives in E2-T2; tests here assert the redirect location
without following it. `tests/Feature/ClassManagementTest.php` has a method named `test_teacher_creates_a_class`.
The third `Verify` line writes the route list to the host temp file `/tmp/classpulse-routes.txt`.

**Files**
- `app/Http/Controllers/ClassController.php` — new: `store`, `update`
- `app/Http/Requests/StoreClassRequest.php` — new
- `app/Http/Requests/UpdateClassRequest.php` — new
- `routes/web.php` — edit: `POST /classes`, `PUT /classes/{class}`
- `tests/Feature/ClassManagementTest.php` — new

**Acceptance**

1. **WHEN** a teacher posts a valid class with `name`, `subject_description`, `period_label`, `roster_cap` between 1 and 30 and optional semester dates to `/classes` **THE SYSTEM SHALL** create 1 `school_classes` row and redirect to `/roster?class=` followed by its id.
2. **WHEN** the posted `name` duplicates an existing class, `roster_cap` is 0 or 31, or `semester_end` precedes `semester_start` **THE SYSTEM SHALL** return a validation error for that field and create 0 rows.
3. **WHEN** a teacher sends changed fields with `PUT /classes/{class}` **THE SYSTEM SHALL** update that row and keep its id.
4. **WHEN** `PUT /classes/{class}` sets `roster_cap` below that class's active student count **THE SYSTEM SHALL** return a `roster_cap` validation error and keep the stored cap.
5. **WHEN** a guest posts to `/classes` or sends `PUT /classes/{class}` **THE SYSTEM SHALL** redirect to `/login` and change nothing.

**Verify** — every command, in order, run from the project root. Each one exits 0 when this task is correct.

```bash
docker compose exec -T app vendor/bin/phpunit tests/Feature/ClassManagementTest.php
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/ClassManagementTest.php | grep -q 'ClassManagementTest::test_teacher_creates_a_class'
docker compose exec -T app php artisan route:list --path=classes > /tmp/classpulse-routes.txt && grep -q 'ClassController@store' /tmp/classpulse-routes.txt && grep -q 'ClassController@update' /tmp/classpulse-routes.txt
docker compose exec -T app vendor/bin/pint --test
```

**Checkpoint**

```bash
git add -A && git commit -m "step 10: classes (E2-T1)"
git tag step-10-classes
```

Run both after the last `Verify` command exits 0. The tag is this task's rollback target and the thing the build's
final gate counts. Never invent the tag: copy the `checkpoint` field.

### `E2-T2` — Add the Roster & Settings page and class deletion

**Depends on:** `E2-T1` · **Priority:** p0

Add to `ClassController`: `index` (`GET /roster`, resolves the class with
`CurrentClass::resolve($request->integer('class') ?: null)` and renders `roster.index` inside
`<x-layouts.app active="roster">`) and `destroy` (`DELETE /classes/{class}`, redirect to `/roster`), both in the
`auth` group. `DestroyClassRequest`: `confirm_name` required and, in an `after` hook, equal to the class name, else
the error `Type the class name exactly to confirm.`. `resources/views/roster/index.blade.php`: empty state
`Create your first class` when no class exists; a `<details>` titled `Create New Course / Section` with labelled fields
`Official course code`, `Subject description`, `Period / Block`, `Target roster cap`, `Semester start`,
`Semester end` posting to `/classes`; the class parameters form (`PUT`); and a `<details>` titled
`Delete this class` whose text is `This permanently deletes <name>, <n> students and <m> entries.` (for example
`This permanently deletes HNL 2O, 3 students and 12 entries.`, with the real name and counts) followed by a
`confirm_name` field and a destructive submit. `tests/Feature/RosterPageTest.php` has a method named
`test_delete_requires_the_exact_class_name`.

**Files**
- `app/Http/Controllers/ClassController.php` — edit: add `index`, `destroy`
- `app/Http/Requests/DestroyClassRequest.php` — new
- `resources/views/roster/index.blade.php` — new
- `routes/web.php` — edit: `GET /roster`, `DELETE /classes/{class}`
- `tests/Feature/RosterPageTest.php` — new

**Acceptance**

1. **WHEN** `DELETE /classes/{class}` arrives with a `confirm_name` different from the class name **THE SYSTEM SHALL** keep the class and return a `confirm_name` error, and with the exact name it SHALL delete the class and every student, entry, operation and event of it.
2. **WHEN** `/roster` is requested with no classes **THE SYSTEM SHALL** show `Create your first class`, and for an existing class it SHALL show a delete confirmation naming the class and its student and entry counts.
3. **WHEN** `/roster?class=` with a class id renders **THE SYSTEM SHALL** show a `Create New Course / Section` form with labelled fields `Official course code`, `Subject description`, `Period / Block`, `Target roster cap`, `Semester start` and `Semester end`.
4. **WHEN** a guest requests `/roster` or sends `DELETE /classes/{class}` **THE SYSTEM SHALL** redirect to `/login` and delete nothing.

**Verify**

```bash
docker compose exec -T app vendor/bin/phpunit tests/Feature/RosterPageTest.php
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/RosterPageTest.php | grep -q 'RosterPageTest::test_delete_requires_the_exact_class_name'
docker compose exec -T app vendor/bin/phpunit tests/Feature/LayoutShellTest.php
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/roster)" = 302
docker compose exec -T app vendor/bin/pint --test
```

**Checkpoint**

```bash
git add -A && git commit -m "step 11: roster-page (E2-T2)"
git tag step-11-roster-page
```

### `E2-T3` — Manage the student roster with the cap

**Depends on:** `E2-T2` · **Priority:** p0

`StudentController` with `store` (`POST /classes/{class}/students`), `update` (`PUT /students/{student}`),
`archive` (`POST /students/{student}/archive`) and `restore` (`POST /students/{student}/restore`), in the `auth`
group. One FormRequest serves both add and rename, because the rules are identical: `StudentRequest` with
`display_name` required|string|min:1|max:120 (trimmed) and `student_number` nullable|string|max:40. Before an add or a
restore, when `$class->activeStudentCount() >= $class->roster_cap`, redirect back with the error key `roster_cap` and
the message `This class already has <active count> active students (cap <roster_cap>).` — with 30 of 30 that is
exactly `This class already has 30 active students (cap 30).`. Renaming never changes `id`. Archiving sets
`archived_at = now()` and deletes nothing. Extend `resources/views/roster/index.blade.php`: an add-student form, an
`Active students` table (rename form, `Archive` button whose confirmation text names the student), an
`Archived students` table (`Restore`), the empty state `Add students or import a CSV` with a plain
`url('/classes/'.$class->id.'/import')` link (that page arrives in step 23), and the cap shown as `<active>/<cap> active`.
`tests/Feature/StudentRosterTest.php` has a method named `test_cap_blocks_the_31st_active_student`.

**Files**
- `app/Http/Controllers/StudentController.php` — new
- `app/Http/Requests/StudentRequest.php` — new
- `resources/views/roster/index.blade.php` — edit: student tables
- `routes/web.php` — edit: student routes
- `tests/Feature/StudentRosterTest.php` — new

**Acceptance**

1. **WHEN** a teacher posts a `display_name` of 1 to 120 characters and an optional `student_number` to `/classes/{class}/students` **THE SYSTEM SHALL** create 1 active student in that class.
2. **WHEN** a class with `roster_cap` 30 already has 30 active students **THE SYSTEM SHALL** reject another add and a restore with the error `This class already has 30 active students (cap 30).` and keep 30 active students.
3. **WHEN** `/students/{student}/archive` is posted **THE SYSTEM SHALL** set `archived_at` and keep every entry of that student, and `/students/{student}/restore` SHALL clear it while the class is under its cap.
4. **WHEN** `PUT /students/{student}` renames a student **THE SYSTEM SHALL** keep the same `id`.
5. **WHEN** `/roster?class=` with a class id renders **THE SYSTEM SHALL** list active and archived students in separate tables, and a class with 0 students SHALL show `Add students or import a CSV`.
6. **WHEN** a student route receives an id that does not exist **THE SYSTEM SHALL** return 404.

**Verify**

```bash
docker compose exec -T app vendor/bin/phpunit tests/Feature/StudentRosterTest.php
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/StudentRosterTest.php | grep -q 'StudentRosterTest::test_cap_blocks_the_31st_active_student'
docker compose exec -T app vendor/bin/phpunit tests/Feature/LayoutShellTest.php
docker compose exec -T app vendor/bin/pint --test
```

**Checkpoint**

```bash
git add -A && git commit -m "step 12: students (E2-T3)"
git tag step-12-students
```

### `E2-T4` — Add SchoolCalendar and ParticipationStats

**Depends on:** `E2-T3` · **Priority:** p0

Two pure, final classes. `SchoolCalendar` takes the timezone in its constructor (default `America/Toronto`) and
computes `today()` as `CarbonImmutable::now($this->timezone)->format('Y-m-d')`; every other method does date-only
arithmetic on `CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC')`. `defaultDate()` returns today, or the
previous Friday when today is Saturday or Sunday; `isFuture($d)` is `$d > today()`; `weekStart` returns the Monday of
the ISO week; `weekDays($monday)` returns the 5 dates Monday-Friday. In `AppServiceProvider::register` bind
`$this->app->singleton(SchoolCalendar::class, fn () => new SchoolCalendar(config('classpulse.school_timezone')));`.
`ParticipationStats` takes rows shaped `['student_id' => int, 'work_date' => 'Y-m-d', 'status' => 'present'|'absent', 'points' => ?int]`
(plain arrays, no Eloquent) and implements the formulas: total_points = SUM(points of present rows);
present_days_recorded = COUNT(present rows) (a recorded 0 counts); absences = COUNT(absent rows); days_recorded =
present_days_recorded + absences; participation_days = COUNT(present rows with points > 0); average =
total_points / present_days_recorded, or null when present_days_recorded = 0. `formatAverage(null)` returns
`No data`, otherwise `number_format($average, 2, '.', '')`. `weekly()` buckets rows by Monday, clipping the first
bucket's `from` and the last bucket's `to` to the period. Unit tests extend `PHPUnit\Framework\TestCase` (no
framework boot) and construct `new SchoolCalendar('America/Toronto')`; they freeze the clock with
`CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-21 23:30', 'America/Toronto'))` and also cover the DST
changes of `2026-03-08` and `2026-11-01`. Method names the gate lists: `SchoolCalendarTest::test_today_uses_toronto_midnight`
and `ParticipationStatsTest::test_semester_average_is_not_the_mean_of_weekly_averages`. These are the first files
in `tests/Unit`.

**Files**
- `app/Support/SchoolCalendar.php` — new
- `app/Support/ParticipationStats.php` — new
- `app/Providers/AppServiceProvider.php` — edit: singleton binding
- `tests/Unit/SchoolCalendarTest.php` — new
- `tests/Unit/ParticipationStatsTest.php` — new

**Acceptance**

1. **WHEN** the clock is `2026-10-21 23:30` America/Toronto **THE SYSTEM SHALL** return `2026-10-21` from `SchoolCalendar::today()`, and at `2026-10-22 00:30` America/Toronto it SHALL return `2026-10-22`.
2. **WHEN** `previousWeekday('2026-03-09')`, `nextWeekday('2026-10-23')` and `previousWeekday('2026-11-02')` are called **THE SYSTEM SHALL** return `2026-03-06`, `2026-10-26` and `2026-10-30`.
3. **WHEN** `isWeekday('2026-10-24')` and `weekStart('2026-10-21')` are called **THE SYSTEM SHALL** return false and `2026-10-19`, and `weekDays('2026-10-19')` SHALL return the five dates `2026-10-19` to `2026-10-23`.
4. **WHEN** a student has present rows with 3, 0 and 5 points, one absent row and one not-recorded day **THE SYSTEM SHALL** report total_points 8, present_days_recorded 3, absences 1, days_recorded 4, participation_days 2 and an average that formats as `2.67`.
5. **WHEN** a student has 0 present days recorded in the period **THE SYSTEM SHALL** format the average as `No data`, never `0.00`.
6. **WHEN** week 1 has one present day with 10 points and week 2 has four present days with 1 point each **THE SYSTEM SHALL** return a period average of 2.8 (14 / 5) rather than the 5.5 mean of weekly averages, and weekly buckets SHALL be clipped to the period bounds.

**Verify**

```bash
docker compose exec -T app vendor/bin/phpunit tests/Unit/SchoolCalendarTest.php
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Unit/SchoolCalendarTest.php | grep -q 'SchoolCalendarTest::test_today_uses_toronto_midnight'
docker compose exec -T app vendor/bin/phpunit tests/Unit/ParticipationStatsTest.php
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Unit/ParticipationStatsTest.php | grep -q 'ParticipationStatsTest::test_semester_average_is_not_the_mean_of_weekly_averages'
docker compose exec -T app vendor/bin/pint --test
```

**Checkpoint**

```bash
git add -A && git commit -m "step 13: calendar-stats (E2-T4)"
git tag step-13-calendar-stats
```

### `E2-T5` — Implement single-student participation operations

**Depends on:** `E2-T4` · **Priority:** p0

`ParticipationService::apply()` order: (1) reject a weekend date with `weekend` and a date after
`SchoolCalendar::today()` with `future_date`; (2) open `DB::transaction`; (3) lock the class row with
`SchoolClass::whereKey($class->id)->lockForUpdate()->first()`; (4) if `participation_operations.op_id` already exists,
return `dayState($class, $date, true)`; (5) resolve the student with
`Student::where('school_class_id', $class->id)->whereKey($studentId)->first()` — null ⇒ `not_found` (404), archived ⇒
`student_archived` (422); (6) read the entry for (class, student, date) with `lockForUpdate()`; (7) apply the
transition; (8) insert the operation row (`op_id`, `school_class_id`, `work_date`, `kind`) and one event row per
affected student with `before_exists`, `before_status`, `before_points`, `before_restore_points`; (9) bump
`revision` on every row it updates (new rows start at 1); (10) return `dayState($class, $date)`.
Transitions (NR = no row, P(n) = present with n, A(r) = absent remembering r):

| Kind | NR | P(n) | A(r) |
|---|---|---|---|
| `increment` | P(1) | P(n+1); n = 99 ⇒ `points_max` 422 | `student_absent` 409 |
| `decrement` | `not_recorded` 422 | n > 0 ⇒ P(n−1); n = 0 ⇒ `points_min` 422 | `student_absent` 409 |
| `set_zero` | P(0) | `already_recorded` 409 | `already_recorded` 409 |
| `absent_on` | A(NULL) | A(n) | `already_absent` 409 |
| `absent_off` | `not_absent` 409 | `not_absent` 409 | r not NULL ⇒ P(r); r NULL ⇒ delete the row (back to NR) |

An absent row stores `points` NULL and the remembered value in `restore_points`. `ParticipationException` carries
`errorCode`, `status` and the literal message from the error table above. `dayState()` builds the `data` object
defined in *Contracts*. `ParticipationIdempotencyTest` applies 50 increments with 50 `Str::uuid()` values (method
`test_fifty_distinct_op_ids_store_fifty_points`), replays one `op_id`, and captures `DB::getQueryLog()` (after
`DB::enableQueryLog()`) to assert that a query containing `for update` on `school_classes` precedes the first
`insert`. `ParticipationServiceTest` has a method named `test_absent_off_restores_remembered_points`.

**Files**
- `app/Services/ParticipationService.php` — new
- `app/Exceptions/ParticipationException.php` — new
- `tests/Feature/ParticipationServiceTest.php` — new
- `tests/Feature/ParticipationIdempotencyTest.php` — new

**Acceptance**

1. **WHEN** `increment` is applied **THE SYSTEM SHALL** turn no row into present with 1 point and present n into n+1, and SHALL raise `points_max` (422) at 99 points and `student_absent` (409) for an absent student.
2. **WHEN** `decrement` is applied **THE SYSTEM SHALL** lower present n above 0 by 1, and SHALL raise `points_min` (422) at 0, `not_recorded` (422) when no row exists and `student_absent` (409) when the student is absent.
3. **WHEN** `set_zero`, `absent_on` and `absent_off` are applied **THE SYSTEM SHALL** record present 0 only on a day with no row (otherwise `already_recorded` 409), remember the points held before an absence and restore them on `absent_off`, and delete the row on `absent_off` when nothing was recorded before the absence.
4. **WHEN** 50 `increment` operations with 50 distinct `op_id` values are applied to one student **THE SYSTEM SHALL** store 50 points and 50 `participation_operations` rows.
5. **WHEN** an `op_id` that already exists is applied again **THE SYSTEM SHALL** change no row and return the day state with `replayed` true.
6. **WHEN** any operation runs **THE SYSTEM SHALL** lock the `school_classes` row with a `for update` query before its first write, and SHALL raise `not_found` (404) for a student of another class, `student_archived` (422) for an archived student, `weekend` (422) for a Saturday or Sunday and `future_date` (422) for a date after today in America/Toronto.

**Verify**

```bash
docker compose exec -T app vendor/bin/phpunit tests/Feature/ParticipationServiceTest.php
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/ParticipationServiceTest.php | grep -q 'ParticipationServiceTest::test_absent_off_restores_remembered_points'
docker compose exec -T app vendor/bin/phpunit tests/Feature/ParticipationIdempotencyTest.php
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/ParticipationIdempotencyTest.php | grep -q 'ParticipationIdempotencyTest::test_fifty_distinct_op_ids_store_fifty_points'
docker compose exec -T app vendor/bin/pint --test
```

**Checkpoint**

```bash
git add -A && git commit -m "step 14: participation-service (E2-T5)"
git tag step-14-participation-service
```

### `E2-T6` — Add batch operations, undo and the demo seed

**Depends on:** `E2-T5` · **Priority:** p0

Extend `ParticipationService::apply()` with the three class-day kinds (same lock, replay check and event recording):
`zero_remaining` — every active student with no row that date becomes P(0) (one event each with `before_exists` 0);
absent, recorded and archived students are untouched; 0 qualifying students ⇒ `nothing_to_do` (422).
`reset_day` — deletes every entry of that class and date (one event per deleted row with its full before-snapshot);
0 rows ⇒ `nothing_to_do` (422). `undo` — picks the operation with the highest `seq` for the class/date where
`kind != 'undo'` and `undone_at IS NULL`; none ⇒ `nothing_to_undo` (409); for each of its events in descending `id`
order: `before_exists = 0` ⇒ delete the row, else upsert the row with `before_status`, `before_points`,
`before_restore_points` (revision + 1, or 1 if recreated); set that operation's `undone_at`; insert an operation row of
kind `undo` with the request `op_id` and no events. Undo of an undo is not supported.
`DemoSeedCommand` (`classpulse:demo-seed {--days=20}`): exit 1 with `Demo seed only runs when APP_ENV=local.` unless
`$this->laravel->environment('local')`; exit 1 if any class already exists; create the classes `HNL 2O`, `HNC 3C` and
`HLS 3O` with 12-18 students each named from invented first/last name lists (for example `Alex Rivera`,
`Jordan Lee`, `Robin Sky`); for each of the previous `--days` school days (walking back with
`SchoolCalendar::previousWeekday` from `defaultDate()`) apply random `increment`, `absent_on` and `zero_remaining`
operations **through `ParticipationService`** with fresh UUIDs; if `users` is empty, create `teacher@classpulse.test`
with `Str::password(20)` and print `Teacher: teacher@classpulse.test` and `Password: <value>` once.
`DemoSeedTest` sets `$this->app['env'] = 'local'` for the success case and runs `--days=2`; its refusal case is the
method `test_refuses_outside_local`. `ParticipationBatchUndoTest` has a method named
`test_undo_restores_reset_day_as_one_batch`.

**Files**
- `app/Services/ParticipationService.php` — edit: batch kinds and undo
- `tests/Feature/ParticipationBatchUndoTest.php` — new
- `app/Console/Commands/DemoSeedCommand.php` — new
- `tests/Feature/DemoSeedTest.php` — new

**Acceptance**

1. **WHEN** `zero_remaining` is applied **THE SYSTEM SHALL** record present 0 for every active student without a row that day, leave absent, recorded and archived students untouched, and raise `nothing_to_do` (422) when no student qualifies.
2. **WHEN** `reset_day` is applied **THE SYSTEM SHALL** delete every entry of that class and date only, and raise `nothing_to_do` (422) when there are none.
3. **WHEN** `undo` is applied repeatedly **THE SYSTEM SHALL** revert the most recent not-undone operation of that class and date each time, restoring the exact prior status, points and restore_points, and raise `nothing_to_undo` (409) when none remain.
4. **WHEN** the undone operation was `zero_remaining`, `reset_day` or `absent_on` **THE SYSTEM SHALL** restore every affected student to the state before that operation as one batch.
5. **WHEN** `classpulse:demo-seed` runs outside `APP_ENV=local` **THE SYSTEM SHALL** exit 1 and write 0 rows.
6. **WHEN** `classpulse:demo-seed --days=2` runs with `APP_ENV=local` on an empty database **THE SYSTEM SHALL** create 3 classes with invented student names, at least one `participation_operations` row for every seeded class and day, and the user `teacher@classpulse.test`, print its random password once and exit 0.

**Verify**

```bash
docker compose exec -T app vendor/bin/phpunit tests/Feature/ParticipationBatchUndoTest.php
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/ParticipationBatchUndoTest.php | grep -q 'ParticipationBatchUndoTest::test_undo_restores_reset_day_as_one_batch'
docker compose exec -T app vendor/bin/phpunit tests/Feature/DemoSeedTest.php
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/DemoSeedTest.php | grep -q 'DemoSeedTest::test_refuses_outside_local'
docker compose exec -T app php artisan list classpulse | grep -q 'classpulse:demo-seed'
docker compose exec -T app vendor/bin/pint --test
```

**Checkpoint**

```bash
git add -A && git commit -m "step 15: batch-undo (E2-T6)"
git tag step-15-batch-undo
```

### `E2-T7` — Expose the daily JSON API

**Depends on:** `E2-T6` · **Priority:** p0

Routes inside the `auth` group of `routes/web.php`:
`GET /api/classes/{class}/days/{date}` → `DayController@show` and
`POST /api/classes/{class}/days/{date}/operations` → `DayController@store`, both with
`->where('date', '[0-9]{4}-[0-9]{2}-[0-9]{2}')`. The controller rejects an impossible date (`2026-02-30`) with 422
`validation`, delegates to `ParticipationService`, and catches `ParticipationException` to return
`{"error": {"code", "message", "day"}}` with its status (`day` from `dayState()` for the business codes only).
`DayOperationRequest`: `op_id` required|uuid; `kind` required|in:increment,decrement,set_zero,absent_on,absent_off,zero_remaining,reset_day,undo;
`student_id` required_if:kind,increment,decrement,set_zero,absent_on,absent_off|integer. In `bootstrap/app.php`
`withExceptions`, register render callbacks that apply only when `$request->is('api/*')`:
`AuthenticationException` ⇒ 401 `unauthenticated`; `TokenMismatchException` ⇒ 419 `csrf_mismatch`;
`ValidationException` ⇒ 422 `validation` (message = first error); `ModelNotFoundException` and
`NotFoundHttpException` ⇒ 404 `not_found`; messages from the error table. `DayApiTest` proves the 419 shape by
registering, inside the test, `Route::middleware('web')->post('/api/_probe/csrf', fn () => throw new \Illuminate\Session\TokenMismatchException())`
(Laravel skips real CSRF checks while running tests); it has a method named `test_replayed_op_id_changes_nothing`.

**Files**
- `app/Http/Controllers/Api/DayController.php` — new
- `app/Http/Requests/DayOperationRequest.php` — new
- `routes/web.php` — edit: two API routes
- `bootstrap/app.php` — edit: JSON exception rendering
- `tests/Feature/DayApiTest.php` — new

**Acceptance**

1. **WHEN** an authenticated teacher requests `GET /api/classes/{class}/days/{date}` **THE SYSTEM SHALL** return 200 with `data.day_version`, one `data.entries` item per active student, `data.summary`, `data.can_undo` and `data.replayed` false.
2. **WHEN** a valid operation is posted to `/api/classes/{class}/days/{date}/operations` **THE SYSTEM SHALL** return 200 with the updated entry and a `day_version` greater than before, and the same `op_id` posted again SHALL return 200 with `replayed` true and unchanged points.
3. **WHEN** an operation fails a business rule **THE SYSTEM SHALL** return the mapped status with a body whose `error` object carries `code`, `message` and `day`, for example 422 `points_min` and 409 `student_absent`.
4. **WHEN** the date is a Saturday, after today or not a real calendar date, or `kind` is unknown, or `student_id` is missing for a per-student kind **THE SYSTEM SHALL** return 422 with code `weekend`, `future_date` or `validation`.
5. **WHEN** `student_id` belongs to another class or `{class}` does not exist **THE SYSTEM SHALL** return 404 with code `not_found`.
6. **WHEN** an unauthenticated JSON request reaches an `/api` route **THE SYSTEM SHALL** return 401 with code `unauthenticated`, and a `TokenMismatchException` on an `/api` route SHALL render as 419 with code `csrf_mismatch`.

**Verify**

```bash
docker compose exec -T app vendor/bin/phpunit tests/Feature/DayApiTest.php
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/DayApiTest.php | grep -q 'DayApiTest::test_replayed_op_id_changes_nothing'
test "$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' http://localhost:8090/api/classes/1/days/2026-10-21)" = 401
docker compose exec -T app vendor/bin/pint --test
```

**Checkpoint**

```bash
git add -A && git commit -m "step 16: day-api (E2-T7)"
git tag step-16-day-api
```

### `E2-T8` — Render the Daily Tracker page

**Depends on:** `E2-T7` · **Priority:** p0

`DailyController@__invoke` on `GET /daily` (auth group): resolve the class with `CurrentClass`; no class ⇒ render the
empty state `Create your first class` linking to `/roster`; read `date` (default `SchoolCalendar::defaultDate()`);
a Saturday/Sunday ⇒ redirect to the previous Friday (`/daily?class=<id>&date=<friday>`); a date after today ⇒
redirect to today (or the previous Friday if today is a weekend); build the cards from
`ParticipationService::dayState()`. `resources/views/daily/index.blade.php` inside `<x-layouts.app active="daily">`:
in the `actions` slot `Undo last action` (`#undo-btn`, `disabled` when `can_undo` is false); a date bar with a
`Previous school day` link, the date text formatted `l, M j, Y` (for `2026-10-21`: `Wednesday, Oct 21, 2026`), a
`Next school day` link (on today: `<button disabled>` with no link) and `Today`; the save pill
`<span id="save-pill" role="status" aria-live="polite">Saved</span>`; `Reset day` and `Mark remaining as 0` buttons
carrying `data-dialog="reset-dialog"` and `data-dialog="zero-dialog"` that open `#reset-dialog` and `#zero-dialog`
(each `<dialog>` names the class, the date text and the count, e.g.
`Reset all entries for HNL 2O on Wednesday, Oct 21, 2026? This removes 2 recorded entries.`); a labelled search field
`#student-search` (`Find student…`) with `#search-count`; filter chips `All`, `Active`, `Absent`
(`button[data-filter][aria-pressed]`); the summary line
`<present_recorded> recorded · <absent> absent · <not_recorded> not recorded · <total_points> pts`; then the card grid.
`<main>` carries `data-api="/api/classes/<id>/days/<date>"` and `data-day-version`. `resources/views/daily/card.blade.php`
renders `<article class="student-card" data-student-id="<id>" data-status="none|present|absent">`: `[−]` button
(`data-action="decrement"`, `aria-label="Remove one point from <name>"`), the name linking to
`url('/students/'.$id)` (page arrives in step 21), the score (`—` plus `Not recorded` and a `Record 0` button with
`data-action="set_zero"` when not recorded; the number plus `Present · 0` when 0; the number plus `Present`
otherwise; `Absent` when absent), the `[+]` button (`data-action="increment"`, `aria-label="Add one point to <name>"`),
then a full-width button `Absent` (`data-action="absent_on"`, `aria-label="Mark <name> absent"`) that reads
`Mark present` (`data-action="absent_off"`, `aria-label="Mark <name> present"`) when absent; when absent both point
buttons carry `disabled` and the card reads `ABSENT (Tap to mark Present)`. Class without active students ⇒
`Add students or import a CSV`. `tests/Feature/DailyPageTest.php` freezes the clock at `2026-10-21 10:00`
America/Toronto and has a method named `test_absent_card_disables_point_buttons`.

**DOM contract with `public/js/daily.js` (E2-T9).** These are the only hooks the script uses; E2-T9's gate greps both
sides.

| Hook in the view | File | Values | What `daily.js` does with it |
|---|---|---|---|
| `data-action` on card buttons | `daily/card.blade.php` | exactly `increment`, `decrement`, `set_zero`, `absent_on`, `absent_off` | enqueues that kind for the card's `data-student-id` |
| `data-dialog` on toolbar buttons | `daily/index.blade.php` | `reset-dialog`, `zero-dialog` | opens that `<dialog>`; on confirm enqueues `reset_day` or `zero_remaining` |
| `id="undo-btn"` | `daily/index.blade.php` | — | enqueues `undo`; toggles `disabled` from `can_undo` |
| `id="save-pill"` | `daily/index.blade.php` | — | shows `Saving…`, `Saved`, `Save failed — Retry`, `Session expired — sign in again` |
| `id="reset-dialog"`, `id="zero-dialog"` | `daily/index.blade.php` | — | confirmed through `ClassPulseDialogs.confirm` |
| `id="student-search"`, `id="search-count"` | `daily/index.blade.php` | — | search highlight and `N matches` |
| `main[data-api]`, `data-day-version`, `button[data-filter]`, `article.student-card[data-student-id][data-status]` | both | — | endpoint, version, filter chips, card re-render |

**Files**
- `app/Http/Controllers/DailyController.php` — new
- `resources/views/daily/index.blade.php` — new
- `resources/views/daily/card.blade.php` — new
- `routes/web.php` — edit: `GET /daily`
- `tests/Feature/DailyPageTest.php` — new

**Acceptance**

1. **WHEN** `/daily` renders for a class on `2026-10-21` at `2026-10-21 10:00` America/Toronto **THE SYSTEM SHALL** output one card with a `data-student-id` attribute per active student, none for archived students, and the date text `Wednesday, Oct 21, 2026`.
2. **WHEN** a card is not recorded, present with 0, present with points, or absent **THE SYSTEM SHALL** show the text `Not recorded` with a `Record 0` button, `Present · 0`, `Present`, or `Absent` with both point buttons `disabled`.
3. **WHEN** the requested date is Saturday `2026-10-24` **THE SYSTEM SHALL** redirect to `date=2026-10-23`, and a date after today SHALL redirect to today.
4. **WHEN** the page shows Monday `2026-10-19` **THE SYSTEM SHALL** link `Previous school day` to `2026-10-16`, and when it shows today it SHALL render `Next school day` as a disabled button with no link.
5. **WHEN** a card renders for `Alex Rivera` **THE SYSTEM SHALL** label its buttons `Add one point to Alex Rivera`, `Remove one point from Alex Rivera` and `Mark Alex Rivera absent`, render the save status pill with `aria-live=polite`, and render the summary line as `1 recorded · 1 absent · 1 not recorded · 3 pts` for one present student with 3 points, one absent student and one not-recorded student.
6. **WHEN** no class exists or the selected class has 0 active students **THE SYSTEM SHALL** show `Create your first class` or `Add students or import a CSV`.

**Verify**

```bash
docker compose exec -T app vendor/bin/phpunit tests/Feature/DailyPageTest.php
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/DailyPageTest.php | grep -q 'DailyPageTest::test_absent_card_disables_point_buttons'
docker compose exec -T app vendor/bin/phpunit tests/Feature/LayoutShellTest.php
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/daily)" = 302
docker compose exec -T app vendor/bin/pint --test
```

**Checkpoint**

```bash
git add -A && git commit -m "step 17: daily-page (E2-T8)"
git tag step-17-daily-page
```

### `E2-T9` — Add the save queue and wire the Daily page

**Depends on:** `E2-T8` · **Priority:** p0

`public/js/save-queue.js` (pure, no DOM): `createSaveQueue(options)` keeps a FIFO of `{op_id, kind, student_id}`;
`enqueue(kind, studentId)` creates an op with `options.newId()` (UUID v4), appends it, sets state `saving` and, if
nothing is in flight, sends the head through `options.send(op)` which resolves `{status, body}` or rejects. Exactly one
op is in flight. On 2xx: `applyServerState(body.data)`, remove the op, send the next; when the queue is empty set
state `saved`. On 409/422: remove the op, `applyServerState(body.error.day)` when present, call
`options.onError(body.error.message)`, continue with the next. On 401/419: set state `expired`, keep every op, stop
sending. On a rejection or any other status (5xx, 0): keep the op and everything behind it, set state `failed`, stop;
`retry()` resends the same head op (same `op_id`). `applyServerState(day)` ignores a `day` whose `day_version` is lower
than `highestVersion()`, otherwise records it and calls `options.onApply(day)`; returns whether it applied.
`shouldWarnBeforeUnload(queue)` is `queue.pendingCount() > 0`.
`public/js/daily.js` (defer) declares, on one line, exactly
`const CARD_ACTIONS = ['increment', 'decrement', 'set_zero', 'absent_on', 'absent_off'];` and ignores any
`button[data-action]` click whose value is not in it; reads `main[data-api]`, GETs the day once on load, builds
`send` with `fetch` (`POST`, JSON body, headers `Content-Type: application/json`, `Accept: application/json`,
`X-CSRF-TOKEN` from `meta[name="csrf-token"]`, `AbortController` timeout 10000 ms ⇒ reject), passes
`newId: () => crypto.randomUUID()`, handles card clicks (optimistic change on the card, then `enqueue`), opens the
dialog named by a toolbar button's `data-dialog` and on confirm enqueues `reset_day` (`reset-dialog`) or
`zero_remaining` (`zero-dialog`), enqueues `undo` from `#undo-btn`, re-renders cards, summary line, `#undo-btn` and
the pill (`#save-pill`) from each applied `day`, shows pill text `Saving…`, `Saved`, `Save failed — Retry` (a button
calling `retry()`) or `Session expired — sign in again` (link to `/login`, on-screen state kept), highlights search
matches from `#student-search` (outline class plus `<mark>`, others stay visible, `#search-count` shows `N matches`,
Enter focuses the first match), applies filter chips, and adds a `beforeunload` listener that calls
`event.preventDefault()` while `shouldWarnBeforeUnload` is true. It looks elements up by the literal ids `save-pill`,
`undo-btn`, `reset-dialog`, `zero-dialog`, `student-search` and `search-count` (the DOM contract table in E2-T8).
`public/js/dialogs.js` exposes `globalThis.ClassPulseDialogs.confirm(dialogEl)` returning a Promise of true/false from
`showModal()` and the dialog's `returnValue`. Add to `resources/views/daily/index.blade.php`:
`<script src="{{ asset('js/save-queue.js') }}" defer></script>`, then `dialogs.js`, then `daily.js`, all `defer`.
`tests/js/save-queue.test.js` uses `require('node:test')`, `require('node:assert/strict')`,
`require('node:crypto').randomUUID` and `require('../../public/js/save-queue.js')`, with a fake `send` whose promises
the test resolves by hand (no timers), and covers: 10 rapid enqueues sent one at a time in order with distinct v4 ids
(regex `^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$`); rejection, 500 and a timeout-style
rejection keep ops and `retry()` resends the same `op_id`; 409/422 drop and apply `error.day`; a lower `day_version`
is ignored; `saved` only after a 2xx empties the queue; 401 and 419 set `expired` and keep ops;
`shouldWarnBeforeUnload`. The first `Verify` line writes the runner's output to the host temp file
`/tmp/classpulse-jstest.txt` and requires a pass count starting with 1-9 and `fail 0` (Node prints `ℹ pass N` with the
spec reporter and `# pass N` with the TAP reporter; the pattern accepts both). `daily.js` and `dialogs.js` touch the
DOM, so they are syntax-checked with `node --check`, never executed under Node.

**Files**
- `public/js/save-queue.js` — new
- `public/js/daily.js` — new
- `public/js/dialogs.js` — new
- `tests/js/save-queue.test.js` — new
- `resources/views/daily/index.blade.php` — edit: three `defer` script tags

**Acceptance**

1. **WHEN** 10 actions are enqueued before any response arrives **THE SYSTEM SHALL** call `send` one operation at a time, in enqueue order, with 10 distinct UUID v4 `op_id` values.
2. **WHEN** `send` rejects, times out or returns 500 **THE SYSTEM SHALL** keep that operation and every later one pending, report state `failed`, and on `retry()` resend the same `op_id`.
3. **WHEN** a 409 or 422 response arrives **THE SYSTEM SHALL** drop that operation, apply the `day` state carried in the error, expose the error message and send the next operation.
4. **WHEN** a response carries a `day_version` lower than the highest already applied **THE SYSTEM SHALL** not apply it, report state `saved` only after a 2xx response leaves the queue empty, return true from `shouldWarnBeforeUnload` while operations are pending, and set state `expired` on a 401 or 419 response while keeping every pending operation.
5. **WHEN** `node --check` runs in the `jstest` container on `public/js/save-queue.js`, `public/js/dialogs.js` and `public/js/daily.js` **THE SYSTEM SHALL** exit 0 for each, and the Daily page view SHALL reference all three as external scripts that return HTTP 200.
6. **WHEN** `public/js/daily.js` is compared with the Daily views **THE SYSTEM SHALL** find the `CARD_ACTIONS` list of `increment`, `decrement`, `set_zero`, `absent_on` and `absent_off` in `daily.js`, each of those values as a `data-action` attribute in `resources/views/daily/card.blade.php`, no other `data-action` value in the Daily views, and the ids `save-pill`, `undo-btn`, `reset-dialog`, `zero-dialog`, `student-search` and `search-count` in both `daily.js` and `resources/views/daily/index.blade.php`.

**Verify**

```bash
docker compose --profile test run --rm jstest > /tmp/classpulse-jstest.txt && grep -qE '(ℹ|#) pass [1-9]' /tmp/classpulse-jstest.txt && grep -qE '(ℹ|#) fail 0' /tmp/classpulse-jstest.txt
docker compose --profile test run --rm jstest node --check public/js/save-queue.js
docker compose --profile test run --rm jstest node --check public/js/dialogs.js
docker compose --profile test run --rm jstest node --check public/js/daily.js
grep -q 'js/save-queue.js' resources/views/daily/index.blade.php
grep -q 'js/dialogs.js' resources/views/daily/index.blade.php
grep -q 'js/daily.js' resources/views/daily/index.blade.php
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/js/save-queue.js)" = 200
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/js/dialogs.js)" = 200
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/js/daily.js)" = 200
grep -qF "const CARD_ACTIONS = ['increment', 'decrement', 'set_zero', 'absent_on', 'absent_off'];" public/js/daily.js
grep -qF 'data-action="increment"' resources/views/daily/card.blade.php && grep -qF 'data-action="decrement"' resources/views/daily/card.blade.php && grep -qF 'data-action="set_zero"' resources/views/daily/card.blade.php && grep -qF 'data-action="absent_on"' resources/views/daily/card.blade.php && grep -qF 'data-action="absent_off"' resources/views/daily/card.blade.php
test "$(grep -ohE 'data-action="[a-z_]+"' resources/views/daily/index.blade.php resources/views/daily/card.blade.php | grep -cvE 'data-action="(increment|decrement|set_zero|absent_on|absent_off)"')" = 0
grep -qF 'save-pill' public/js/daily.js && grep -qF 'undo-btn' public/js/daily.js && grep -qF 'reset-dialog' public/js/daily.js && grep -qF 'zero-dialog' public/js/daily.js && grep -qF 'student-search' public/js/daily.js && grep -qF 'search-count' public/js/daily.js
grep -qF 'id="save-pill"' resources/views/daily/index.blade.php && grep -qF 'id="undo-btn"' resources/views/daily/index.blade.php && grep -qF 'id="reset-dialog"' resources/views/daily/index.blade.php && grep -qF 'id="zero-dialog"' resources/views/daily/index.blade.php && grep -qF 'id="student-search"' resources/views/daily/index.blade.php && grep -qF 'id="search-count"' resources/views/daily/index.blade.php
docker compose exec -T app vendor/bin/phpunit tests/Feature/DailyPageTest.php
docker compose exec -T app vendor/bin/phpunit tests/Feature/LayoutShellTest.php
```

**Checkpoint**

```bash
git add -A && git commit -m "step 18: save-queue (E2-T9)"
git tag step-18-save-queue
```

---

## Epic acceptance

The epic is done when every task is `done` **and**:

1. **WHEN** the full PHP suite and the JS suite run **THE SYSTEM SHALL** exit 0 with 0 failures and 0 errors, covering the class, roster, operation, undo, idempotency, API, Daily page and save-queue tests of this epic, with at least one JS test passed.
2. **WHEN** an unauthenticated client calls the day API **THE SYSTEM SHALL** return 401 with the JSON error envelope instead of an HTML redirect.

```bash
docker compose exec -T app vendor/bin/pint --test && docker compose exec -T app vendor/bin/phpunit
docker compose --profile test run --rm jstest > /tmp/classpulse-jstest.txt && grep -qE '(ℹ|#) pass [1-9]' /tmp/classpulse-jstest.txt && grep -qE '(ℹ|#) fail 0' /tmp/classpulse-jstest.txt
test "$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' http://localhost:8090/api/classes/1/days/2026-10-21)" = 401
```

## Pitfalls

- **Two writers is one too many.** A controller that "just sets points" breaks undo and idempotency. Everything goes
  through `ParticipationService::apply()`.
- **Locking a row that does not exist locks nothing.** That is why the class row, which always exists, is locked first.
- **Undo must use the stored before-snapshot**, never recompute ("decrement to undo an increment" is wrong after a
  reset or an absence).
- **Weekend redirect vs. weekend rejection:** the page redirects (`/daily`), the API rejects (`422 weekend`).
- **`number_format` is for display only.** Never store or compare rounded averages.
- **Archived students** never show on Daily and never receive operations, but keep every entry.
- **A `<script>` without `src` in any view** (even `type="application/json"`) fails `LayoutShellTest`; pass data to
  JS through `data-*` attributes and a GET of the day state.
- **`crypto.randomUUID()` needs a secure context** — localhost and HTTPS both qualify, which covers every real use.
- **An empty JS run passes on exit code alone** (`pass 0`, exit 0). That is why every JS gate greps the pass count.

## Before moving on

- [ ] Every task in this epic is `done` in `tasks.json` — no task left `in_progress`.
- [ ] Every `verify` command of every task in this epic passed, not just the first one.
- [ ] No `verify` command was edited, and none was skipped because a file it names did not exist.
- [ ] **Every task in this epic has its `checkpoint` tag in version control** — `step-10-classes` through
      `step-18-save-queue`, matching `tasks.json`. `git tag -l 'step-*'` lists them.
- [ ] Gate command passes clean, run from the project root.
- [ ] Every "Produced" contract above exists with the stated signature.
- [ ] No file outside the subtree was modified.
- [ ] `.env.example` unchanged — this epic adds no environment variable.
- [ ] One commit per task, each message containing its task id, each followed by its checkpoint tag.
