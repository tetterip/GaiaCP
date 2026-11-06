#!/bin/sh
# Prepares the storage volume, installs GaiaCP on the first start when GAIACP_ADMIN_EMAIL is set (otherwise the web
# installer at /install does it), migrates after a new image, then runs the container's command (Apache, or the
# scheduler: docker-compose.yml).
set -e
cd /var/www/html

as_www() { runuser -u www-data -- "$@"; }

# storage is a volume: on first start it is empty, and .env lives in it (/.env links there) so it survives a new image
mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs
chown -R www-data:www-data storage

wait_for_database() {
    [ "${DB_CONNECTION:-mysql}" = "sqlite" ] && return 0
    tries=0
    until php -r 'try { new PDO("mysql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT") ?: 3306), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); } catch (Throwable $e) { exit(1); }'; do
        tries=$((tries + 1))
        if [ "$tries" -ge 60 ]; then
            echo "gaiacp: the database at ${DB_HOST} did not answer in 2 minutes" >&2
            exit 1
        fi
        sleep 2
    done
}

installed() { [ -f storage/app/private/installed ]; }

if [ "${GAIACP_ROLE:-web}" = "scheduler" ]; then
    # The scheduler waits for the web container to install GaiaCP
    until installed; do sleep 5; done
    exec runuser -u www-data -- "$@"
fi

if ! installed && [ -n "${GAIACP_ADMIN_EMAIL}" ]; then
    wait_for_database
    echo "gaiacp: installing, first admin ${GAIACP_ADMIN_EMAIL}"
    as_www php artisan gaiacp:install --no-interaction \
        --db-connection="${DB_CONNECTION:-mysql}" --db-host="${DB_HOST}" --db-port="${DB_PORT:-3306}" \
        --db-database="${DB_DATABASE}" --db-username="${DB_USERNAME}" --db-password="${DB_PASSWORD}" \
        --company="${GAIACP_COMPANY:-My hosting company}" --url="${APP_URL:-http://localhost:8080}" \
        --timezone="${GAIACP_TIMEZONE:-UTC}" --country="${GAIACP_COUNTRY:-}" \
        --admin-name="${GAIACP_ADMIN_NAME:-Admin}" --admin-email="${GAIACP_ADMIN_EMAIL}" \
        --admin-password="${GAIACP_ADMIN_PASSWORD}"
elif installed; then
    wait_for_database
    as_www php artisan migrate --force --no-interaction
fi

exec "$@"
