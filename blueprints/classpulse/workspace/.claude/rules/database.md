---
description: MariaDB schema, migrations and Eloquent conventions for ClassPulse
paths:
  - "database/**"
  - "app/Models/**"
---

# Database rules

- Target MariaDB >= 10.3 syntax only. Local is `mariadb:10.11`; the Hostinger version is unpublished, so never use a
  feature introduced after 10.11 (no `UUID` or `INET4` column types, no `VECTOR` columns).
- Every table is InnoDB, `utf8mb4`, `utf8mb4_unicode_ci`.
- Never edit a migration that has run in any database (dev `classpulse`, test `classpulse_test`, release
  `classpulse_release`). A change is a new migration, created with
  `docker compose exec -T app php artisan make:migration add_student_number_index_to_students_table` (example name).
  The tool chooses the timestamp prefix; never type one by hand.
- `migrate:fresh` is only ever run by `RefreshDatabase` in tests. Never against `classpulse`.
- CHECK constraints are added inside the migration with a fixed name `<table>_<rule>_check`, for example
  `DB::statement('ALTER TABLE participation_entries ADD CONSTRAINT participation_entries_points_check CHECK (points IS NULL OR points <= 99)')`.
  These statements contain no user input.
- `participation_entries` identity is `UNIQUE (school_class_id, student_id, work_date)` plus the composite FK
  `(student_id, school_class_id) -> students(id, school_class_id)`. Never drop either.
- A present row always has points (`status = 'absent' OR points IS NOT NULL`). "Not recorded" means no row.
- Business dates are `DATE` columns holding the America/Toronto calendar date, computed only by
  `App\Support\SchoolCalendar`.
- Every model sets `$fillable` explicitly. No `$guarded = []`.
- Only `App\Services\ParticipationService` writes `participation_entries`, `participation_operations` and
  `participation_events`. Factories may create entries in tests only.
- Every query on students or entries is scoped by `school_class_id`.
- Seeders and factories use invented names only (for example `Alex Rivera`, `Jordan Lee`). Never a real student,
  never a fixed password.
