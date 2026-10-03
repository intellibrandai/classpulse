#!/usr/bin/env bash
# Builds the UPDATE kit for a site that is ALREADY installed on Hostinger (never uploads anything) into dist/update/:
#   classpulse-update-app.zip           code for the folder classpulse-app (flat: extract INSIDE classpulse-app)
#   classpulse-update-public_html.zip   public assets only (flat: extract INSIDE public_html; no index.php, no .htaccess)
#   migrations/NN_<name>.sql + README   incremental SQL, one file per migration, each ending with its `migrations` row
#   manifest.json, SHA256SUMS.txt, LEEME-ACTUALIZAR-HOSTINGER.md (the Spanish guide, copy of docs/hostinger-update.md)
# vendor/ is shipped ONLY when composer.lock differs from the baseline (CLASSPULSE_BASELINE_REF, default: the last "release:" commit).
# Composer and the scratch database run in the Docker services. Prove the result with scripts/verify-update.sh.
set -euo pipefail
export TZ=UTC LC_ALL=C
DBROOT=classpulse_local_root   # throwaway root password of the local Docker MariaDB only
SCRATCH_DB=classpulse_update
FIXED_TIME=202601010000
OUT=dist/update
BUILD=dist/update-build
APPB="${BUILD}/app"
PUBB="${BUILD}/public"

BASELINE_REF="${CLASSPULSE_BASELINE_REF:-$(git log --grep='^release:' -1 --format=%H)}"
test -n "${BASELINE_REF}" || { echo "no baseline commit: set CLASSPULSE_BASELINE_REF" >&2; exit 1; }
GIT_SHA="$(git rev-parse --short HEAD)"
DIRTY=""; if [ -n "$(git status --porcelain --untracked-files=normal -- . ':!dist')" ]; then DIRTY="-dirty"; fi
VERSION="${GIT_SHA}${DIRTY}-$(date -u +%Y%m%d)"
LOCK_NOW="$(shasum -a 256 composer.lock | cut -d' ' -f1)"
LOCK_BASE="$(git show "${BASELINE_REF}:composer.lock" | shasum -a 256 | cut -d' ' -f1)"
VENDOR=false; if [ "${LOCK_NOW}" != "${LOCK_BASE}" ]; then VENDOR=true; fi

rm -rf "${BUILD}" "${OUT}"
mkdir -p "${APPB}" "${PUBB}" "${OUT}/migrations"

# 1. App code. Whitelist: nothing else of the project root can end up here (no .env, storage/, public/, tests, docs, scripts).
rsync -aR --exclude='.DS_Store' --exclude='*.map' --exclude='*.log' \
  app bootstrap/app.php bootstrap/providers.php config routes resources database/migrations artisan composer.json composer.lock "${APPB}/"
if [ "${VENDOR}" = true ]; then
  docker compose exec -T -w "/app/${APPB}" app composer install --no-dev --optimize-autoloader --no-interaction
  rm -rf "${APPB}/bootstrap/cache"
fi

# 2. Public assets: every file of the asset folders (idempotent overwrite), never index.php, .htaccess or robots.txt of the live site.
(cd public && rsync -aR --exclude='.DS_Store' --exclude='*.map' css js brand fonts favicon.ico "../${PUBB}/")

