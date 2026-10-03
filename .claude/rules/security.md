---
description: HTTP layer, routing, auth and security conventions
paths:
  - "app/Http/**"
  - "routes/**"
---

# Security rules

- Every route except `GET /login`, `POST /login` and `/up` sits inside the `auth` middleware group.
- There is no register route and no password-reset route. Tests assert `/register`, `/forgot-password` and
  `/reset-password/abc` return 404. Never add them.
- `POST /login` carries `throttle:login`; the limiter (5 per minute keyed by lowercase email + IP) is defined in
  `App\Providers\AppServiceProvider::boot`.
- Validate every input with a FormRequest in `app/Http/Requests`. Exceptions: the two login fields
  (`$request->validate()` in `LoginController`) and the import endpoints (`Validator::make` in
  `App\Services\RosterImporter`). Never trust a raw `$request->input()` in business logic.
- `{class}` is the only tenant key accepted from the user. A `{student}` or `student_id` whose `school_class_id`
  differs from the resolved class returns 404 `not_found`.
- All SQL goes through Eloquent or the query builder with bindings. No string-built SQL with user input.
- Forms use `@csrf`; JSON calls send `X-CSRF-TOKEN` from `<meta name="csrf-token">`. CSRF middleware is Laravel 13's
  `Illuminate\Foundation\Http\Middleware\PreventRequestForgery` (in the `web` group by default).
- JSON under `/api`: success `{"data": <day state>}`; error `{"error": {"code": <code>, "message": <text>}}`, plus
  `day` for participation business errors. One shape everywhere.
- `App\Http\Middleware\SecurityHeaders` is global and sends exactly
  `default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'`
  as the CSP. Do not weaken it to make a view work; fix the view.
- CSV exports prefix any text cell starting with `=`, `+`, `-`, `@`, tab or CR with `'` via `App\Support\CsvWriter`.
- Never log passwords, hashes, session ids or `.env` values.
