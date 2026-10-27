#!/usr/bin/env bash
# Updates this checkout to origin/main and refreshes the app. Run on the server, from the repo root.
# Safe to re-run: every step is idempotent.
set -euo pipefail

cd "$(dirname "$0")/.."

# Optional server-specific overrides, e.g. PHP_BIN=/opt/plesk/php/8.4/bin/php (not committed).
if [ -f .deploy.env ]; then
    # shellcheck disable=SC1091
    . ./.deploy.env
fi
PHP="${PHP_BIN:-php}"
COMPOSER="${COMPOSER_BIN:-composer}"

git fetch --quiet origin main
git reset --hard origin/main

# Unquoted on purpose: COMPOSER_BIN may be "php composer.phar".
$COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress
"$PHP" artisan migrate --force
"$PHP" artisan optimize:clear
"$PHP" artisan optimize
"$PHP" artisan filament:optimize

echo "Deployed $(git rev-parse --short HEAD)"
