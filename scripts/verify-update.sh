#!/usr/bin/env bash
# Proves the update kit built by scripts/package-update.sh without touching anything but the classpulse project.
#   CLASSPULSE_PREVIOUS_DIR=<folder with the PREVIOUS full package: classpulse-app.zip + classpulse-public_html.zip>
#   ./scripts/verify-update.sh
# With CLASSPULSE_PREVIOUS_DIR the kit is applied over a COPY of the previous package (overwrite semantics of the guide, with
# live .env, storage/, index.php and .htaccess planted first), compared with the NEW full package in dist/, and booted.
set -euo pipefail
APP_DIR_NAME="${CLASSPULSE_APP_DIR:-classpulse-app}"
DBROOT=classpulse_local_root
VERIFY_DB=classpulse_verify_update
PORT=8002
U=dist/update
W=dist/verify-update
export LC_ALL=C

cleanup() {
  docker compose exec -T app sh -c 'if [ -f /tmp/classpulse-verify-update.pid ]; then kill "$(cat /tmp/classpulse-verify-update.pid)" 2>/dev/null || true; rm -f /tmp/classpulse-verify-update.pid; fi' || true
  docker compose exec -T db mariadb -uroot -p"${DBROOT}" -e "DROP DATABASE IF EXISTS ${VERIFY_DB};" || true
  rm -rf "${W}"
}
trap cleanup EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }
ok() { echo "PASS: $*"; }
forbid() {
  local label="$1" listing="$2" pattern="$3" rc=0 hits
  hits="$(printf '%s\n' "$listing" | grep -E -- "$pattern")" || rc=$?
  if [ "$rc" -eq 1 ]; then return 0; fi
  if [ "$rc" -ne 0 ]; then fail "grep error while checking: ${label}"; fi
  printf '%s\n' "$hits" | head -5 >&2
  fail "forbidden path in the update kit: ${label}"
}

for f in classpulse-update-app.zip classpulse-update-public_html.zip manifest.json SHA256SUMS.txt LEEME-ACTUALIZAR-HOSTINGER.md migrations/README.txt; do
  test -f "${U}/${f}" || fail "missing ${U}/${f} (run ./scripts/package-update.sh)"
done

# 0. Checksums, integrity, guide copy
(cd "${U}" && shasum -a 256 -c SHA256SUMS.txt >/dev/null) || fail "SHA256SUMS.txt does not match the kit"
unzip -tq "${U}/classpulse-update-app.zip" >/dev/null || fail "update app zip is corrupt"
unzip -tq "${U}/classpulse-update-public_html.zip" >/dev/null || fail "update public_html zip is corrupt"
cmp -s docs/hostinger-update.md "${U}/LEEME-ACTUALIZAR-HOSTINGER.md" || fail "LEEME-ACTUALIZAR-HOSTINGER.md is not the current docs/hostinger-update.md"
jq -e '.version and .composer_lock_sha256 and (.app_files | length > 0) and (.public_files | length > 0) and (.migrations | length > 0)' "${U}/manifest.json" >/dev/null || fail "manifest.json is incomplete"
ok "checksums, zip integrity, guide copy, manifest shape"

app_list="$(unzip -Z1 "${U}/classpulse-update-app.zip")"
pub_list="$(unzip -Z1 "${U}/classpulse-update-public_html.zip")"

