#!/bin/bash
# Gets a Claude Code cloud session ready to work: PHP dependencies, .env, the SQLite
# database with demo data. Safe to re-run; later runs only do what is missing.
set -euo pipefail

if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

cd "${CLAUDE_PROJECT_DIR:-$(dirname "$0")/../..}"

export COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1

# A failed source clone once made composer rewrite this repo's origin remote; put it back.
origin=$(git remote get-url origin 2>/dev/null || true)
restore_origin() {
  if [ -n "$origin" ] && [ "$(git remote get-url origin 2>/dev/null || true)" != "$origin" ]; then
    git remote set-url origin "$origin"
    echo "session-start: restored the origin remote to $origin" >&2
  fi
}
trap restore_origin EXIT

composer install --prefer-dist --no-progress --quiet

# No npm step: nothing uses Vite (Filament ships its own assets).

[ -f .env ] || cp .env.example .env
grep -q '^APP_KEY=base64:' .env || php artisan key:generate --no-interaction --quiet

fresh=0
if [ ! -f database/database.sqlite ]; then
  touch database/database.sqlite
  fresh=1
fi
php artisan migrate --force --no-interaction --quiet
if [ "$fresh" = 1 ]; then
  php artisan db:seed --force --no-interaction --quiet
fi

echo "session-start: ready (demo login admin@example.com / password; php artisan serve to run it)"
