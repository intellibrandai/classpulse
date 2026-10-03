---
name: add-participation-operation
description: Use when changing how points, absences, batches or undo behave in ClassPulse - "new operation kind", "change increment", "fix undo", "add a batch action". Keeps ParticipationService the single writer, keeps operations idempotent by op_id, and extends the service and API tests.
---

# Add or change a participation operation

## When to use
- Adding a new `kind` to `POST /api/classes/{class}/days/{date}/operations`.
- Changing the state transitions of an existing kind (`increment`, `decrement`, `set_zero`, `set_points`, `clear`,
  `absent_on`, `absent_off`, `zero_remaining`, `reset_day`, `undo`).

## Steps
1. Write the transition table first (state NR = no row, P(n) = present with n, A(r) = absent remembering r) and the
   error code for every illegal state. Error codes map to HTTP 422 (input/state limits) or 409 (conflicts).
2. Implement it only in `app/Services/ParticipationService.php`, inside the existing transaction that starts with
   `SchoolClass::whereKey($class->id)->lockForUpdate()->first()`.
3. Insert one `participation_operations` row (with the client `op_id`) and one `participation_events` row per
   affected student holding `before_exists`, `before_status`, `before_points`, `before_restore_points`.
4. Add the kind to the `in:` rule of `app/Http/Requests/DayOperationRequest.php` and, if it targets a student, to the
   `required_if` list for `student_id`.
5. Add service tests (happy path, every illegal state, undo of the new kind, replay of the same `op_id`) and one API
   test in `tests/Feature/DayApiTest.php`.
6. If the Daily page needs a card button, add the kind to `CARD_ACTIONS` in `public/js/daily.js` and the matching
   `data-action` in `resources/views/daily/card.blade.php`, then add a new `grep` check for that value beside the
   existing DOM-contract checks (never loosen or delete an existing check). The click goes through the save queue.
   Never compute totals in JS.

## Verify
`/tmp` is the host's temp directory; the JS line requires at least one passing test because an empty run exits 0.

```bash
docker compose exec -T app vendor/bin/phpunit tests/Feature/ParticipationServiceTest.php     # expect: exit 0
docker compose exec -T app vendor/bin/phpunit tests/Feature/ParticipationBatchUndoTest.php   # expect: exit 0
docker compose exec -T app vendor/bin/phpunit tests/Feature/DayApiTest.php                   # expect: exit 0
docker compose --profile test run --rm jstest > /tmp/classpulse-jstest.txt && grep -qE '(ℹ|#) pass [1-9]' /tmp/classpulse-jstest.txt && grep -qE '(ℹ|#) fail 0' /tmp/classpulse-jstest.txt   # expect: exit 0
docker compose --profile test run --rm jstest node --check public/js/daily.js               # expect: exit 0
```

## Do not
- Write `participation_entries` from a controller, a command or a seeder.
- Accept an absolute points value from the client anywhere except `set_points` (single student, bounds 0..99 enforced in the service, never in the controller).
- Skip the event rows: an operation without events cannot be undone.