# 1. App zip: nothing local, nothing secret, no demo data, no dev tools
forbid ".env files" "$app_list" '(^|/)\.env($|\.)'
forbid "storage/ or bootstrap/cache/ content" "$app_list" '^(storage|bootstrap/cache)(/|$)'
forbid "public/ assets inside the app zip" "$app_list" '^public(/|$)'
forbid "a top-level classpulse-app folder (zip must be flat)" "$app_list" "^${APP_DIR_NAME}(/|$)"
forbid "dev folders and files" "$app_list" '^(tests|blueprints|docs|scripts|dist|docker|\.claude|\.git|\.github|node_modules|database/factories|database/seeders)(/|$)|^(CLAUDE|AGENTS|README)\.md$|^(Dockerfile|docker-compose\.yml|phpunit\.xml|pint\.json)$'
forbid "logs, sqlite, sql, csv, pdf, maps, screenshots, credentials" "$app_list" '\.(log|sqlite3?|sql|csv|pdf|map|png|jpg|jpeg)$|local-test-login|credentials|(^|/)auth\.json$|\.pem$|\.key$'
forbid "dev packages in vendor/" "$app_list" '^vendor/(phpunit|laravel/pint|laravel/pail|laravel/pao|fakerphp|mockery|nunomaduro/collision|sebastian|theseer|myclabs|phar-io|staabm)(/|$)'
for required in app/Http/Controllers/AccountController.php app/Http/Requests/UpdateEmailRequest.php app/Http/Requests/UpdatePasswordRequest.php app/Http/Requests/AccountCredentialRequest.php resources/views/roster/partials/account.blade.php resources/views/roster/partials/password-field.blade.php routes/web.php bootstrap/app.php config/classpulse.php composer.json composer.lock artisan database/migrations/0001_01_01_000000_create_users_table.php; do
  grep -qx "$required" <<<"$app_list" || fail "app zip lacks ${required}"
done
vendor_flag="$(jq -r '.vendor_included' "${U}/manifest.json")"
lock_now="$(shasum -a 256 composer.lock | cut -d' ' -f1)"
test "$(jq -r '.composer_lock_sha256' "${U}/manifest.json")" = "${lock_now}" || fail "manifest composer.lock hash is not the current composer.lock"
if [ "${vendor_flag}" = true ]; then
  grep -qx 'vendor/autoload.php' <<<"$app_list" || fail "manifest says vendor is included but vendor/autoload.php is missing"
else
  forbid "vendor/ although composer.lock did not change" "$app_list" '^vendor(/|$)'
  test "$(jq -r '.baseline_composer_lock_sha256' "${U}/manifest.json")" = "${lock_now}" || fail "vendor omitted but composer.lock differs from the baseline"
fi
rc=0; unzip -p "${U}/classpulse-update-app.zip" app/Console/Commands/DemoSeedCommand.php | grep -q "APP_ENV\|isLocal\|environment" || rc=$?
test "$rc" -eq 0 || fail "DemoSeedCommand lost its production guard"
ok "app zip: no .env, storage, bootstrap/cache, public, tests, docs, scripts, demo or dev files; Account code present; vendor included: ${vendor_flag}"

# 2. public_html zip: only asset folders, never index.php / .htaccess / robots.txt
forbid "unexpected top-level entry" "$(printf '%s\n' "$pub_list" | sed -E 's#/.*##' | sort -u | grep -vxE 'css|js|brand|fonts|favicon\.ico' || true)" '.'
forbid "index.php, .htaccess or robots.txt (the live copies must be kept)" "$pub_list" '(^|/)(index\.php|\.htaccess|robots\.txt)$'
forbid "PHP, hidden, temp or debug files" "$pub_list" '\.(php|phtml|phar|map|bak|orig|swp|log|sql|env)$|(^|/)\.[^/]'
for required in js/account.js js/shell.js css/roster.css css/fonts.css favicon.ico; do
  grep -qx "$required" <<<"$pub_list" || fail "public zip lacks ${required}"
done
ok "public_html zip: only css/ js/ brand/ fonts/ favicon.ico; no index.php, no .htaccess, no robots.txt; js/account.js present"

# 3. Manifest hashes equal the zip contents
rm -rf "${W}"; mkdir -p "${W}/app" "${W}/pub"
unzip -q "${U}/classpulse-update-app.zip" -d "${W}/app"
unzip -q "${U}/classpulse-update-public_html.zip" -d "${W}/pub"
bad=0
while IFS=$'\t' read -r path sum; do
  actual="$(shasum -a 256 "${W}/app/${path}" 2>/dev/null | cut -d' ' -f1 || true)"
  [ "$actual" = "$sum" ] || { echo "hash differs: app/${path}" >&2; bad=1; }
done < <(jq -r '.app_files[] | [.path, .sha256] | @tsv' "${U}/manifest.json")
while IFS=$'\t' read -r path sum; do
  actual="$(shasum -a 256 "${W}/pub/${path}" 2>/dev/null | cut -d' ' -f1 || true)"
  [ "$actual" = "$sum" ] || { echo "hash differs: public/${path}" >&2; bad=1; }
