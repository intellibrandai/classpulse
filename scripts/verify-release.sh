#!/usr/bin/env bash
# Proves the release built by scripts/package-release.sh without touching anything but the classpulse project.
# Every check prints only PASS/FAIL lines; secrets (the local test login) are compared without being printed.
set -euo pipefail
APP_DIR_NAME="${CLASSPULSE_APP_DIR:-classpulse-app}"
DBROOT=classpulse_local_root
VERIFY_DB=classpulse_verify
PORT=8001
export LC_ALL=C
SECRETS_TMP=""

cleanup() {
  docker compose exec -T app sh -c 'if [ -f /tmp/classpulse-verify.pid ]; then kill "$(cat /tmp/classpulse-verify.pid)" 2>/dev/null || true; rm -f /tmp/classpulse-verify.pid; fi' || true
  docker compose exec -T db mariadb -uroot -p"${DBROOT}" -e "DROP DATABASE IF EXISTS ${VERIFY_DB};" || true
  rm -rf dist/verify
  if [ -n "${SECRETS_TMP}" ]; then rm -f "${SECRETS_TMP}"; fi
}
trap cleanup EXIT

fail() { echo "FAIL: $*" >&2; exit 1; }
ok() { echo "PASS: $*"; }

# forbid <label> <listing> <extended regex>: fails when any listed path matches (grep exit 1 = none found).
forbid() {
  local label="$1" listing="$2" pattern="$3" rc=0 hits
  hits="$(printf '%s\n' "$listing" | grep -E -- "$pattern")" || rc=$?
  if [ "$rc" -eq 1 ]; then return 0; fi
  if [ "$rc" -ne 0 ]; then fail "grep error while checking: ${label}"; fi
  printf '%s\n' "$hits" | head -5 >&2
  fail "forbidden path in package: ${label}"
}

for f in dist/classpulse-app.zip dist/classpulse-public_html.zip dist/install.sql dist/SHA256SUMS.txt dist/LEEME-HOSTINGER.md; do
  test -f "$f" || fail "missing $f (run ./scripts/package-release.sh)"
done

# 0. Checksums, zip integrity, instructions copy
(cd dist && shasum -a 256 -c SHA256SUMS.txt >/dev/null) || fail "SHA256SUMS.txt does not match the files"
test "$(wc -l < dist/SHA256SUMS.txt | tr -d ' ')" -eq 3 || fail "SHA256SUMS.txt must list exactly three files"
unzip -tq dist/classpulse-app.zip >/dev/null || fail "classpulse-app.zip is corrupt"
unzip -tq dist/classpulse-public_html.zip >/dev/null || fail "classpulse-public_html.zip is corrupt"
cmp -s docs/hostinger-deploy.md dist/LEEME-HOSTINGER.md || fail "dist/LEEME-HOSTINGER.md is not the current docs/hostinger-deploy.md"
ok "checksums, zip integrity, LEEME-HOSTINGER.md is the current Spanish guide"

app_list="$(unzip -Z1 dist/classpulse-app.zip)"
pub_list="$(unzip -Z1 dist/classpulse-public_html.zip)"
test -n "$app_list" && test -n "$pub_list" || fail "empty zip listing"
A="${APP_DIR_NAME}"

