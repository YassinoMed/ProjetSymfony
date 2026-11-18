# syntax=docker/dockerfile:1

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --optimize-autoloader
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

FROM php:8.5-cli-alpine AS runtime
RUN apk add --no-cache icu-dev libzip-dev postgresql-dev $PHPIZE_DEPS \
    && docker-php-ext-install intl pdo_pgsql zip opcache \
    && apk del $PHPIZE_DEPS

WORKDIR /app
COPY --from=vendor /app /app
RUN mkdir -p var && chown -R www-data:www-data var

USER www-data
CMD ["php", "bin/console", "messenger:consume", "async", "--time-limit=3600", "-vv"]