done < <(jq -r '.public_files[] | [.path, .sha256] | @tsv' "${U}/manifest.json")
test "$bad" -eq 0 || fail "manifest hashes do not match the zip contents"
test "$(jq '.app_files | length' "${U}/manifest.json")" -eq "$(find "${W}/app" -type f | wc -l | tr -d ' ')" || fail "manifest app_files count differs from the zip"
test "$(jq '.public_files | length' "${U}/manifest.json")" -eq "$(find "${W}/pub" -type f | wc -l | tr -d ' ')" || fail "manifest public_files count differs from the zip"
ok "manifest.json: every shipped file's sha256 matches ($(jq '.app_files | length' "${U}/manifest.json") app files, $(jq '.public_files | length' "${U}/manifest.json") public files)"

# 4. Migrations: one SQL per migration file, each ending with its migrations row, no data, no admin statements
expected="$(find database/migrations -name '*.php' | wc -l | tr -d ' ')"
actual="$(ls "${U}/migrations"/*.sql | wc -l | tr -d ' ')"
test "${expected}" -eq "${actual}" || fail "${actual} migration SQL files, expected ${expected}"
for f in "${U}/migrations"/*.sql; do
  name="$(basename "$f" .sql | sed -E 's/^[0-9]+_//')"
  test -f "database/migrations/${name}.php" || fail "${f} does not match a migration file"
  tail -1 "$f" | grep -q "^INSERT INTO \`migrations\` (\`migration\`, \`batch\`) SELECT '${name}'," || fail "${f} does not end with its migrations row"
done
rc=0; cat "${U}/migrations"/*.sql | grep -Ei 'DROP (DATABASE|TABLE)|CREATE DATABASE|GRANT |IDENTIFIED BY|INSERT INTO `?(users|students|school_classes|sessions)' >/dev/null || rc=$?
test "$rc" -eq 1 || fail "migration SQL contains destructive/admin statements or data rows"
rc=0; cat docs/hostinger-update.md | grep -q 'no añade migraciones' || rc=$?
test "$rc" -eq 0 || fail "the guide must state that the Account feature adds no migration"
# The Account feature adds no migration: no migration file changed or was added by the working tree against HEAD, and nothing is pending locally.
test -z "$(git status --porcelain -- database/migrations)" || fail "database/migrations has uncommitted changes (the Account feature must add no migration)"
status="$(docker compose exec -T app php artisan migrate:status --no-interaction)"
rc=0; grep -q 'Pending' <<<"$status" || rc=$?
test "$rc" -eq 1 || fail "pending migrations in the dev database"
ok "migrations: ${actual} SQL files (one per migration, each registering itself), no data, none pending locally, none uncommitted"

# 5. Secrets and local-only values
rc=0; grep -rlaE 'APP_KEY=base64|APP_KEY="?base64' "${W}" "${U}" >/dev/null 2>&1 || rc=$?
test "$rc" -eq 1 || fail "an APP_KEY value is in the kit"
for needle in 'classpulse_local' 'teacher@classpulse.test'; do
  rc=0; grep -rlaF --exclude=DemoSeedCommand.php -- "$needle" "${W}" "${U}" >/dev/null 2>&1 || rc=$?
  test "$rc" -eq 1 || fail "local-only value '${needle}' found in the kit"
done
if [ -f storage/app/local-test-login.txt ]; then
  SECRETS_TMP="$(mktemp)"; chmod 600 "$SECRETS_TMP"
  sed -nE 's/^[[:space:]]*(Password|Email|E-mail)[[:space:]]*:[[:space:]]*//p' storage/app/local-test-login.txt | sed -E 's/[[:space:]]+$//' | awk 'length($0) >= 8' > "$SECRETS_TMP"
  rc=0; if [ -s "$SECRETS_TMP" ]; then grep -rlaF -f "$SECRETS_TMP" "${W}" "${U}" >/dev/null 2>&1 || rc=$?; else rc=1; fi
  rm -f "$SECRETS_TMP"
  test "$rc" -eq 1 || fail "a value from storage/app/local-test-login.txt is in the kit"
fi
ok "no APP_KEY, no local credentials, no local test e-mail in the kit"

