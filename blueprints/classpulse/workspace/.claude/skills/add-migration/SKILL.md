---
name: add-migration
description: Use when a ClassPulse table, column, index or constraint must change - "add a column", "new table", "change the schema", "add an index". Creates a new timestamped Laravel migration in Docker, applies it to the dev database and proves it on the test database. Never edits a migration that already ran.
---

# Add a migration

## When to use
- Any change to the MariaDB schema: new table, new column, new index, new CHECK constraint.
- Never for data fixes inside the app (those go through `App\Services\ParticipationService`).

## Steps
1. Generate the file (the tool picks the timestamp; never type it):
   `docker compose exec -T app php artisan make:migration add_student_number_index_to_students_table`
2. Edit the newest file in `database/migrations/` only. Use the schema builder; add CHECK constraints with
   `DB::statement('ALTER TABLE students ADD CONSTRAINT students_name_length_check CHECK (CHAR_LENGTH(display_name) <= 120)')`
   style statements with a fixed constraint name. Write a `down()` that reverses it.
3. Use only MariaDB 10.3-10.11 syntax (the Hostinger version is unpublished).
4. Update the matching model's `$fillable`, casts and factory if a column was added.
5. Apply to the dev database: `docker compose exec -T app php artisan migrate --force`.
6. Add or extend a test in `tests/Feature/SchemaConstraintsTest.php` that proves the new constraint rejects a bad row.

## Verify
`/tmp` below is the host's temp directory. The second line fails when the container is down (the `&&` stops at the
failed `exec`) and when any migration is still `Pending`.

```bash
docker compose exec -T app php artisan migrate --force                         # expect: exit 0
docker compose exec -T app php artisan migrate:status > /tmp/status.txt && ! grep -q Pending /tmp/status.txt   # expect: exit 0 — status read, no Pending row
docker compose exec -T app vendor/bin/phpunit tests/Feature/SchemaConstraintsTest.php   # expect: exit 0
docker compose exec -T app vendor/bin/pint --test                              # expect: exit 0
```

## Do not
- Edit or delete a migration that has already run anywhere, including `0001_01_01_000000_create_users_table.php`.
- Run `migrate:fresh` against the dev database `classpulse`.
- Invent the timestamp prefix of a migration filename.
