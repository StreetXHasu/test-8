FROM php:8.5-fpm-alpine AS base

RUN apk add --no-cache \
        freetype-dev \
        icu-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libpq-dev \
        libwebp-dev \
        linux-headers \
        nginx \
        $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd intl pdo_pgsql \
    && apk del --no-cache linux-headers $PHPIZE_DEPS \
    && rm -rf /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_PROCESS_TIMEOUT=2000

FROM base AS prod

ENV APP_ENV=production

# vendor/ ships in the build context, so the image builds without network access.
# Composer then prunes the dev packages and rebuilds the autoloader and the
# Laravel package manifest for a production install.
COPY . .
RUN mkdir -p \
        bootstrap/cache \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && JWT_SECRET=0123456789abcdef0123456789abcdef composer install --no-interaction --prefer-dist --no-dev --classmap-authoritative \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/php/php.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
RUN chmod +x docker/php/entrypoint.sh bin/generate-secrets

EXPOSE 80

ENTRYPOINT ["docker/php/entrypoint.sh"]
CMD ["serve"]

FROM base AS test

RUN apk add --no-cache $PHPIZE_DEPS linux-headers \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apk del --no-cache $PHPIZE_DEPS linux-headers \
    && rm -rf /tmp/pear

COPY . .
RUN mkdir -p \
        bootstrap/cache \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        var \
    && JWT_SECRET=0123456789abcdef0123456789abcdef composer install --no-interaction --prefer-dist --optimize-autoloader \
    && chown -R www-data:www-data storage bootstrap/cache var

COPY docker/php/php.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/php/php-test.ini /usr/local/etc/php/conf.d/pcov.ini
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
RUN chmod +x docker/php/entrypoint.sh bin/generate-secrets

EXPOSE 80

ENTRYPOINT ["docker/php/entrypoint.sh"]
CMD ["serve"]
