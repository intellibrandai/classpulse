# Epic 01: Foundation and access

> After this epic, a cleaned Laravel 13 app runs in Docker with the ClassPulse schema, models and factories,
> one-teacher account commands, global security headers, a login protected by throttling, and the design-system
> assets and layout shell every later page renders inside.

| | |
|---|---|
| **Epic id** | `01-foundation-access` |
| **Tasks** | `E1-T1` … `E1-T9` (build steps 1-9) |
| **Depends on** | nothing — start here (after §10 Bootstrap of `blueprints/classpulse/blueprint.md` has run) |
| **Unlocks** | `02-roster-daily-tracking`, `03-reports-import-release` |
| **Parallel with** | none (every later epic edits `routes/web.php`, which this epic creates) |

You do not need any other file to complete this epic. Everything below is repeated here on purpose.

---

## Stack

Laravel 13 (skeleton `laravel/laravel` pinned to `13.10.1`, framework resolved to 13.34.0) · PHP 8.3 in Docker image
`php:8.3-cli-bookworm` · Blade · hand-written CSS/JS in `public/` (no Node, no Vite, no bundler) · MariaDB 10.11
(`mariadb:10.11` container) · Eloquent · Laravel session auth without a starter kit · PHPUnit 12 · Pint (preset
`laravel`). Package manager: Composer 2.10.3 inside the image. Dependency versions are in `composer.lock` — read it,
never guess one. PHP, Composer, MariaDB and Node are NOT installed on the host; every command goes through Docker.
`node:24-alpine` (service `jstest`) is only used to syntax-check and test the hand-written JS.