# 3. Incremental SQL: `migrate --pretend` on an empty scratch database lists the SQL of every migration; split it per file.
docker compose exec -T db mariadb -uroot -p"${DBROOT}" -e "DROP DATABASE IF EXISTS ${SCRATCH_DB}; CREATE DATABASE ${SCRATCH_DB} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON ${SCRATCH_DB}.* TO 'classpulse'@'%';"
PRETEND="$(docker compose exec -T -e DB_DATABASE=${SCRATCH_DB} app php artisan migrate --pretend --force --no-ansi)"
docker compose exec -T db mariadb -uroot -p"${DBROOT}" -e "DROP DATABASE ${SCRATCH_DB};"
printf '%s\n' "${PRETEND}" | awk -v dir="${OUT}/migrations" '
  /^  [0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[a-z0-9_]+ \.+/ {
    if (file != "") close_file()
    name = $1; n++
    file = sprintf("%s/%02d_%s.sql", dir, n, name)
    print "-- ClassPulse migration " name > file
    print "-- Apply ONLY if `SELECT migration FROM migrations ORDER BY id;` does not list it. Run the whole file in phpMyAdmin > SQL." >> file
    next
  }
  /^  ⇂ / { if (file != "") { sub(/^  ⇂ /, ""); sub(/[ \t]+$/, ""); print $0 ";" >> file } next }
  function close_file() {
    printf "INSERT INTO `migrations` (`migration`, `batch`) SELECT %c%s%c, COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`;\n", 39, name, 39 >> file
    close(file)
  }
  END { if (file != "") close_file() }'
test "$(ls "${OUT}/migrations"/*.sql | wc -l | tr -d ' ')" -eq "$(find database/migrations -name '*.php' | wc -l | tr -d ' ')" || { echo "migration SQL count differs from database/migrations" >&2; exit 1; }
NEW_MIGRATIONS="$(git diff --name-only --diff-filter=A "${BASELINE_REF}" -- database/migrations | sed 's#.*/##; s#\.php$##' | paste -sd, -)"
cat > "${OUT}/migrations/README.txt" <<README
MIGRACIONES (SQL incremental) - ClassPulse ${VERSION}

La actualizacion de la funcion "Cuenta" (cambiar correo y contrasena) NO anade ninguna migracion.
Esta carpeta trae el SQL de TODAS las migraciones del proyecto (un archivo por migracion, en orden) para
que puedas aplicar solo las que le falten a tu base, sea cual sea la version que tengas instalada.

1. En phpMyAdmin > tu base > pestana SQL ejecuta:   SELECT migration FROM migrations ORDER BY id;
2. Compara la lista con los nombres de los archivos de esta carpeta (el nombre esta dentro de cada archivo y termina el
   archivo en la fila INSERT INTO migrations).
3. Aplica SOLO los archivos cuya migracion NO aparece en la lista, de menor a mayor numero, uno por uno
   (pestana SQL > pegar el contenido > Continuar). Cada archivo termina con la fila que lo registra en la tabla migrations.
4. Si la lista ya contiene todas, no hagas nada.
5. NUNCA importes install.sql sobre una base que ya tiene datos: fallaria con "la tabla ya existe" y no es una actualizacion.

Migraciones nuevas desde la base de comparacion de este kit (${BASELINE_REF:0:7}): ${NEW_MIGRATIONS:-ninguna}
README

# 4. Reproducible zips (flat: extracted INSIDE the existing folders)
find "${BUILD}" -type d -exec chmod 755 {} +
find "${BUILD}" -type f -exec chmod 644 {} +
find "${BUILD}" -exec touch -h -t "${FIXED_TIME}" {} +
ROOT_DIR="$(pwd)"
(cd "${APPB}" && find . -mindepth 1 | sed 's#^\./##' | sort | zip -X -q "${ROOT_DIR}/${OUT}/classpulse-update-app.zip" -@)
(cd "${PUBB}" && find . -mindepth 1 | sed 's#^\./##' | sort | zip -X -q "${ROOT_DIR}/${OUT}/classpulse-update-public_html.zip" -@)

# 5. manifest.json: version, composer.lock hashes, every shipped file with its sha256
files_json() { (cd "$1" && find . -type f | sed 's#^\./##' | sort | while read -r f; do printf '%s\t%s\n' "$f" "$(shasum -a 256 "$f" | cut -d' ' -f1)"; done) | jq -R -s -c 'split("\n") | map(select(length > 0) | split("\t") | {path: .[0], sha256: .[1]})'; }
jq -n --arg version "${VERSION}" --arg sha "${GIT_SHA}${DIRTY}" --arg date "$(date -u +%Y-%m-%d)" --arg base "${BASELINE_REF}" \
  --arg lock "${LOCK_NOW}" --arg lockbase "${LOCK_BASE}" --argjson vendor "${VENDOR}" \
  --argjson app "$(files_json "${APPB}")" --argjson pub "$(files_json "${PUBB}")" \
  --argjson migs "$(ls "${OUT}/migrations" | grep '\.sql$' | jq -R . | jq -s -c .)" \
  '{version: $version, git: $sha, date: $date, baseline_ref: $base, composer_lock_sha256: $lock, baseline_composer_lock_sha256: $lockbase,
    vendor_included: $vendor, never_shipped: [".env", "storage/", "bootstrap/cache/", "public_html/index.php", "public_html/.htaccess", "public_html/robots.txt"],
    app_files: $app, public_files: $pub, migrations: $migs}' > "${OUT}/manifest.json"

# 6. Guide (Spanish) beside the zips and checksums of every file of the kit
cp docs/hostinger-update.md "${OUT}/LEEME-ACTUALIZAR-HOSTINGER.md"
(cd "${OUT}" && { shasum -a 256 classpulse-update-app.zip classpulse-update-public_html.zip manifest.json LEEME-ACTUALIZAR-HOSTINGER.md migrations/*; } > SHA256SUMS.txt)
rm -rf "${BUILD}"

echo "update kit ${VERSION} built in ${OUT}/ (vendor included: ${VENDOR}; baseline ${BASELINE_REF:0:7})"
ls -l "${OUT}" "${OUT}/migrations"