# 1. Forbidden paths: app zip (everything lives under ${A}/)
if grep -qvE "^${A}(/|$)" <<<"$app_list"; then fail "app zip has entries outside ${A}/"; fi
forbid ".env files (only ${A}/.env.example is allowed)" "$(printf '%s\n' "$app_list" | grep -vx "${A}/.env.example")" '(^|/)\.env($|\.)'
forbid "git metadata" "$app_list" '(^|/)\.git(/|$)|(^|/)\.github/'
forbid "dev folders and files at the project root" "$app_list" "^${A}/(tests|blueprints|docs|scripts|dist|docker|\.claude|node_modules|\.phpunit\.cache|database/factories)(/|$)"
forbid "dev files at the project root" "$app_list" "^${A}/(CLAUDE\.md|AGENTS\.md|README\.md|Dockerfile|docker-compose\.yml|phpunit\.xml|pint\.json|\.dockerignore|\.npmrc|\.editorconfig|\.gitattributes|\.phpunit\.result\.cache)$"
forbid "node_modules, source maps, logs, sqlite or pdf files" "$app_list" '(^|/)node_modules/|\.map$|\.log$|\.sqlite3?$|\.pdf$'
forbid "a public/ folder inside the app (assets belong in public_html)" "$app_list" "^${A}/public(/|$)"
forbid "compiled config/routes/events caches" "$app_list" "^${A}/bootstrap/cache/(config|routes[^/]*|events|packages|services)\.php$"
# storage/ and bootstrap/cache/ may hold only directories and .gitignore placeholders
forbid "files inside storage/ or bootstrap/cache (logs, sessions, cache, logins, screenshots, PDFs)" \
  "$(printf '%s\n' "$app_list" | grep -E "^${A}/(storage|bootstrap/cache)/.+[^/]$" | grep -vE '/\.gitignore$' || true)" '.'
forbid "storage/app screenshots or logins" "$app_list" 'storage/app/(design-|final-|capture-full|print-check|print-final|local-test-login)'
# Dev packages in vendor/
forbid "dev packages in vendor/" "$app_list" "^${A}/vendor/(phpunit|laravel/pint|laravel/pail|laravel/pao|fakerphp|mockery|nunomaduro/collision|sebastian|theseer|myclabs|phar-io|staabm)(/|$)|^${A}/vendor/bin/(phpunit|pint|pail)$"
test "$(printf '%s\n' "$app_list" | grep -cx "${A}/vendor/autoload.php")" -eq 1 || fail "vendor/autoload.php missing"
for required in "${A}/.htaccess" "${A}/.env.example" "${A}/artisan" "${A}/bootstrap/app.php" "${A}/routes/web.php" "${A}/storage/logs/.gitignore" "${A}/storage/framework/sessions/.gitignore" "${A}/storage/framework/views/.gitignore" "${A}/storage/framework/cache/data/.gitignore" "${A}/bootstrap/cache/.gitignore"; do
  grep -qx "$required" <<<"$app_list" || fail "app zip lacks ${required}"
done
ok "app zip paths: no .env, .git, tests, docs, blueprints, scripts, dev files, dev packages, logs, sessions, cache, logins or screenshots"

# 1b. public_html zip: whitelist of top-level entries, only .htaccess hidden, no other PHP, no temp files
forbid "unexpected top-level entry in public_html zip" "$(printf '%s\n' "$pub_list" | sed -E 's#/.*##' | sort -u | grep -vxE '\.htaccess|index\.php|robots\.txt|favicon\.ico|css|js|fonts|brand' || true)" '.'
forbid "hidden files other than .htaccess in public_html" "$(printf '%s\n' "$pub_list" | grep -vx '\.htaccess' || true)" '(^|/)\.[^/]'
forbid "PHP files other than index.php in public_html" "$(printf '%s\n' "$pub_list" | grep -vx 'index\.php' || true)" '\.(php|phtml|phar)$'
forbid "temp or debug files in public_html" "$pub_list" '(^|/)[^/]*(audit|scratch|debug|tmp|temp|phpinfo|check-requirements)[^/]*\.(js|php|html|txt)$|\.(map|bak|orig|swp|log|sql|env)$|~$'
forbid "a dist, storage or vendor folder in public_html" "$pub_list" '^(dist|storage|vendor)(/|$)'
for required in index.php .htaccess css/fonts.css js/daily.js; do
  grep -qx "$required" <<<"$pub_list" || fail "public_html zip lacks ${required}"
done
ok "public_html zip: only index.php, .htaccess, robots.txt, favicon.ico, css/, js/, fonts/, brand/ (no listing, no hidden files, no temp files)"

