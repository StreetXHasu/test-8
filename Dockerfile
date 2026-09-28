FROM php:8.5-fpm-alpine

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
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apk del --no-cache linux-headers $PHPIZE_DEPS \
    && rm -rf /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    APP_ENV=prod

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --optimize \
    && APP_SECRET=build \
       DATABASE_URL="postgresql://app:app@database:5432/app?serverVersion=16&charset=utf8" \
       JWT_SECRET=build \
       JWT_TTL=86400 \
       S3_ENDPOINT=http://garage:3900 \
       S3_REGION=garage \
       S3_BUCKET=images \
       S3_ACCESS_KEY=build \
       S3_SECRET_KEY=build \
       DEFAULT_URI=http://localhost:8080 \
       php bin/console assets:install public --no-debug \
    && mkdir -p var/cache var/log var/share \
    && chown -R www-data:www-data var

COPY docker/php/php.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
RUN chmod +x docker/php/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["docker/php/entrypoint.sh"]
CMD ["serve"]
