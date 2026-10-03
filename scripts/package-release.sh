#!/usr/bin/env bash
# Builds the Hostinger package in dist/ (never uploads anything):
#   classpulse-app.zip, classpulse-public_html.zip, install.sql, SHA256SUMS.txt, LEEME-HOSTINGER.md
# Composer and the scratch database run inside the Docker services. Prove the result with scripts/verify-release.sh.
set -euo pipefail
APP_DIR_NAME="${CLASSPULSE_APP_DIR:-classpulse-app}"
DBROOT=classpulse_local_root   # throwaway root password of the local Docker MariaDB only
FIXED_TIME=202601010000        # every file gets this mtime so the zips are reproducible
export TZ=UTC LC_ALL=C

BUILD=dist/build
APP="${BUILD}/${APP_DIR_NAME}"
PUB="${BUILD}/public_html"

rm -rf "${BUILD}" dist/*.zip dist/install.sql dist/SHA256SUMS.txt dist/LEEME-HOSTINGER.md dist/verify
mkdir -p "${APP}" "${PUB}"

# 1. Application files (exclude list = single source of truth for what never ships)
rsync -a --exclude-from=scripts/release-exclude.txt ./ "${APP}/"

# 2. Empty writable folders with .gitignore-style placeholders (nothing from the local machine is copied)
for d in storage/app/private storage/app/public storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache; do
  mkdir -p "${APP}/${d}"
  printf '*\n!.gitignore\n' > "${APP}/${d}/.gitignore"
done
printf '*\n!private/\n!public/\n!.gitignore\n' > "${APP}/storage/app/.gitignore"
printf 'Require all denied\n' > "${APP}/.htaccess"
# Production template: the only .env-like file in the package (placeholders only, no key, no password)
cp scripts/env.production.example "${APP}/.env.example"

# 3. Production vendor/ built in the container (no dev packages; compiled config is never cached)
docker compose exec -T -w "/app/${APP}" app composer install --no-dev --optimize-autoloader --no-interaction
# Laravel rebuilds its package manifest on the first request; never ship one built from local state.
find "${APP}/bootstrap/cache" -type f ! -name .gitignore -delete

# 4. Public files for public_html (Layout B): index.php points at ../${APP_DIR_NAME}/
rsync -a --exclude='.DS_Store' --exclude='*.map' --exclude='hot' --exclude='storage' public/ "${PUB}/"
sed -i.bak "s#__DIR__\.'/\.\./#__DIR__.'/../${APP_DIR_NAME}/#g" "${PUB}/index.php" && rm "${PUB}/index.php.bak"
test "$(grep -c "/../${APP_DIR_NAME}/" "${PUB}/index.php")" -eq 3

# 5. Reproducible zips: fixed mtimes and modes, sorted entries, no extra attributes
find "${BUILD}" -type d -exec chmod 755 {} +
find "${BUILD}" -type f -exec chmod 644 {} +
find "${BUILD}" -exec touch -h -t "${FIXED_TIME}" {} +
(cd "${BUILD}" && find "${APP_DIR_NAME}" | sort | zip -X -q "../classpulse-app.zip" -@)
(cd "${PUB}" && find . -mindepth 1 | sed 's#^\./##' | sort | zip -X -q "../../classpulse-public_html.zip" -@)

# 6. install.sql = schema + the migrations rows only (no users, no classes, no students)
docker compose exec -T db mariadb -uroot -p"${DBROOT}" -e "DROP DATABASE IF EXISTS classpulse_release; CREATE DATABASE classpulse_release CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON classpulse_release.* TO 'classpulse'@'%';"
docker compose exec -T -e DB_DATABASE=classpulse_release app php artisan migrate --force
{ docker compose exec -T db mariadb-dump -uroot -p"${DBROOT}" --no-data --skip-add-drop-table --skip-comments --skip-dump-date classpulse_release
  docker compose exec -T db mariadb-dump -uroot -p"${DBROOT}" --no-create-info --skip-comments --skip-dump-date classpulse_release migrations; } > dist/install.sql
docker compose exec -T db mariadb -uroot -p"${DBROOT}" -e "DROP DATABASE classpulse_release;"

# 7. Owner instructions (Spanish) beside the zips, never inside them; checksums of the three files
cp docs/hostinger-deploy.md dist/LEEME-HOSTINGER.md
(cd dist && shasum -a 256 classpulse-app.zip classpulse-public_html.zip install.sql > SHA256SUMS.txt)
rm -rf "${BUILD}"

echo "release built in dist/:"
ls -l dist/classpulse-app.zip dist/classpulse-public_html.zip dist/install.sql dist/SHA256SUMS.txt dist/LEEME-HOSTINGER.md