# 2. install.sql: schema + migrations rows only
inserts="$(grep -c '^INSERT INTO' dist/install.sql || true)"
other="$(grep '^INSERT INTO' dist/install.sql | grep -vc '^INSERT INTO `migrations`' || true)"
test "${other}" -eq 0 || fail "install.sql inserts data into a table other than migrations"
test "${inserts}" -ge 1 || fail "install.sql has no migrations rows"
rc=0; grep -E 'INSERT INTO `?(students|school_classes|participation_[a-z_]+|student_notes|report_comment_drafts|academic_periods|users|sessions|password_reset_tokens)`?' dist/install.sql >/dev/null || rc=$?
test "$rc" -eq 1 || fail "install.sql contains demo/test data rows"
rc=0; grep -Ei 'CREATE DATABASE|^USE |DROP (DATABASE|TABLE)|classpulse_release|classpulse_verify|GRANT |CREATE USER|IDENTIFIED BY' dist/install.sql >/dev/null || rc=$?
test "$rc" -eq 1 || fail "install.sql contains database/user administration statements"
for t in users sessions migrations school_classes students participation_entries participation_operations participation_events student_notes academic_periods report_comment_drafts; do
  grep -q "^CREATE TABLE \`${t}\`" dist/install.sql || fail "install.sql lacks table ${t}"
done
expected_migrations="$(find database/migrations -name '*.php' | wc -l | tr -d ' ')"
actual_migrations="$(grep -oE "'[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[a-z0-9_]+'" dist/install.sql | wc -l | tr -d ' ')"
test "${expected_migrations}" -eq "${actual_migrations}" || fail "install.sql has ${actual_migrations} migration rows, expected ${expected_migrations}"
ok "install.sql: schema + ${actual_migrations} migrations rows, no INSERT into students/classes/participation/notes/drafts/users"

# 3. Unpack side by side, exactly as on Hostinger (Layout B)
rm -rf dist/verify
mkdir -p dist/verify/public_html
unzip -q dist/classpulse-app.zip -d dist/verify
unzip -q dist/classpulse-public_html.zip -d dist/verify/public_html
test -f "dist/verify/${A}/vendor/autoload.php"
test -f "dist/verify/${A}/.htaccess"
grep -q 'Require all denied' "dist/verify/${A}/.htaccess" || fail "app .htaccess must deny all"
grep -q -- '-Indexes' dist/verify/public_html/.htaccess || fail "public_html/.htaccess must disable directory listing (Options -Indexes)"
grep -q 'RewriteRule \^ index.php' dist/verify/public_html/.htaccess || fail "public_html/.htaccess lacks the front-controller rewrite"
test "$(grep -c "/../${A}/" dist/verify/public_html/index.php)" -eq 3 || fail "index.php must reference ../${A}/ three times"
# Layout B: every path index.php requires resolves from public_html to a real file
test -f "dist/verify/public_html/../${A}/vendor/autoload.php" || fail "index.php autoload path does not resolve"
test -f "dist/verify/public_html/../${A}/bootstrap/app.php" || fail "index.php bootstrap path does not resolve"
test -d "dist/verify/public_html/../${A}/storage/framework" || fail "index.php maintenance path parent does not resolve"
test ! -e dist/verify/public_html/.env
# Self-hosted fonts must ship with the public files (the CSP only allows font-src 'self')
test -f dist/verify/public_html/css/fonts.css
test "$(find dist/verify/public_html/fonts -name '*.woff2' | wc -l | tr -d ' ')" -ge 4
# No dev packages according to Composer's own records; writable folders exist and are empty but for placeholders
grep -q '"dev": false' "dist/verify/${A}/vendor/composer/installed.json" || fail "vendor was not installed with --no-dev"
for pkg in phpunit/phpunit laravel/pint laravel/pail laravel/pao fakerphp/faker mockery/mockery nunomaduro/collision; do
  grep -q "\"name\": \"${pkg}\"" "dist/verify/${A}/vendor/composer/installed.json" && fail "dev package ${pkg} is in vendor/"