# 6. Overlay: previous full package + live files + update kit == new full package (except preserved files)
if [ -z "${CLASSPULSE_PREVIOUS_DIR:-}" ]; then
  echo "SKIPPED: overlay test (set CLASSPULSE_PREVIOUS_DIR to the folder with the previous classpulse-app.zip and classpulse-public_html.zip)"
  echo "update kit verified (overlay SKIPPED)"; exit 0
fi
for f in classpulse-app.zip classpulse-public_html.zip; do test -f "${CLASSPULSE_PREVIOUS_DIR}/${f}" || fail "missing ${CLASSPULSE_PREVIOUS_DIR}/${f}"; test -f "dist/${f}" || fail "missing new full package dist/${f}"; done
A="${APP_DIR_NAME}"
mkdir -p "${W}/live" "${W}/new"
unzip -q "${CLASSPULSE_PREVIOUS_DIR}/classpulse-app.zip" -d "${W}/live"
mkdir -p "${W}/live/public_html"
unzip -q "${CLASSPULSE_PREVIOUS_DIR}/classpulse-public_html.zip" -d "${W}/live/public_html"
unzip -q dist/classpulse-app.zip -d "${W}/new"
mkdir -p "${W}/new/public_html"; unzip -q dist/classpulse-public_html.zip -d "${W}/new/public_html"
# Plant what a live site has and the guide says must survive
printf 'LIVE_ENV_MARKER=1\n' > "${W}/live/${A}/.env"
printf 'live log line\n' > "${W}/live/${A}/storage/logs/laravel.log"
printf '<?php // live index with hosting-specific paths\n' > "${W}/live/public_html/index.php"
printf '# live htaccess with hosting rules\n' > "${W}/live/public_html/.htaccess"
cp "${W}/live/public_html/index.php" "${W}/index.live"; cp "${W}/live/public_html/.htaccess" "${W}/htaccess.live"
# Apply the kit with the same semantics as the guide: extract INSIDE the folders, overwrite
unzip -q -o "${U}/classpulse-update-app.zip" -d "${W}/live/${A}"
unzip -q -o "${U}/classpulse-update-public_html.zip" -d "${W}/live/public_html"
cmp -s "${W}/live/${A}/.env" <(printf 'LIVE_ENV_MARKER=1\n') || fail ".env was touched by the update"
grep -q 'live log line' "${W}/live/${A}/storage/logs/laravel.log" || fail "storage/ was touched by the update"
cmp -s "${W}/live/public_html/index.php" "${W}/index.live" || fail "public_html/index.php was overwritten"
cmp -s "${W}/live/public_html/.htaccess" "${W}/htaccess.live" || fail "public_html/.htaccess was overwritten"
ok "live .env, storage/laravel.log, public_html/index.php and .htaccess survived the update unchanged"
# File-set equality with the new full package. Allowed differences: preserved live files; vendor/composer autoload maps, which are
# regenerated by 'composer --optimize-autoloader' (a non-authoritative classmap falls back to PSR-4 for classes it does not list),
# and vendor/composer/installed.php, which records the root package's git reference.
fileset() { (cd "$1" && find . -type f -print0 | xargs -0 shasum -a 256 | sed 's#  \./#  #' | sort -k2); }
fileset "${W}/new/${A}" | grep -vE '  (vendor/composer/(autoload_classmap|autoload_static|installed)\.php)$' > "${W}/new.app.txt"
fileset "${W}/live/${A}" | grep -vE '  (\.env|storage/logs/laravel\.log|vendor/composer/(autoload_classmap|autoload_static|installed)\.php)$' > "${W}/live.app.txt"
missing="$(diff "${W}/new.app.txt" "${W}/live.app.txt" | grep '^<' || true)"
if [ -n "$missing" ]; then printf '%s\n' "$missing" | head -10 >&2; fail "files of the new full package are missing or differ after applying the update"; fi
stale="$(diff "${W}/new.app.txt" "${W}/live.app.txt" | grep '^>' || true)"
if [ -n "$stale" ]; then echo "NOTE: files left over from the previous package (deleted upstream, harmless):"; printf '%s\n' "$stale" | head -10; fi
fileset "${W}/new/public_html" | grep -vE '  (index\.php|\.htaccess|robots\.txt)$' > "${W}/new.pub.txt"
fileset "${W}/live/public_html" | grep -vE '  (index\.php|\.htaccess|robots\.txt)$' > "${W}/live.pub.txt"
missing="$(diff "${W}/new.pub.txt" "${W}/live.pub.txt" | grep '^<' || true)"
if [ -n "$missing" ]; then printf '%s\n' "$missing" | head -10 >&2; fail "public files of the new full package are missing or differ after applying the update"; fi
ok "previous full package + update kit has exactly the files and bytes of the new full package (app: $(wc -l < "${W}/new.app.txt" | tr -d ' ') files, public: $(wc -l < "${W}/new.pub.txt" | tr -d ' ') files; preserved live files excluded)"

