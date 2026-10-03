#!/usr/bin/env bash
# One-shot, idempotent local setup (Docker only). Run from the project root:
#   ./scripts/setup-local.sh            # install and migrate
#   ./scripts/setup-local.sh --demo     # ...and also load fictitious demo data (prints a demo password once)
# It performs the same steps documented in README.md ("Manual steps"). Safe to re-run: it never resets data.
set -euo pipefail
cd "$(dirname "$0")/.."

DEMO=0
for arg in "$@"; do
  case "$arg" in
    --demo) DEMO=1 ;;
    *) echo "Unknown option: $arg (supported: --demo)" >&2; exit 2 ;;
  esac
done

command -v docker >/dev/null || { echo "Docker is required (Docker Desktop with Compose v2)." >&2; exit 1; }
docker compose version >/dev/null || { echo "Docker Compose v2 is required." >&2; exit 1; }

if [ ! -f .env ]; then
  cp .env.example .env
  echo "Created .env from .env.example"
fi

docker compose up -d --build

echo "Waiting for the app container..."
for _ in $(seq 1 60); do
  docker compose exec -T app php -v >/dev/null 2>&1 && break
  sleep 2
done

docker compose exec -T app composer install --no-interaction --prefer-dist

if ! grep -qE '^APP_KEY=.+' .env; then
  docker compose exec -T app php artisan key:generate --force
fi

# The test database is separate from the dev database (phpunit.xml uses classpulse_test).
docker compose exec -T db mariadb -uroot -pclasspulse_local_root \
  -e "CREATE DATABASE IF NOT EXISTS classpulse_test; GRANT ALL ON classpulse_test.* TO 'classpulse'@'%'; FLUSH PRIVILEGES;"

docker compose exec -T app php artisan migrate --force

if [ "$DEMO" = "1" ]; then
  if docker compose exec -T app php artisan classpulse:demo-seed --days=20 --periods; then
    echo "Demo data loaded. Note the password printed above: it is shown only once."
  else
    echo "Demo seed skipped or refused (it only runs on an empty database with APP_ENV=local)." >&2
  fi
fi

PORT="$(grep -E '^CLASSPULSE_WEB_PORT=' .env | cut -d= -f2)"
echo
echo "ClassPulse is ready: http://localhost:${PORT:-8090}"