done
grep -q "'Illuminate" "dist/verify/${A}/vendor/composer/autoload_classmap.php" || fail "vendor autoloader is not optimized/complete"
for d in storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache storage/app/private storage/app/public; do
  test -d "dist/verify/${A}/${d}" || fail "missing writable folder ${d}"
  test "$(find "dist/verify/${A}/${d}" -type f ! -name .gitignore | wc -l | tr -d ' ')" -eq 0 || fail "${d} is not empty"
done
ok "unzipped as Layout B: index.php paths resolve, vendor is production-only, writable folders are empty"

# 4. Secrets and local-only values must not be anywhere in the package (compared without printing)
rc=0; grep -rlaE 'APP_KEY=base64|APP_KEY="?base64' dist/verify dist/install.sql dist/LEEME-HOSTINGER.md >/dev/null 2>&1 || rc=$?
test "$rc" -eq 1 || fail "an APP_KEY value (APP_KEY=base64...) is in the package"
rc=0; grep -rlaE '^[[:space:]]*(DB_PASSWORD|MAIL_PASSWORD|REDIS_PASSWORD|AWS_SECRET_ACCESS_KEY|APP_KEY)=[^[:space:]]+' dist/verify --include='.env*' 2>/dev/null | grep -v "/${A}/.env.example$" >/dev/null || rc=$?
test "$rc" -eq 1 || fail "an env file other than the placeholder .env.example holds credentials"
rc=0; grep -E '^[[:space:]]*(DB_PASSWORD|APP_KEY)=' "dist/verify/${A}/.env.example" | grep -vE '=(CAMBIAR_[A-Z_]+)?$' >/dev/null || rc=$?
test "$rc" -eq 1 || fail ".env.example must hold placeholders only"
for needle in 'classpulse_local' 'teacher@classpulse.test' 'classpulse.test'; do
  # DemoSeedCommand.php legitimately names the demo account; it only runs when APP_ENV=local (proved in step 8).
  rc=0; grep -rlaF --exclude=DemoSeedCommand.php -- "$needle" dist/verify dist/install.sql dist/LEEME-HOSTINGER.md >/dev/null 2>&1 || rc=$?
  test "$rc" -eq 1 || fail "local-only value '${needle}' found in the package"
done
if [ -f storage/app/local-test-login.txt ]; then
  SECRETS_TMP="$(mktemp)"; chmod 600 "$SECRETS_TMP"
  # the local test password and e-mail, one per line, never echoed
  sed -nE 's/^[[:space:]]*(Password|Email|E-mail)[[:space:]]*:[[:space:]]*//p' storage/app/local-test-login.txt | sed -E 's/[[:space:]]+$//' | awk 'length($0) >= 8' > "$SECRETS_TMP"
  if [ -s "$SECRETS_TMP" ]; then
    rc=0; grep -rlaF -f "$SECRETS_TMP" dist/verify dist/install.sql dist/LEEME-HOSTINGER.md >/dev/null 2>&1 || rc=$?
    test "$rc" -eq 1 || fail "a value from storage/app/local-test-login.txt is in the package"
    ok "no value from storage/app/local-test-login.txt found (compared, not printed)"
  else
    echo "WARN: storage/app/local-test-login.txt has no Password:/Email: line of 8+ characters; content comparison skipped" >&2
  fi
fi
ok "no APP_KEY, no real passwords, no local credentials or test e-mail in the zips, install.sql or LEEME"

# 5. Scratch database from install.sql (structure + migrations rows, no users)
docker compose exec -T db mariadb -uroot -p"${DBROOT}" -e "DROP DATABASE IF EXISTS ${VERIFY_DB}; CREATE DATABASE ${VERIFY_DB} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON ${VERIFY_DB}.* TO 'classpulse'@'%';"
docker compose exec -T db mariadb -uroot -p"${DBROOT}" "${VERIFY_DB}" < dist/install.sql