# 7. Boot the updated copy: /up, /login, the new routes, migrations Ran (database from the NEW install.sql, no users)
docker compose exec -T db mariadb -uroot -p"${DBROOT}" -e "DROP DATABASE IF EXISTS ${VERIFY_DB}; CREATE DATABASE ${VERIFY_DB} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON ${VERIFY_DB}.* TO 'classpulse'@'%';"
docker compose exec -T db mariadb -uroot -p"${DBROOT}" "${VERIFY_DB}" < dist/install.sql
APP_KEY_VALUE="base64:$(docker compose exec -T app php -r 'echo base64_encode(random_bytes(32));')"
cat > "${W}/live/${A}/.env" <<ENVEOF
APP_NAME=ClassPulse
APP_ENV=production
APP_KEY=${APP_KEY_VALUE}
APP_DEBUG=false
APP_URL=http://127.0.0.1:${PORT}
APP_TIMEZONE=America/Toronto
DB_CONNECTION=mariadb
DB_HOST=db
DB_PORT=3306
DB_DATABASE=${VERIFY_DB}
DB_USERNAME=classpulse
DB_PASSWORD=classpulse_local
SESSION_DRIVER=database
SESSION_LIFETIME=480
SESSION_SECURE_COOKIE=false
CACHE_STORE=file
QUEUE_CONNECTION=sync
LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=error
ENVEOF
# the live index.php of a real site carries the Layout B paths; restore the packaged one from the PREVIOUS package for the boot
cp "${W}/new/public_html/index.php" "${W}/live/public_html/index.php"
status="$(docker compose exec -T -w "/app/${W}/live/${A}" app php artisan migrate:status --no-interaction)"
grep -q 'Ran' <<<"$status" || fail "no migration Ran in the updated copy"
rc=0; grep -q 'Pending' <<<"$status" || rc=$?
test "$rc" -eq 1 || fail "pending migrations in the updated copy"
routes="$(docker compose exec -T -w "/app/${W}/live/${A}" app php artisan route:list --no-interaction)"
grep -q 'account/email' <<<"$routes" && grep -q 'account/password' <<<"$routes" || fail "the updated copy does not know the account routes"
docker compose exec -T app sh -c "nohup php -S 0.0.0.0:${PORT} -t /app/${W}/live/public_html >/tmp/classpulse-verify-update.log 2>&1 & echo \$! > /tmp/classpulse-verify-update.pid"
http() { docker compose exec -T app curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${PORT}$1" || true; }
code=000
for _ in $(seq 1 20); do code="$(http /up)"; [ "$code" = 200 ] && break; sleep 1; done
test "$code" = 200 || fail "/up returned ${code}"
test "$(http /login)" = 200 || fail "/login is not 200"
test "$(http /js/account.js)" = 200 || fail "/js/account.js is not served"
for path in /.env /storage/logs/laravel.log /composer.json; do
  c="$(http "$path")"; case "$c" in 403|404) ;; *) fail "${path} returned ${c}";; esac
done
c="$(docker compose exec -T app curl -s -o /dev/null -w '%{http_code}' -X POST "http://127.0.0.1:${PORT}/account/password" || true)"
case "$c" in 302|419) ;; *) fail "POST /account/password as a guest returned ${c}, expected 302 or 419";; esac
ok "updated copy booted: /up 200, /login 200, /js/account.js 200, account routes registered, guest POST ${c}, migrations Ran, /.env /storage /composer.json 403|404"

echo "sizes (bytes): app zip $(wc -c < "${U}/classpulse-update-app.zip" | tr -d ' '), public_html zip $(wc -c < "${U}/classpulse-update-public_html.zip" | tr -d ' ')"
cat "${U}/SHA256SUMS.txt"
echo "update kit verified"
