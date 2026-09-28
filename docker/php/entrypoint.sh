#!/bin/sh
set -eu

cd /app

if [ "${1:-serve}" = "serve" ]; then
    php docker/php/wait-for-database.php
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
    php bin/console cache:warmup --no-debug
    chown -R www-data:www-data var
    php-fpm -D
    exec nginx -g 'daemon off;'
fi

exec "$@"