# 6. Throwaway production-like .env with local-only values (created AFTER the scans above, deleted with dist/verify)
APP_KEY_VALUE="base64:$(docker compose exec -T app php -r 'echo base64_encode(random_bytes(32));')"
cat > "dist/verify/${A}/.env" <<ENVEOF
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

# 7. Every migration Ran, none Pending
status="$(docker compose exec -T -w "/app/dist/verify/${A}" app php artisan migrate:status --no-interaction)"
grep -q 'Ran' <<<"$status"
rc=0
grep -q 'Pending' <<<"$status" || rc=$?
test "$rc" -eq 1 || fail "pending migrations after importing dist/install.sql (grep exit ${rc})"
ok "migrate:status: every migration Ran, none Pending"

# 8. Production guards: demo data must refuse to run with APP_ENV=production; no scheduler, sync queue
rc=0
docker compose exec -T -w "/app/dist/verify/${A}" app php artisan classpulse:demo-seed --no-interaction >/dev/null 2>&1 || rc=$?
test "$rc" -ne 0 || fail "classpulse:demo-seed ran with APP_ENV=production"
test "$(docker compose exec -T db mariadb -uroot -p"${DBROOT}" -N -B -e "SELECT (SELECT COUNT(*) FROM ${VERIFY_DB}.students)+(SELECT COUNT(*) FROM ${VERIFY_DB}.school_classes)+(SELECT COUNT(*) FROM ${VERIFY_DB}.users)" | tr -d '[:space:]')" = 0 || fail "the production database is not empty after the demo-seed refusal"
schedule="$(docker compose exec -T -w "/app/dist/verify/${A}" app php artisan schedule:list --no-interaction 2>&1 || true)"
grep -qi 'no scheduled tasks' <<<"$schedule" || fail "the app registers scheduled tasks (cron would be needed)"
ok "demo-seed refuses in production (APP_ENV=production), database stays empty, no scheduled tasks"
test ! -e "dist/verify/${A}/bootstrap/cache/config.php" || fail "config was cached"

# 9. Serve the unpacked release inside the app container and request /up, /login and the sensitive paths
docker compose exec -T app sh -c "nohup php -S 0.0.0.0:${PORT} -t /app/dist/verify/public_html >/tmp/classpulse-verify.log 2>&1 & echo \$! > /tmp/classpulse-verify.pid"
http() { docker compose exec -T app curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${PORT}$1" || true; }
code=000
for _ in $(seq 1 20); do
  code="$(http /up)"
  [ "$code" = 200 ] && break
  sleep 1
done
test "$code" = 200 || fail "/up returned ${code}"
test "$(http /login)" = 200 || fail "/login is not 200"
test "$(http /fonts/plus-jakarta-sans-latin.woff2)" = 200 || fail "self-hosted font is not served"
test "$(http /css/tokens.css)" = 200 || fail "css is not served"
for path in /.env /.env.example /storage/logs/laravel.log /composer.json /artisan /vendor/autoload.php /../classpulse-app/.env; do
  c="$(http "$path")"
  case "$c" in 403|404) ;; *) fail "${path} returned ${c}, expected 403 or 404";; esac
done
ok "served from the unzipped package: /up 200, /login 200, assets 200, /.env /storage/logs/laravel.log /composer.json /vendor 403|404"

# 10. Sizes, inode count and checksums (informational)
unz_kb="$(du -sk "dist/verify" | cut -f1)"
files="$(find dist/verify -path "dist/verify/${A}/.env" -prune -o -print | wc -l | tr -d ' ')"
echo "sizes (bytes): app zip $(wc -c < dist/classpulse-app.zip | tr -d ' '), public_html zip $(wc -c < dist/classpulse-public_html.zip | tr -d ' '), install.sql $(wc -c < dist/install.sql | tr -d ' ')"
echo "unzipped: ${unz_kb} KB (includes the throwaway .env), about ${files} files and folders"
cat dist/SHA256SUMS.txt
echo "release verified: zips clean, install.sql clean, /up 200, /login 200, migrations Ran, demo-seed blocked in production"
