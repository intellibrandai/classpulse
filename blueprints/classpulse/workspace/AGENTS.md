# ClassPulse — agent instructions

Private participation and attendance tracker for Ontario teachers: Laravel 13 + Blade + MariaDB, run locally only through
Docker Compose (project name `classpulse`). PHP, Composer, MariaDB and Node are not installed on the host.

## Commands

| Task | Command |
|---|---|
| Start services | `docker compose up -d --build` (web http://localhost:8090) |
| Migrate dev DB | `docker compose exec -T app php artisan migrate --force` |
| All PHP tests | `docker compose exec -T app vendor/bin/phpunit` |
| One test file | `docker compose exec -T app vendor/bin/phpunit tests/Feature/DayApiTest.php` |
| JS unit tests | `docker compose --profile test run --rm jstest` |
| JS syntax check | `docker compose --profile test run --rm jstest node --check public/js/daily.js` |
| Format check | `docker compose exec -T app vendor/bin/pint --test` |
| Release build + proof | `./scripts/package-release.sh && ./scripts/verify-release.sh` |

**Gate:** each line exits 0 before a task is done (the JS line applies from step 18; it greps the pass count because
a run with no test file prints `pass 0` and still exits 0; `/tmp` is the host's temp directory):

```bash
docker compose exec -T app vendor/bin/pint --test
docker compose exec -T app vendor/bin/phpunit
docker compose --profile test run --rm jstest > /tmp/classpulse-jstest.txt && grep -qE '(ℹ|#) pass [1-9]' /tmp/classpulse-jstest.txt && grep -qE '(ℹ|#) fail 0' /tmp/classpulse-jstest.txt
```

Build order: `blueprints/classpulse/tasks.json`.

## Non-negotiable

1. Touch only the `classpulse` Compose project; never stop, remove or prune other containers or volumes; never
   `docker compose down -v` without asking.
2. Never commit `.env` or a real credential.
3. Never add Node, npm, Vite, a bundler, a CDN, a web font or any AI service.
4. No inline scripts, inline styles or `{!!` in Blade views (CSP).
5. Only invented student names in seeds, factories, fixtures and tests.
6. Never publish to Hostinger; the owner approves and publishes.
7. Never mark a task done with a failing gate, and never edit a verify command.

Full architecture, boundaries, design tokens and path-scoped rules: `CLAUDE.md` and `.claude/rules/` in this
directory. `CLAUDE.md` is the source of truth.
