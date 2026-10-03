# ClassPulse

Private "digital participation clipboard" for Ontario teachers (America/Toronto): tap cards per student per
school day, weekly and semester analytics, CSV import/export. Laravel 13 + Blade + MariaDB, local only in Docker.

## Commands

Run from the project root. PHP, Composer, MariaDB and Node are NOT installed on the host: always use Docker.

| Task | Command |
|---|---|
| Start services | `docker compose up -d --build` (web http://localhost:8090, DB 127.0.0.1:33061) |
| First-time local setup | `./scripts/setup-local.sh --demo` (idempotent: up, composer install, key, test DB, migrate, demo seed) |
| Status / logs | `docker compose ps` · `docker compose logs app` |
| Stop (keeps data) | `docker compose stop` |
| Artisan | `docker compose exec -T app php artisan route:list` (any artisan command) |
| Migrate dev DB | `docker compose exec -T app php artisan migrate --force` |
| All PHP tests | `docker compose exec -T app vendor/bin/phpunit` |
| One test file | `docker compose exec -T app vendor/bin/phpunit tests/Feature/DayApiTest.php` |
| One test method | `docker compose exec -T app vendor/bin/phpunit --filter test_replayed_op_id_changes_nothing` |
| List a file's tests | `docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/DayApiTest.php` |
| JS unit tests | `docker compose --profile test run --rm jstest` (node --test, zero packages) |
| JS syntax check | `docker compose --profile test run --rm jstest node --check public/js/daily.js` |
| Format check / fix | `docker compose exec -T app vendor/bin/pint --test` · `docker compose exec -T app vendor/bin/pint` |
| Health | `curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/up` prints `200` |
| Demo data (local only) | `docker compose exec -T app php artisan classpulse:demo-seed` |
| Teacher account | `docker compose exec app php artisan classpulse:create-teacher teacher@classpulse.test` (interactive) |
| Release build + proof | `./scripts/package-release.sh && ./scripts/verify-release.sh` |
| Re-apply workspace | `rsync -a --ignore-existing blueprints/classpulse/workspace/ ./` (never overwrites) |
| Reset dev DB | ask the user first: it deletes all local data |

**Gate:** each line exits 0 before any task is marked done. The JS line applies from step 18, when `tests/js/`
exists; it greps the pass count because a run with no test file prints `pass 0` and still exits 0 (`/tmp` is the
host's temp directory). `phpunit.xml` sets `failOnEmptyTestSuite="true"`.

```bash
docker compose exec -T app vendor/bin/pint --test
docker compose exec -T app vendor/bin/phpunit
docker compose --profile test run --rm jstest > /tmp/classpulse-jstest.txt && grep -qE '(ℹ|#) pass [1-9]' /tmp/classpulse-jstest.txt && grep -qE '(ℹ|#) fail 0' /tmp/classpulse-jstest.txt
```

Build order: `blueprints/classpulse/tasks.json` (resume protocol) and `blueprints/classpulse/epics/NN-*.md`.
Dependency versions live in `composer.lock`; Docker image tags live in `Dockerfile` and `docker-compose.yml`.

## Stack

PHP 8.3 (Docker `php:8.3-cli-bookworm`) · Laravel 13 (skeleton `laravel/laravel` 13.10.1, pinned in Bootstrap) ·
Blade · hand-written CSS/JS (no build step) · self-hosted Plus Jakarta Sans + JetBrains Mono (`public/fonts`) · MariaDB 10.11 locally · Eloquent · session auth (no starter kit) ·
PHPUnit 12 · Pint · `node:24-alpine` only as a JS test and syntax-check container.
Target host: Hostinger shared PHP hosting, Layout B (app folder beside `public_html`), published only by the owner.

## Architecture

**Request path (write).** browser `public/js/daily.js` → `public/js/save-queue.js` (one op in flight, UUID `op_id`)
→ `POST /api/classes/{class}/days/{date}/operations` in `routes/web.php` → `app/Http/Requests/DayOperationRequest.php`
→ `app/Http/Controllers/Api/DayController.php` → `app/Services/ParticipationService.php` (transaction, class row
`lockForUpdate`, events for undo) → `app/Models/*` → MariaDB. The response is the full day state; JS re-renders from it.

**Request path (read).** `GET /weekly` → `app/Http/Controllers/WeeklyController.php` → `app/Support/ParticipationStats.php`
(pure, plain arrays) → `resources/views/reports/weekly.blade.php`; CSV via `app/Support/CsvWriter.php`.

**Boundaries.**

| Layer | May use | Must never |
|---|---|---|
| `app/Http/Controllers/**` | Requests, Services, Support, Models (reads) | write entries/operations/events |
| `app/Services/ParticipationService.php` | Models, `SchoolCalendar`, DB transactions | read the HTTP request |
| `app/Support/**` | plain PHP, Carbon | touch the DB or the request (except `CurrentClass`, which reads session) |
| `resources/views/**` | `{{ }}`, Blade components | `{!! !!}`, inline `<script>`/`<style>`/`style=""` |
| `public/js/**` | DOM, `fetch`, `globalThis.ClassPulseSaveQueue` | `import`/`export`, compute stored totals, store data in `localStorage` (only exceptions: the theme choice and the login "Remember my email" address, `login-remember.js`) |
| `tests/**` | factories, `Carbon::setTestNow` | the dev DB `classpulse`, the network |

**Where things live.**

| Concern | Single source of truth |
|---|---|
| Schema | `database/migrations/` (new migration per change, never edit one that ran) |
| "Today" and school days | `app/Support/SchoolCalendar.php` (America/Toronto, Monday-Friday) |
| Totals and averages | `app/Support/ParticipationStats.php` |
| Every participation write | `app/Services/ParticipationService.php` |
| Current class (session) | `app/Support/CurrentClass.php` |
| Class switcher (header pill and Semester chip) | `<x-class-menu>`: `app/View/Components/ClassMenu.php`, `resources/views/components/class-menu.blade.php`, `public/js/shell.js`; a `<details>` whose whole `<summary>` is the click target, never a native `<select>` |
| Limits | `config/classpulse.php` (`max_roster` 35, `max_points` 99; the only place the per-class active-student limit is defined) |
| Security headers / CSP | `app/Http/Middleware/SecurityHeaders.php` |
| Design tokens | `public/css/tokens.css` (Stitch light/dark sets; shell in `shell.css`, Daily in `daily.css`, other screens and login in `screens.css`; glass/depth UI kit, icon sizes and badges in `glass.css` (class list in its header comment); print base in `print.css`) |
| Icons and brand | `<x-icon name="…">` over the sprite `resources/views/components/icons.blade.php`; logo via `<x-brand-mark/>` (`public/brand/`); no emoji in views (tested) |
| Daily DOM hooks for JS | `CARD_ACTIONS` in `public/js/daily.js` + `data-action` in `resources/views/daily/card.blade.php` |

**Resolution convention.** PHP: PSR-4 from `composer.json` (`App\` → `app/`, `Tests\` → `tests/`). JS: classic scripts,
no `import`/`export`; `public/js/save-queue.js` is UMD so `tests/js/*.test.js` can `require()` it.

## Code rules

1. Validate every request with a FormRequest in `app/Http/Requests`. Two exceptions only: the two login fields use
   `$request->validate()` in `LoginController`, and the import endpoints use `Validator::make` in `RosterImporter`.
2. Every student/entry query is scoped by `school_class_id`; a `{student}` from another class returns 404.
3. Operations are deltas with a client `op_id`; a replayed `op_id` changes nothing (`replayed: true`).
4. Integers are stored; averages are rounded only for display with `number_format($v, 2, '.', '')`; zero present
   days displays `No data`.
5. JSON: success `{"data": {...}}`, error `{"error": {"code": "...", "message": "..."}}`, one shape everywhere.
6. Models set `$fillable` explicitly. Controllers stay thin: one public method per route action.
7. PHP style is whatever `pint --test` (preset `laravel`) accepts; JS uses 2-space indent and single quotes.
8. UI strings in English; owner documentation in `docs/` (Spanish) and `docs/es/README.es.md` (Spanish owner README); the root `README.md` is in English; code comments in English.
9. Status is always visible as text as well as colour (`Not recorded`, `Present · 0`, `Present`, `Absent`).
10. Every test file contains the method its step's gate lists with `phpunit --list-tests`; never rename it.

## Design system (source of truth `public/css/tokens.css`)

| Token | Dark (default when OS prefers dark) | Light |
|---|---|---|
| `--bg` / `--surface` / `--surface-2` | `#090D16` / `#0F172A` / `#1E293B` | `#F8FAFC` / `#FFFFFF` / `#F1F5F9` |
| `--border` / `--border-strong` | `#263247` / `#7686A6` | `#CBD5E1` / `#64748B` |
| `--fg` / `--muted` | `#DAE2FD` / `#A5B0C8` | `#0F172A` / `#475569` |
| `--primary` / `--primary-fg` / `--primary-soft` | `#818CF8` / `#0B1020` / `#232557` | `#4F46E5` / `#FFFFFF` / `#E0E7FF` |
| `--success` / `--success-bg` | `#34D399` / `#0C3A2E` | `#047857` / `#D1FAE5` |
| `--destructive` / `--destructive-bg` | `#F87171` / `#3D1620` | `#B91C1C` / `#FEE2E2` |
| `--warning` | `#FBBF24` | `#92400E` |
| Heat 0 / low / mid / high / A | `#1E293B` `#2B2F6B` `#1F4D5C` `#0F5B3F` `#5A1E2B` (text `#DAE2FD`, A `#FFE4E8`) | `#F1F5F9` `#E0E7FF` `#CFFAFE` `#BBF7D0` `#FECACA` (text `#0F172A`, A `#7F1D1D`) |

Glass, glow and background-gradient tokens (`--glass`, `--glow-*`, `--bg-glow`) also live in `tokens.css`.

- **Type:** `--font-main` Plus Jakarta Sans, `--font-mono` JetBrains Mono (numbers, KPIs), both self-hosted from
  `public/fonts` through `public/css/fonts.css`; never loaded from a third party. The palette table above is the legacy
  token set; the Daily Tracker follows the Stitch tokens (`--bg-app`, `--bg-surface`, `--color-indigo`, ...) in `tokens.css`.
- **Spacing:** 4px base: 4, 8, 12, 16, 24, 32, 48. **Radius:** controls 8, cards 16, dialogs 16, pills 999.
- **Elevation:** 1px border plus `0 1px 2px rgba(0,0,0,.25)` dark / `0 1px 2px rgba(20,26,46,.08)` light.
- **Layout:** max width 1440; cards `repeat(auto-fill, minmax(230px, 1fr))` beside a 320px side panel from 1024px; breakpoints 640 / 1024.
- **Targets:** card +/- 56x56 min, everything else 44px high min. Focus ring 3px `--primary`, 2px offset.
- **Motion:** 120ms ease-out, transform/opacity only; none under `prefers-reduced-motion: reduce`.
- **CSS:** hex values live only in `tokens.css`; `app.css` has no `#` at all (no hex, no id selectors).

## Environment

| Variable | Required | Used by | Source |
|---|---|---|---|
| `APP_KEY` | yes | Laravel encryption, sessions | `php artisan key:generate` (Bootstrap) |
| `APP_TIMEZONE` | yes | `config/app.php`, `config/classpulse.php` | `.env.example` (`America/Toronto`) |
| `DB_HOST` `DB_PORT` `DB_DATABASE` `DB_USERNAME` `DB_PASSWORD` | yes | Laravel DB | `.env.example` (local Docker values) |
| `SESSION_DRIVER` `SESSION_LIFETIME` `SESSION_SECURE_COOKIE` | yes | sessions | `.env.example` (`true` cookie flag in production) |
| `CLASSPULSE_WEB_PORT` `CLASSPULSE_DB_PORT` | no | `docker-compose.yml` interpolation | `.env.example` (8090 / 33061) |
| `CLASSPULSE_APP_DIR` | no | `scripts/package-release.sh`, `scripts/verify-release.sh` | export in the same shell line; default `classpulse-app` |

Tests take their DB settings from `phpunit.xml` (`classpulse_test`), never from `.env`. `.env.example` is committed;
`.env` never is. Full table: `blueprints/classpulse/blueprint.md` §10.

## Rules

| File | Applies to |
|---|---|
| `.claude/rules/database.md` | `database/**`, `app/Models/**` |
| `.claude/rules/frontend.md` | `resources/views/**`, `public/**` |
| `.claude/rules/security.md` | `app/Http/**`, `routes/**` |
| `.claude/rules/calculations.md` | `app/Support/**`, `app/Services/**`, `tests/**` |

Skills: `.claude/skills/add-migration/`, `.claude/skills/add-participation-operation/`, `.claude/skills/release-package/`.

## Non-negotiable

1. Touch only the `classpulse` Compose project. Never stop, remove, exec into or prune other containers or volumes
   (the `wp-env-*` containers belong to another project). Never run `docker compose down -v`
   without asking.
2. Never commit `.env` or any real credential; never read `.env` into output. Local DB passwords are throwaway.
3. Never add Node, npm, Vite, a bundler, a CDN, a remote web font (fonts are self-hosted in `public/fonts`) or any AI service or dependency.
4. No inline scripts, no inline styles, no `{!!` in views: the CSP and `LayoutShellTest` enforce it.
5. Only fictitious students (invented names) in seeds, factories, fixtures and tests. No money anywhere in the app.
6. Never publish to Hostinger or touch a remote server. Publishing needs the owner's explicit approval.
7. Never mark a task done with a failing gate, and never edit a verify command to make it pass.
