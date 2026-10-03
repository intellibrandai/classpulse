---
name: release-package
description: Use when the owner asks for "a release", "the Hostinger files", "package it" or "rebuild the zips". Builds dist/classpulse-app.zip, dist/classpulse-public_html.zip and dist/install.sql locally with Docker, then proves them with verify-release.sh. Never uploads anything.
---

# Build and verify a release package

## When to use
- The owner wants fresh upload files for Hostinger (Layout B: app folder beside `public_html`).
- After any change to code, migrations or `scripts/release-exclude.txt`.

## Steps
1. Make sure the gate is green first (`/tmp` is the host's temp directory; the JS line requires at least one passing
   test because an empty run still exits 0):
   `docker compose exec -T app vendor/bin/pint --test && docker compose exec -T app vendor/bin/phpunit`
   then
   `docker compose --profile test run --rm jstest > /tmp/classpulse-jstest.txt && grep -qE '(ℹ|#) pass [1-9]' /tmp/classpulse-jstest.txt && grep -qE '(ℹ|#) fail 0' /tmp/classpulse-jstest.txt`
2. Dependency audit (manual pre-release step, needs network access to the Packagist advisories feed):
   `docker compose exec -T app composer audit`
   Expected: no advisories reported, or a list of advisories that whoever builds the release reads and decides on
   before continuing. It is not part of the automated gate because it depends on the network.
3. Build: `./scripts/package-release.sh` (optional `CLASSPULSE_APP_DIR=other-name` to rename the app folder).
4. Prove: `./scripts/verify-release.sh` (unzips into `dist/verify/`, imports `dist/install.sql` into the scratch
   database `classpulse_verify`, serves the result with `php -S` on port 8001 inside the app container, checks
   `/up` and `/login`, then cleans up).
5. Hand the three files in `dist/` to the owner with `docs/hostinger-deploy.md`.

## Verify
```bash
./scripts/package-release.sh      # expect: exit 0, prints "release built: ..."
./scripts/verify-release.sh       # expect: exit 0, prints "release verified"
test ! -e dist/verify             # expect: exit 0
```

## Do not
- Publish to Hostinger, open hPanel, or run anything against a remote server. Publishing needs the owner's
  explicit approval and is done by the owner.
- Put `.env`, `.env.example`, `tests/`, `blueprints/` or dev dependencies in the zip (`scripts/release-exclude.txt`
  excludes `/.env` and `/.env.*`; rsync has no `!` exception syntax, so no env file ever enters the package).
- Create any web-reachable installer or migration route.
