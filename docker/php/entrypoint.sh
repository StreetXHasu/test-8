#!/bin/sh
set -eu

cd /app

if [ "${1:-serve}" = "serve" ]; then
    php docker/php/wait-for-database.php
    php artisan migrate --force --no-interaction
    chown -R www-data:www-data storage bootstrap/cache
    php-fpm -D
    exec nginx -g 'daemon off;'
fi

exec "$@"