| Task | Command |
|---|---|
| Start services | `docker compose up -d --build` (web http://localhost:8090) |
| Artisan | `docker compose exec -T app php artisan route:list` (any artisan command) |
| Migrate dev DB | `docker compose exec -T app php artisan migrate --force` |
| Test (one file) | `docker compose exec -T app vendor/bin/phpunit tests/Feature/AuthTest.php` |
| List a file's tests | `docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/AuthTest.php` |
| Test (all) | `docker compose exec -T app vendor/bin/phpunit` |
| Format check | `docker compose exec -T app vendor/bin/pint --test` |
| JS syntax check | `docker compose --profile test run --rm jstest node --check public/js/theme.js` |
| Local services | up: `docker compose up -d --build` · down: `docker compose stop` (never `down -v`) |

**Gate for this epic:** `docker compose exec -T app vendor/bin/pint --test && docker compose exec -T app vendor/bin/phpunit`
passes before any task here is marked done. (The project-wide gate also runs the `jstest` test suite with a
non-zero pass count; that part starts to apply at step 18, when `tests/js/` first exists, so it is not part of this
epic's gate.) `phpunit.xml` sets `failOnEmptyTestSuite="true"`, so a run that executes no test at all fails.

The services are defined in `docker-compose.yml`, which shipped in the bundle's `workspace/` and is already at the
project root. You do not write it. Tests use the database `classpulse_test` (set in `phpunit.xml`), never the dev
database `classpulse`.

## Directory subtree

Only the parts this epic touches:

```
config/
  classpulse.php                      # NEW (E1-T1) school timezone and limits
  app.php                             # scaffold — E1-T1 edits the timezone line
composer.json                         # scaffold — E1-T1 removes the npm/npx scripts
routes/web.php                        # scaffold — E1-T1 empties it; E1-T7 adds the auth routes
bootstrap/app.php                     # scaffold — E1-T6 appends the SecurityHeaders middleware
app/
  Console/Commands/
    CreateTeacherCommand.php          # NEW (E1-T5)
    ResetPasswordCommand.php          # NEW (E1-T5)
    HashPasswordCommand.php           # NEW (E1-T5)
  Http/
    Controllers/Auth/LoginController.php   # NEW (E1-T7)
    Middleware/SecurityHeaders.php         # NEW (E1-T6)
  Models/
    User.php                          # scaffold, read-only
    SchoolClass.php Student.php ParticipationEntry.php ParticipationOperation.php ParticipationEvent.php  # NEW (E1-T3)
  Providers/AppServiceProvider.php    # scaffold — E1-T7 adds the login rate limiter
  Support/CurrentClass.php            # NEW (E1-T9)
database/
  migrations/<timestamp>_create_classpulse_schema.php   # NEW (E1-T2) — name prefix chosen by make:migration
  factories/SchoolClassFactory.php StudentFactory.php ParticipationEntryFactory.php  # NEW (E1-T4)
  seeders/DatabaseSeeder.php          # scaffold — E1-T2 removes the fixed test user
public/
  css/tokens.css css/app.css          # NEW (E1-T8)
  js/theme-init.js js/theme.js        # NEW (E1-T8)
resources/views/
  auth/login.blade.php                # NEW (E1-T7)
  components/layouts/app.blade.php    # NEW (E1-T9)
tests/Feature/
  EnvironmentGuardTest.php            # NEW (E1-T1) tests never touch the dev DB
  SchemaConstraintsTest.php           # NEW (E1-T2)
  ModelsTest.php                      # NEW (E1-T4)
  TeacherCommandsTest.php             # NEW (E1-T5)
  SecurityHeadersTest.php             # NEW (E1-T6)
  AuthTest.php                        # NEW (E1-T7)
  LayoutShellTest.php                 # NEW (E1-T9) also scans every view for CSP violations
```

Removed by E1-T1 (skeleton leftovers): `package.json`, `vite.config.js`, `resources/js/`, `resources/css/`,
`resources/views/welcome.blade.php`, `tests/Feature/ExampleTest.php`, `tests/Unit/ExampleTest.php`, and the skeleton
migrations whose names end in `_create_cache_table.php` and `_create_jobs_table.php`. `tests/Unit` stays empty until
step 13; the full PHPUnit run still passes because `tests/Feature` holds tests (verified on this machine).

Everything outside this subtree is out of scope. If a task seems to require editing a file not listed here, stop
and report — it means the epic boundary is wrong.

## Data model touched here

All tables InnoDB, `utf8mb4`, `utf8mb4_unicode_ci`, MariaDB 10.3-10.11 syntax only.

| Entity | Fields this epic adds or reads | Notes |
|---|---|---|
| `users` (scaffold) | `id`, `name`, `email`, `password` | exactly one teacher row in real use; no roles |
| `sessions` (scaffold) | all | `SESSION_DRIVER=database`; `reset-password` deletes every row |
| `school_classes` | `id`, `name` varchar(60) UNIQUE, `subject_description` varchar(120) NULL, `period_label` varchar(40) NULL, `roster_cap` tinyint unsigned DEFAULT 30, `semester_start` DATE NULL, `semester_end` DATE NULL, timestamps | CHECK `roster_cap BETWEEN 1 AND 30`; CHECK `semester_end IS NULL OR semester_start IS NULL OR semester_end >= semester_start` |
| `students` | `id`, `school_class_id` FK cascade, `display_name` varchar(120), `student_number` varchar(40) NULL, `archived_at` timestamp NULL, timestamps | UNIQUE `students_id_class_unique (id, school_class_id)`; INDEX `(school_class_id, archived_at)` |
| `participation_entries` | `id`, `school_class_id`, `student_id`, `work_date` DATE, `status` enum('present','absent') DEFAULT 'present', `points` smallint unsigned NULL, `restore_points` smallint unsigned NULL, `revision` int unsigned DEFAULT 1, timestamps | UNIQUE `(school_class_id, student_id, work_date)`; composite FK `(student_id, school_class_id) → students(id, school_class_id)` cascade; FK class cascade; INDEX `(school_class_id, work_date)`; CHECK points ≤ 99, restore_points ≤ 99, `status = 'absent' OR points IS NOT NULL` |
| `participation_operations` | `seq` PK AI, `op_id` char(36) UNIQUE, `school_class_id` FK cascade, `work_date` DATE, `kind` varchar(20), `undone_at` NULL, `created_at` | INDEX `(school_class_id, work_date, seq)` |
| `participation_events` | `id`, `operation_seq` FK → `participation_operations(seq)` cascade, `school_class_id`, `student_id`, `before_exists` tinyint(1), `before_status` enum NULL, `before_points` NULL, `before_restore_points` NULL, `created_at` | append-only, used only by undo |

## Contracts

**Consumed** — already exists, do not rebuild:

| From | Interface | Guarantee |
|---|---|---|
| §10 Bootstrap | Docker services `db` and `app`, `.env` with `APP_KEY`, database `classpulse_test` granted to `classpulse` | `docker compose exec -T app php artisan --version` prints `Laravel Framework 13.x` |
| Laravel skeleton | `User` model, `UserFactory`, `sessions` table, `/up` health route | unchanged by this epic except where listed |

**Produced** — later epics depend on exactly these signatures. Changing one breaks them:

| Export | Signature | Used by |
|---|---|---|
| `config/classpulse.php` | keys `school_timezone` (string), `max_roster` (int 30), `max_points` (int 99), `import_max_kb` (int 256) | 02, 03 |
| `app/Models/SchoolClass.php` → `SchoolClass` | `students(): HasMany`, `activeStudents(): HasMany` (archived_at null), `activeStudentCount(): int`, `remainingCapacity(): int` | 02, 03 |
| `app/Models/Student.php` → `Student` | `schoolClass(): BelongsTo`, `scopeActive()`, `isArchived(): bool` | 02, 03 |
| `app/Models/ParticipationOperation.php` | primary key `seq`, `const UPDATED_AT = null` | 02 |
| `database/factories/*Factory.php` | `SchoolClass::factory()`, `Student::factory()` with state `archived()`, `ParticipationEntry::factory()` | 02, 03 (tests only) |
| `app/Http/Middleware/SecurityHeaders.php` | global; CSP string below; `Cache-Control: no-store, private` when authenticated | 02, 03 |
| `app/Support/CurrentClass.php` → `CurrentClass::resolve(?int $requested): ?SchoolClass` | session key `classpulse.current_class_id` | 02, 03 |
| `resources/views/components/layouts/app.blade.php` → `<x-layouts.app>` | props `title` (string), `active` (`daily`/`weekly`/`semester`/`roster`), `classes` (Collection), `currentClass` (?SchoolClass); slots default and `actions` | 02, 03 |
| routes | `GET/POST /login` (`name('login')`), `POST /logout`, `GET /` → `/daily` | 02, 03 |

## Conventions that bite in this area

- **Migration filenames are chosen by the tool.** Create with
  `docker compose exec -T app php artisan make:migration create_classpulse_schema` and edit the newest file in
  `database/migrations/`; never type a timestamp.
- **Content-Security-Policy has no `unsafe-inline`.** No `style="..."`, no `<style>`, no `<script>` without `src`,
  no `onclick=`, no `{!!`, no `@vite` in any Blade file. `LayoutShellTest` (E1-T9) fails the build on them.
- **CSRF middleware in Laravel 13 is `Illuminate\Foundation\Http\Middleware\PreventRequestForgery`**, already in the
  `web` group. Forms use `@csrf`. Laravel skips CSRF checks while running tests.
- **Login throttling is not automatic without a starter kit.** It is the named limiter `login` you define.
- **Never `migrate:fresh` the dev database.** The dev DB still holds the skeleton's `cache`/`jobs` tables from
  Bootstrap; they are harmless leftovers — leave them.
- **Messages the tests assert are the project's own literals**, never framework translations: write
  `These credentials do not match our records.` directly in the controller instead of `__('auth.failed')`.
- **Every test file names the method its step's gate lists.** Each step's `Verify` runs
  `vendor/bin/phpunit --list-tests <file> | grep -q '<Class>::<method>'`, so the method named in the task must exist
  with exactly that name (a renamed or empty test class fails the gate).
- Invented names only: `Alex Rivera`, `Jordan Lee`, `Robin Sky`, `Morgan Diaz`, `Casey Moon`.

Full project rules: `CLAUDE.md`. Area rules: `.claude/rules/database.md`, `.claude/rules/security.md`,
`.claude/rules/frontend.md`. Both sit in the project root — the builder copied them there from the bundle's
`workspace/` before task one.

---

## Tasks

Listed in the same order as `tasks.json`. That order is the build order — work top to bottom and do not re-rank by
priority or by what looks quick.

### `E1-T1` — Clean the skeleton and add ClassPulse configuration

**Depends on:** nothing · **Priority:** p0 — metadata for scope cuts, not a running order

Remove every Node/Vite leftover and the example scaffolding, then add the project configuration. Commands:
`rm -f package.json vite.config.js resources/views/welcome.blade.php tests/Feature/ExampleTest.php tests/Unit/ExampleTest.php database/migrations/*_create_cache_table.php database/migrations/*_create_jobs_table.php`,
then `test ! -d resources/js || rm -r resources/js` and `test ! -d resources/css || rm -r resources/css` (each guard
exits 0 when the directory is already gone). Run the `rm -f` line once: in zsh an unmatched glob makes it fail, so if the task is repeated, skip that line (the files are already gone). Write `config/classpulse.php` returning
`['school_timezone' => env('APP_TIMEZONE', 'America/Toronto'), 'max_roster' => 30, 'max_points' => 99, 'import_max_kb' => 256]`.
In `config/app.php` set `'timezone' => env('APP_TIMEZONE', 'America/Toronto'),`. In `composer.json` delete the
`scripts.setup` and `scripts.dev` entries (they call `npm`/`npx`); keep every other script. Replace `routes/web.php`
with `<?php` plus one comment line (`// Routes are added from step 7 on; /up is registered in bootstrap/app.php.`).
Write `tests/Feature/EnvironmentGuardTest.php` (extends `Tests\TestCase`, no `RefreshDatabase`) with one method
`test_suite_uses_the_test_database_and_toronto_time` asserting `config('database.default') === 'mariadb'`,
`DB::connection()->getDatabaseName() === 'classpulse_test'`, `config('app.timezone') === 'America/Toronto'`,
`config('classpulse.max_roster') === 30` and `config('classpulse.max_points') === 99`.

**Files**
- `config/classpulse.php` — new
- `config/app.php` — edit: timezone line
- `composer.json` — edit: remove `scripts.setup` and `scripts.dev`
- `routes/web.php` — edit: remove the welcome route
- `tests/Feature/EnvironmentGuardTest.php` — new
- Removed (not authored, not counted): the skeleton leftovers listed under the directory subtree

**Acceptance**

Copied verbatim from this task's `acceptance` array in `tasks.json`. Each one is decidable by a command below, on
this machine, during the build.

1. **WHEN** `docker compose exec -T app php artisan --version` runs **THE SYSTEM SHALL** print a line containing `Laravel Framework 13.` and exit 0.
2. **WHEN** `curl` requests `http://localhost:8090/up` **THE SYSTEM SHALL** return HTTP 200.
3. **WHEN** `vendor/bin/phpunit tests/Feature/EnvironmentGuardTest.php` runs in the app container **THE SYSTEM SHALL** assert that the connection is `mariadb`, the database is `classpulse_test`, `app.timezone` is `America/Toronto`, `classpulse.max_roster` is 30 and `classpulse.max_points` is 99, and exit 0.
4. **WHEN** the project root is listed **THE SYSTEM SHALL** contain no `package.json`, no `vite.config.js`, no `resources/js`, no `resources/css`, no `resources/views/welcome.blade.php` and no migration whose name contains `create_cache_table` or `create_jobs_table`.
5. **WHEN** `composer.json` is searched for `npm` or `npx` **THE SYSTEM SHALL** find 0 occurrences.
6. **WHEN** `vendor/bin/pint --test` runs in the app container **THE SYSTEM SHALL** exit 0.

**Verify** — every command, in order, run from the project root. Each one exits 0 when this task is correct; the
last one exiting 0 is what makes the task done.

```bash
docker compose exec -T app php artisan --version | grep -q 'Laravel Framework 13\.'
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/up)" = 200
docker compose exec -T app php artisan config:show app.timezone | grep -q 'America/Toronto'
docker compose exec -T app vendor/bin/phpunit tests/Feature/EnvironmentGuardTest.php
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/EnvironmentGuardTest.php | grep -q 'EnvironmentGuardTest::test_suite_uses_the_test_database_and_toronto_time'
test ! -e package.json && test ! -e vite.config.js && test ! -e resources/js && test ! -e resources/css && test ! -e resources/views/welcome.blade.php
test "$(ls database/migrations | grep -cE 'create_(cache|jobs)_table')" = 0
test "$(grep -cE 'npm|npx' composer.json)" = 0
docker compose exec -T app vendor/bin/pint --test
```

**Checkpoint**

```bash
git add -A && git commit -m "step 1: skeleton-config (E1-T1)"
git tag step-01-skeleton-config
```

Run both after the last `Verify` command exits 0, before starting the next task. The tag is this task's rollback
target and the thing the build's final gate counts. Never invent the tag: copy the `checkpoint` field.

### `E1-T2` — Create the domain schema migration and its constraint tests

**Depends on:** `E1-T1` · **Priority:** p0

Run `docker compose exec -T app php artisan make:migration create_classpulse_schema` and fill the newest file in
`database/migrations/` with the five tables below using the schema builder, then add the CHECK constraints with
`DB::statement` (MariaDB 10.11 enforces them). `down()` drops `participation_events`, `participation_operations`,
`participation_entries`, `students`, `school_classes` in that order.

```php
Schema::create('school_classes', function (Blueprint $table) {
    $table->id();
    $table->string('name', 60)->unique();
    $table->string('subject_description', 120)->nullable();
    $table->string('period_label', 40)->nullable();
    $table->unsignedTinyInteger('roster_cap')->default(30);
    $table->date('semester_start')->nullable();
    $table->date('semester_end')->nullable();
    $table->timestamps();
});
Schema::create('students', function (Blueprint $table) {
    $table->id();
    $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
    $table->string('display_name', 120);
    $table->string('student_number', 40)->nullable();
    $table->timestamp('archived_at')->nullable();
    $table->timestamps();
    $table->unique(['id', 'school_class_id'], 'students_id_class_unique');
    $table->index(['school_class_id', 'archived_at'], 'students_class_archived_index');
});
Schema::create('participation_entries', function (Blueprint $table) {
    $table->id();
    $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
    $table->unsignedBigInteger('student_id');
    $table->date('work_date');
    $table->enum('status', ['present', 'absent'])->default('present');
    $table->unsignedSmallInteger('points')->nullable();
    $table->unsignedSmallInteger('restore_points')->nullable();
    $table->unsignedInteger('revision')->default(1);
    $table->timestamps();
    $table->unique(['school_class_id', 'student_id', 'work_date'], 'participation_entries_class_student_date_unique');
    $table->index(['school_class_id', 'work_date'], 'participation_entries_class_date_index');
    $table->index(['student_id', 'school_class_id'], 'participation_entries_student_class_index');
    $table->foreign(['student_id', 'school_class_id'], 'participation_entries_student_class_foreign')
        ->references(['id', 'school_class_id'])->on('students')->cascadeOnDelete();
});
Schema::create('participation_operations', function (Blueprint $table) {
    $table->id('seq');
    $table->char('op_id', 36)->unique();
    $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
    $table->date('work_date');
    $table->string('kind', 20);
    $table->timestamp('undone_at')->nullable();
    $table->timestamp('created_at')->nullable();
    $table->index(['school_class_id', 'work_date', 'seq'], 'participation_operations_class_date_seq_index');
});
Schema::create('participation_events', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('operation_seq');
    $table->unsignedBigInteger('school_class_id');
    $table->unsignedBigInteger('student_id');
    $table->boolean('before_exists');
    $table->enum('before_status', ['present', 'absent'])->nullable();
    $table->unsignedSmallInteger('before_points')->nullable();
    $table->unsignedSmallInteger('before_restore_points')->nullable();
    $table->timestamp('created_at')->nullable();
    $table->foreign('operation_seq')->references('seq')->on('participation_operations')->cascadeOnDelete();
});
DB::statement('ALTER TABLE school_classes ADD CONSTRAINT school_classes_roster_cap_check CHECK (roster_cap BETWEEN 1 AND 30)');
DB::statement('ALTER TABLE school_classes ADD CONSTRAINT school_classes_semester_check CHECK (semester_end IS NULL OR semester_start IS NULL OR semester_end >= semester_start)');
DB::statement('ALTER TABLE participation_entries ADD CONSTRAINT participation_entries_points_check CHECK (points IS NULL OR points <= 99)');
DB::statement('ALTER TABLE participation_entries ADD CONSTRAINT participation_entries_restore_points_check CHECK (restore_points IS NULL OR restore_points <= 99)');
DB::statement("ALTER TABLE participation_entries ADD CONSTRAINT participation_entries_status_points_check CHECK (status = 'absent' OR points IS NOT NULL)");
```

In `database/seeders/DatabaseSeeder.php` replace the skeleton body (which creates a fixed `test@example.com` user)
with an empty `run()` and a comment pointing at `php artisan classpulse:demo-seed`.
`tests/Feature/SchemaConstraintsTest.php` (`RefreshDatabase`) works with `DB::table()` only — the models arrive in
E1-T3 — inserts rows and asserts `QueryException` for each rejected write, and counts rows after a class delete. One of
its methods is named `test_rejects_entry_for_student_of_another_class`.

**Files**
- `database/migrations/*_create_classpulse_schema.php` — new (the one file emitted by `make:migration`)
- `database/seeders/DatabaseSeeder.php` — edit: empty `run()`
- `tests/Feature/SchemaConstraintsTest.php` — new

**Acceptance**

1. **WHEN** `php artisan migrate --force` runs in the app container **THE SYSTEM SHALL** exit 0 and `php artisan migrate:status` SHALL list the `create_classpulse_schema` migration as `Ran`.
2. **WHEN** a `participation_entries` row pairs a student with a `school_class_id` other than that student's class **THE SYSTEM SHALL** reject the insert with a `QueryException`.
3. **WHEN** a second `participation_entries` row is inserted for the same `school_class_id`, `student_id` and `work_date` **THE SYSTEM SHALL** reject it with a `QueryException`.
4. **WHEN** an entry is written with `points` 100, or with `status` `present` and `points` NULL, or a class is written with `roster_cap` 31 or with `semester_end` before `semester_start` **THE SYSTEM SHALL** reject the write with a `QueryException`.
5. **WHEN** a `school_classes` row is deleted **THE SYSTEM SHALL** cascade-delete its students, entries, operations and events, leaving 0 rows that reference it.
6. **WHEN** `database/seeders/DatabaseSeeder.php` is searched for `password` or `@example.com` **THE SYSTEM SHALL** find 0 occurrences.

**Verify**

```bash
docker compose exec -T app php artisan migrate --force
docker compose exec -T app php artisan migrate:status | grep 'create_classpulse_schema' | grep -q 'Ran'
docker compose exec -T app vendor/bin/phpunit tests/Feature/SchemaConstraintsTest.php
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/SchemaConstraintsTest.php | grep -q 'SchemaConstraintsTest::test_rejects_entry_for_student_of_another_class'
test "$(grep -cE 'password|@example\.com' database/seeders/DatabaseSeeder.php)" = 0
docker compose exec -T app vendor/bin/pint --test
```

**Checkpoint**

```bash
git add -A && git commit -m "step 2: domain-schema (E1-T2)"
git tag step-02-domain-schema
```

### `E1-T3` — Add the five Eloquent models

**Depends on:** `E1-T2` · **Priority:** p0

Models in `app/Models/`, each with `use HasFactory;` and an explicit `protected $fillable` (never `$guarded`):
`SchoolClass` (`protected $table = 'school_classes';`; casts `semester_start`/`semester_end` to `date:Y-m-d`;
`students(): HasMany`, `activeStudents(): HasMany` = students with `archived_at` NULL, `activeStudentCount(): int`,
`remainingCapacity(): int` = `roster_cap - activeStudentCount()`), `Student` (casts `archived_at` to datetime;
`schoolClass(): BelongsTo`, `scopeActive($query)` = `whereNull('archived_at')`, `isArchived(): bool`),
`ParticipationEntry` (casts `work_date` to `date:Y-m-d`), `ParticipationOperation` (`protected $primaryKey = 'seq';`,
`const UPDATED_AT = null;`, casts `undone_at` to datetime) and `ParticipationEvent` (`const UPDATED_AT = null;`).
`php artisan model:show <Model>` resolves the short name inside `App\Models` and reads the dev database migrated in
E1-T2; it exits 1 for an unknown model (verified on this machine).

**Files**
- `app/Models/SchoolClass.php` — new
- `app/Models/Student.php` — new
- `app/Models/ParticipationEntry.php` — new
- `app/Models/ParticipationOperation.php` — new
- `app/Models/ParticipationEvent.php` — new

**Acceptance**

1. **WHEN** `php artisan model:show` runs in the app container for `SchoolClass`, `Student`, `ParticipationEntry`, `ParticipationOperation` and `ParticipationEvent` **THE SYSTEM SHALL** exit 0 for each and print the table name `school_classes`, `students`, `participation_entries`, `participation_operations` and `participation_events` respectively.
2. **WHEN** `php -l` runs in the app container on each of the five model files **THE SYSTEM SHALL** exit 0.
3. **WHEN** the five model files are searched **THE SYSTEM SHALL** find `protected $fillable` in every one of them and `guarded` in none of them.
4. **WHEN** `app/Models/ParticipationOperation.php` is searched **THE SYSTEM SHALL** find `primaryKey = 'seq';` and `UPDATED_AT = null;`.
5. **WHEN** `vendor/bin/pint --test` runs in the app container **THE SYSTEM SHALL** exit 0.

**Verify**

```bash
docker compose exec -T app php artisan model:show SchoolClass | grep -q 'school_classes'
docker compose exec -T app php artisan model:show Student | grep -q 'students'
docker compose exec -T app php artisan model:show ParticipationEntry | grep -q 'participation_entries'
docker compose exec -T app php artisan model:show ParticipationOperation | grep -q 'participation_operations'
docker compose exec -T app php artisan model:show ParticipationEvent | grep -q 'participation_events'
docker compose exec -T app php -l app/Models/SchoolClass.php
docker compose exec -T app php -l app/Models/Student.php
docker compose exec -T app php -l app/Models/ParticipationEntry.php
docker compose exec -T app php -l app/Models/ParticipationOperation.php
docker compose exec -T app php -l app/Models/ParticipationEvent.php
test "$(grep -LF 'protected $fillable' app/Models/SchoolClass.php app/Models/Student.php app/Models/ParticipationEntry.php app/Models/ParticipationOperation.php app/Models/ParticipationEvent.php | wc -l | tr -d ' ')" = 0
test "$(grep -l 'guarded' app/Models/SchoolClass.php app/Models/Student.php app/Models/ParticipationEntry.php app/Models/ParticipationOperation.php app/Models/ParticipationEvent.php | wc -l | tr -d ' ')" = 0
grep -qF "primaryKey = 'seq';" app/Models/ParticipationOperation.php && grep -qF 'UPDATED_AT = null;' app/Models/ParticipationOperation.php
docker compose exec -T app vendor/bin/pint --test
```

**Checkpoint**

```bash
git add -A && git commit -m "step 3: models (E1-T3)"
git tag step-03-models
```

### `E1-T4` — Add the factories and the model behaviour tests

**Depends on:** `E1-T3` · **Priority:** p0

Factories in `database/factories/`: `SchoolClassFactory` (`name` `'HNL '.fake()->unique()->numberBetween(10, 99)`,
`roster_cap` 30, semester dates null), `StudentFactory` (`school_class_id` `SchoolClass::factory()`, `display_name`
`fake()->firstName().' '.fake()->lastName()`, `student_number` null, `archived_at` null; state `archived()` sets
`archived_at` to `now()`), `ParticipationEntryFactory` (`school_class_id` `SchoolClass::factory()`, `student_id` a
closure that creates a `Student` in the attributes' `school_class_id`, `work_date` `'2026-10-19'`, `status`
`present`, `points` 0, `restore_points` null). `tests/Feature/ModelsTest.php` (`RefreshDatabase`) proves the model
helpers through the factories; one of its methods is named `test_active_student_count_and_remaining_capacity`.

**Files**
- `database/factories/SchoolClassFactory.php` — new
- `database/factories/StudentFactory.php` — new
- `database/factories/ParticipationEntryFactory.php` — new
- `tests/Feature/ModelsTest.php` — new

**Acceptance**

1. **WHEN** `SchoolClass::factory()->create()` runs **THE SYSTEM SHALL** store a class whose `roster_cap` is 30 and whose `name` starts with `HNL `.
2. **WHEN** a class with `roster_cap` 30 has 3 active students and 1 archived student **THE SYSTEM SHALL** return 3 from `activeStudentCount()`, 27 from `remainingCapacity()` and exactly the 3 active students from `activeStudents()`.
3. **WHEN** `Student::active()` is queried **THE SYSTEM SHALL** exclude every student whose `archived_at` is not null, and `isArchived()` SHALL return true only for those students.
4. **WHEN** `Student::factory()->archived()->create()` runs **THE SYSTEM SHALL** set `archived_at` and leave `student_number` null.
5. **WHEN** `ParticipationEntry::factory()->create()` runs **THE SYSTEM SHALL** store an entry with status `present`, 0 points and a student that belongs to the entry's class.
6. **WHEN** a `ParticipationOperation` is created through the model **THE SYSTEM SHALL** assign it an integer `seq` key and set `created_at` without writing an `updated_at` column.

**Verify**

```bash
docker compose exec -T app vendor/bin/phpunit tests/Feature/ModelsTest.php
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/ModelsTest.php | grep -q 'ModelsTest::test_active_student_count_and_remaining_capacity'
docker compose exec -T app vendor/bin/pint --test
```

**Checkpoint**

```bash
git add -A && git commit -m "step 4: factories (E1-T4)"
git tag step-04-factories
```

### `E1-T5` — Add the teacher account commands

**Depends on:** `E1-T1` · **Priority:** p0

Three commands in `app/Console/Commands/` (auto-discovered): `CreateTeacherCommand`
(`classpulse:create-teacher {email} {--name=Teacher}`: exit 1 with `A teacher account already exists.` if any user
exists; `$this->secret('Password')` then `$this->secret('Confirm password')`; exit 1 when shorter than 12 characters
or different; otherwise `User::create([... 'password' => Hash::make($password)])`, print
`Teacher account created for <email>.`, exit 0), `ResetPasswordCommand` (`classpulse:reset-password {email}`: exit 1
with `No account with that email.` when unknown; same two prompts and rules; update the hash;
`DB::table('sessions')->delete()`; print `Password updated. All sessions were signed out.`), `HashPasswordCommand`
(`classpulse:hash`: same two prompts; print only `Hash::make($password)`; used to paste a hash into phpMyAdmin when
the host has no SSH). `tests/Feature/TeacherCommandsTest.php` drives prompts with `expectsQuestion('Password', ...)`;
one of its methods is named `test_create_teacher_creates_exactly_one_user`. The third `Verify` line writes the
command list to the host temp file `/tmp/classpulse-commands.txt` and greps it.

**Files**
- `app/Console/Commands/CreateTeacherCommand.php` — new
- `app/Console/Commands/ResetPasswordCommand.php` — new
- `app/Console/Commands/HashPasswordCommand.php` — new
- `tests/Feature/TeacherCommandsTest.php` — new

**Acceptance**

1. **WHEN** `classpulse:create-teacher teacher@classpulse.test --name=Teacher` runs on an empty `users` table and the password prompt and its confirmation receive the same value of 12 or more characters **THE SYSTEM SHALL** create exactly 1 user whose password verifies with `Hash::check` and exit 0.
2. **WHEN** `classpulse:create-teacher` runs while any user exists, or the password is shorter than 12 characters, or the confirmation differs **THE SYSTEM SHALL** exit 1 and leave the `users` row count unchanged.
3. **WHEN** `classpulse:reset-password teacher@classpulse.test` completes **THE SYSTEM SHALL** store a hash that verifies the new password and leave 0 rows in `sessions`, and for an unknown email it SHALL exit 1.
4. **WHEN** `classpulse:hash` receives a password **THE SYSTEM SHALL** print a bcrypt hash containing `$2y$` and SHALL NOT print the password text.

**Verify**

```bash
docker compose exec -T app vendor/bin/phpunit tests/Feature/TeacherCommandsTest.php
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/TeacherCommandsTest.php | grep -q 'TeacherCommandsTest::test_create_teacher_creates_exactly_one_user'
docker compose exec -T app php artisan list classpulse > /tmp/classpulse-commands.txt && grep -q 'classpulse:create-teacher' /tmp/classpulse-commands.txt && grep -q 'classpulse:reset-password' /tmp/classpulse-commands.txt && grep -q 'classpulse:hash' /tmp/classpulse-commands.txt
docker compose exec -T app vendor/bin/pint --test
```

**Checkpoint**

```bash
git add -A && git commit -m "step 5: teacher-commands (E1-T5)"
git tag step-05-teacher-commands
```

### `E1-T6` — Add the global security headers middleware

**Depends on:** `E1-T1` · **Priority:** p0

`app/Http/Middleware/SecurityHeaders.php` runs after `$next($request)` and sets
`Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'`,
`X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`,
`Permissions-Policy: camera=(), microphone=(), geolocation=()`, `Strict-Transport-Security: max-age=31536000` only when
`$request->isSecure()`, and `Cache-Control: no-store, private` when `$request->user()` is not null (asserted in E1-T7).
Register it in `bootstrap/app.php` with `$middleware->append(\App\Http\Middleware\SecurityHeaders::class);` inside
`withMiddleware`. `tests/Feature/SecurityHeadersTest.php` requests `/up` over `http://` and `https://localhost/up`; one
of its methods is named `test_http_response_carries_csp_and_no_hsts`.

**Files**
- `app/Http/Middleware/SecurityHeaders.php` — new
- `bootstrap/app.php` — edit: append the middleware
- `tests/Feature/SecurityHeadersTest.php` — new

**Acceptance**

1. **WHEN** any response is served over plain HTTP **THE SYSTEM SHALL** carry `Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin` and `Permissions-Policy: camera=(), microphone=(), geolocation=()`, and no `Strict-Transport-Security` header.
2. **WHEN** a response is served over HTTPS **THE SYSTEM SHALL** add `Strict-Transport-Security: max-age=31536000`.
3. **WHEN** `curl -sI http://localhost:8090/up` runs against the live server **THE SYSTEM SHALL** receive an `X-Content-Type-Options: nosniff` header and a `Content-Security-Policy` header starting with `default-src 'self'`.

**Verify**

```bash
docker compose exec -T app vendor/bin/phpunit tests/Feature/SecurityHeadersTest.php
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/SecurityHeadersTest.php | grep -q 'SecurityHeadersTest::test_http_response_carries_csp_and_no_hsts'
curl -sI http://localhost:8090/up | grep -qi '^x-content-type-options: nosniff'
curl -sI http://localhost:8090/up | grep -qi "^content-security-policy: default-src 'self'"
docker compose exec -T app vendor/bin/pint --test
```

**Checkpoint**

```bash
git add -A && git commit -m "step 6: security-headers (E1-T6)"
git tag step-06-security-headers
```

### `E1-T7` — Add login, logout, throttling and route protection

**Depends on:** `E1-T4`, `E1-T5`, `E1-T6` · **Priority:** p0

`LoginController` with `show` (view `auth.login`), `store` (validate `email` required|email and `password`
required|string with `$request->validate()`; `Auth::attempt`; on success `$request->session()->regenerate()` and
`redirect()->intended('/daily')`; on failure `back()->withErrors(['email' => 'These credentials do not match our records.'])->onlyInput('email')`)
and `destroy` (`Auth::logout()`, `session()->invalidate()`, `session()->regenerateToken()`, redirect `/login`).
In `AppServiceProvider::boot` define
`RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip())->response(fn (Request $request, array $headers) => response()->view('auth.login', ['throttleSeconds' => $headers['Retry-After'] ?? 60], 429, $headers)))`;
the view then shows `Too many sign-in attempts. Try again in N seconds.` (N from `throttleSeconds`).
`routes/web.php`: `guest` group with `GET /login` (`name('login')`) and `POST /login` (`->middleware('throttle:login')`);
`auth` group with `POST /logout` (`name('logout')`) and `Route::redirect('/', '/daily')`. No register or reset routes.
`resources/views/auth/login.blade.php` is a complete HTML page (`<html lang="en">`, `<meta name="csrf-token">`,
`<script src="{{ asset('js/theme-init.js') }}"></script>` then `css/tokens.css` and `css/app.css` links — those files
arrive in E1-T8 and 404 until then, which no test checks), one `<h1>Sign in to ClassPulse</h1>`, labelled email
(`autocomplete="username"`) and password (`autocomplete="current-password"`) fields, errors in an element with
`role="alert"`, never blocking paste, and no inline style or script. `tests/Feature/AuthTest.php` has a method named
`test_sixth_attempt_is_throttled`.

**Files**
- `app/Http/Controllers/Auth/LoginController.php` — new
- `resources/views/auth/login.blade.php` — new
- `routes/web.php` — edit: auth routes
- `app/Providers/AppServiceProvider.php` — edit: `login` limiter
- `tests/Feature/AuthTest.php` — new

**Acceptance**

1. **WHEN** a guest requests `/` **THE SYSTEM SHALL** redirect to `/login`, and `/login` SHALL return 200 with an email field, a password field and the `Content-Security-Policy` header.
2. **WHEN** valid credentials are posted to `/login` **THE SYSTEM SHALL** authenticate the teacher, regenerate the session id and redirect to `/daily`.
3. **WHEN** an invalid password is posted to `/login` **THE SYSTEM SHALL** redirect back with the error `These credentials do not match our records.` and remain a guest.
4. **WHEN** a 6th login attempt for the same email and IP arrives within one minute **THE SYSTEM SHALL** respond 429 with a `Retry-After` header.
5. **WHEN** an authenticated teacher posts to `/logout` **THE SYSTEM SHALL** end the session, rotate the CSRF token and redirect to `/login`, and every authenticated response SHALL carry a `Cache-Control` header containing `no-store`.
6. **WHEN** `/register`, `/forgot-password` or `/reset-password/abc` is requested **THE SYSTEM SHALL** return 404.

**Verify**

```bash
docker compose exec -T app vendor/bin/phpunit tests/Feature/AuthTest.php
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/AuthTest.php | grep -q 'AuthTest::test_sixth_attempt_is_throttled'
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/login)" = 200
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/)" = 302
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/register)" = 404
docker compose exec -T app vendor/bin/pint --test
```

**Checkpoint**

```bash
git add -A && git commit -m "step 7: login (E1-T7)"
git tag step-07-login
```

### `E1-T8` — Write the design tokens, component CSS and theme scripts

**Depends on:** `E1-T7` · **Priority:** p0

`public/css/tokens.css` defines every token as a CSS custom property: light values on `:root`, dark values under
`[data-theme="dark"]` and under `@media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) { ... } }`
(write the media query exactly as `prefers-color-scheme: dark`, one space after the colon). The hex values are the
step 9 token list below. `public/css/app.css` holds every component class the app will use (header, tabs with
`[aria-current="page"]`, buttons `.btn`/`.btn-primary`/`.btn-danger`/`.btn-ghost`, save pill states, card grid
`grid-template-columns: repeat(auto-fill, minmax(240px, 1fr))`, student card and its absent/not-recorded variants,
56x56 score buttons, KPI tiles, weekly matrix and heat classes `.heat-0` `.heat-low` `.heat-mid` `.heat-high`
`.heat-absent`, legend, tables, `dialog`, `.search-match` outline, `mark`, `.skip-link`, `.visually-hidden`,
`.empty-state`, a 3px focus ring with 2px offset, 120ms ease-out transitions on transform/opacity removed under
`@media (prefers-reduced-motion: reduce)`, breakpoints 640px and 1024px, max width 1440px). `app.css` contains no `#`
character at all: colours come only from `var(--token)` and elements are styled by class and attribute, never by id.
`public/js/theme-init.js` (blocking, in `<head>`): inside try/catch read `localStorage.getItem('classpulse-theme')`
and, if `light` or `dark`, set `document.documentElement.dataset.theme`. `public/js/theme.js` (`defer`): wires every
`[data-theme-toggle]` button to flip the theme, store it under `classpulse-theme` in try/catch and update the button's
`aria-label` (`Switch to dark theme` / `Switch to light theme`); also auto-submits the class selector form on change.
Both scripts are classic scripts: no `import`/`export`, 2-space indent, single quotes. `node --check` in the `jstest`
container parses them without executing them (exit 0 valid, 1 syntax error — verified on this machine).

**Files**
- `public/css/tokens.css` — new
- `public/css/app.css` — new
- `public/js/theme-init.js` — new
- `public/js/theme.js` — new

**Acceptance**

1. **WHEN** `curl` requests `http://localhost:8090/css/tokens.css`, `/css/app.css`, `/js/theme-init.js` and `/js/theme.js` **THE SYSTEM SHALL** return HTTP 200 for each.
2. **WHEN** `node --check` runs in the `jstest` container on `public/js/theme-init.js` and `public/js/theme.js` **THE SYSTEM SHALL** exit 0 for each.
3. **WHEN** `public/css/tokens.css` is read **THE SYSTEM SHALL** contain `[data-theme="dark"]`, `:root:not([data-theme="light"])` and `prefers-color-scheme: dark`.
4. **WHEN** `public/css/app.css` is searched **THE SYSTEM SHALL** find 0 `#` characters (no raw hex colour and no id selector) and SHALL find `prefers-reduced-motion: reduce`.
5. **WHEN** `public/js/theme-init.js` and `public/js/theme.js` are read **THE SYSTEM SHALL** find the key `classpulse-theme` in both and no line starting with `import ` or `export ` in either.

**Verify**

```bash
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/css/tokens.css)" = 200
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/css/app.css)" = 200
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/js/theme-init.js)" = 200
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/js/theme.js)" = 200
docker compose --profile test run --rm jstest node --check public/js/theme-init.js
docker compose --profile test run --rm jstest node --check public/js/theme.js
grep -qF '[data-theme="dark"]' public/css/tokens.css && grep -qF ':root:not([data-theme="light"])' public/css/tokens.css && grep -qF 'prefers-color-scheme: dark' public/css/tokens.css
test "$(grep -c '#' public/css/app.css)" = 0 && grep -qF 'prefers-reduced-motion: reduce' public/css/app.css
grep -qF 'classpulse-theme' public/js/theme-init.js && grep -qF 'classpulse-theme' public/js/theme.js
test -z "$(grep -hcE '^(import|export) ' public/js/theme-init.js public/js/theme.js | grep -v '^0$')"
```

**Checkpoint**

```bash
git add -A && git commit -m "step 8: design-assets (E1-T8)"
git tag step-08-design-assets
```

### `E1-T9` — Build the app layout shell and the current-class resolver

**Depends on:** `E1-T8` · **Priority:** p0

`resources/views/components/layouts/app.blade.php` renders `<html lang="en">`, `<meta name="csrf-token">`, the
`theme-init.js` script (no `defer`/`async`) before both stylesheets, `theme.js` with `defer`, a skip link to
`#main`, the brand `ClassPulse`, a GET class selector form (`<select name="class">` listing `classes`, the current
one `selected`, a visible `Switch` submit button, and the current class name shown as a course-code chip), nav tabs
`Daily Tracker` (`/daily`), `Weekly Matrix` (`/weekly`), `Semester Analytics` (`/semester`),
`Class Roster & Settings` (`/roster`) with `aria-current="page"` on `active`, an `actions` slot, the theme toggle
(`data-theme-toggle`, `aria-label="Toggle color theme"`), a logout form, and `<main id="main">`. Nav links use
`url('/daily')`-style paths, never `route()` names, because those routes arrive in later steps.
`app/Support/CurrentClass.php`: `resolve(?int $requested): ?SchoolClass` — a valid requested id wins and is stored in
the session under `classpulse.current_class_id`; otherwise the session id if the class still exists; otherwise the
first class ordered by `name`; otherwise null.
`tests/Feature/LayoutShellTest.php` renders the component with `$this->blade('<x-layouts.app title="Probe" active="daily" :classes="$classes" :current-class="$current">Body</x-layouts.app>', [...])`,
unit-tests `CurrentClass::resolve`, scans every file under `resources/views` with regexes for `style=`, `<style`,
`<script(?![^>]*\bsrc=)`, `\son[a-z]+=`, `{!!` and `@vite` (method `test_views_contain_no_inline_script_or_style`),
and checks that `public/css/tokens.css` contains each of these hex values (case-insensitive):
`#0B1120 #111A2E #182440 #2A3757 #6F7EA8 #E8ECF6 #A3AECB #8B7CFF #1E1B4B #34D399 #0F3B2E #F87171 #3B1620 #FBBF24`
`#F5F6FB #FFFFFF #ECEEF7 #C9D0E3 #76819F #141A2E #4A5675 #5140D8 #E7E4FF #166534 #DCFCE7 #B42318 #FEE4E2 #92400E`
`#2B2A66 #1F4D5C #0F5B3F #5A1E2B #FFE4E8 #DDD8FF #C9EEF4 #BBF0D2 #FAD4D8 #7A1020` — this is the step 9 token list.

**Files**
- `resources/views/components/layouts/app.blade.php` — new
- `app/Support/CurrentClass.php` — new
- `tests/Feature/LayoutShellTest.php` — new

**Acceptance**

1. **WHEN** the `x-layouts.app` component renders for an authenticated teacher **THE SYSTEM SHALL** output the nav tabs `Daily Tracker`, `Weekly Matrix`, `Semester Analytics` and `Class Roster & Settings`, a theme toggle button with an accessible name, and a skip link to `#main`.
2. **WHEN** the layout renders **THE SYSTEM SHALL** load `/js/theme-init.js` in `<head>` without `defer` or `async`, before the `/css/tokens.css` and `/css/app.css` stylesheets.
3. **WHEN** three classes exist and `CurrentClass::resolve` receives a valid class id **THE SYSTEM SHALL** return that class and store its id in the session, with no id it SHALL return the session's class, with a stale id it SHALL fall back to the first class by name, and with no classes it SHALL return null.
4. **WHEN** `LayoutShellTest` scans every file under `resources/views` **THE SYSTEM SHALL** find 0 occurrences of `style=`, `<style`, a `<script>` tag without `src`, an `on*=` event attribute, `{!!` or `@vite`.
5. **WHEN** `LayoutShellTest` reads `public/css/tokens.css` **THE SYSTEM SHALL** find every hex value of the ClassPulse palette listed in the step 9 token list, compared case-insensitively.

**Verify**

```bash
docker compose exec -T app vendor/bin/phpunit tests/Feature/LayoutShellTest.php
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/LayoutShellTest.php | grep -q 'LayoutShellTest::test_views_contain_no_inline_script_or_style'
docker compose exec -T app vendor/bin/pint --test
```

**Checkpoint**

```bash
git add -A && git commit -m "step 9: layout-shell (E1-T9)"
git tag step-09-layout-shell
```

---

## Epic acceptance

The epic is done when every task is `done` **and**:

1. **WHEN** the full PHP suite runs **THE SYSTEM SHALL** exit 0 with 0 failures and 0 errors, including `EnvironmentGuardTest` proving the suite used `classpulse_test`.
2. **WHEN** a guest requests `/` **THE SYSTEM SHALL** redirect to `/login`, and `/login` SHALL carry the Content-Security-Policy header.

```bash
docker compose exec -T app vendor/bin/pint --test && docker compose exec -T app vendor/bin/phpunit
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/)" = 302
curl -sI http://localhost:8090/login | grep -qi '^content-security-policy: default-src'
```

Run from the project root. Both criteria are decided by these commands.

## Pitfalls

- **Skeleton files come back if you re-run Bootstrap?** No: the scaffold only runs when `artisan` does not boot, and
  its copy uses `tar --skip-old-files`. Deleted skeleton files stay deleted.
- **`phpunit.xml` is ours, not the skeleton's.** It forces `DB_DATABASE=classpulse_test` and sets
  `failOnEmptyTestSuite="true"`. If a test ever reports `classpulse` as the database, stop: the dev data is at risk.
- **Composite foreign keys need the parent unique key first.** Create `students_id_class_unique` in the same
  `Schema::create('students')` call, before `participation_entries` references it.
- **A CHECK constraint cannot be expressed with the schema builder** — use `DB::statement` with a fixed name.
- **`SchemaConstraintsTest` must not import a model** — it runs at step 2, before the models exist.
- **`$request->user()` in the global middleware** runs after `$next()`; that is why `no-store` is set there.
- **Never add a register or password-reset route**, not even a disabled one; tests assert 404.
- **`app.css` has no `#` at all** — not even in a comment; step 8's gate counts them.

## Before moving on

- [ ] Every task in this epic is `done` in `tasks.json` — no task left `in_progress`.
- [ ] Every `verify` command of every task in this epic passed, not just the first one.
- [ ] No `verify` command was edited, and none was skipped because a file it names did not exist.
- [ ] **Every task in this epic has its `checkpoint` tag in version control** — one tag per task, matching the
      `checkpoint` value in `tasks.json`. `git tag -l 'step-0*'` lists `step-01-skeleton-config` through
      `step-09-layout-shell`.
- [ ] Gate command passes clean, run from the project root.
- [ ] Every "Produced" contract above exists with the stated signature.
- [ ] No file outside the subtree was modified.
- [ ] `.env.example` unchanged — this epic adds no environment variable.
- [ ] One commit per task, each message containing its task id, each followed by its checkpoint tag.
