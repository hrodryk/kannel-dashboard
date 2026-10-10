#!/usr/bin/env bash
set -euo pipefail

if [ "${1-}" = "artisan" ]; then
    shift
    exec su-exec www-data php /var/www/html/artisan "$@"
fi

cd /var/www/html

if [ "${SKIP_LARAVEL_SETUP-}" != "1" ]; then
    echo "> Ensuring storage permissions..."
    [ -d storage/framework/cache ] || mkdir -p storage/framework/cache
    [ -d storage/framework/sessions ] || mkdir -p storage/framework/sessions
    [ -d storage/framework/views ] || mkdir -p storage/framework/views
    [ -d storage/logs ] || mkdir -p storage/logs
    [ -d bootstrap/cache ] || mkdir -p bootstrap/cache
    chown -R www-data:www-data storage bootstrap/cache
    chmod -R ug+rwx storage bootstrap/cache

    echo "> Running config cache..."
    su-exec www-data php /var/www/html/artisan config:cache --no-interaction || true
    su-exec www-data php /var/www/html/artisan route:cache --no-interaction || true
    su-exec www-data php /var/www/html/artisan view:cache --no-interaction || true
    su-exec www-data php /var/www/html/artisan event:cache --no-interaction || true

    if [ "${RUN_MIGRATIONS-}" = "1" ]; then
        echo "> Running migrations..."
        su-exec www-data php /var/www/html/artisan migrate --force --no-interaction
    fi
fi

echo "> Starting supervisord…"
exec /usr/bin/supervisord -c /etc/supervisord.conf
