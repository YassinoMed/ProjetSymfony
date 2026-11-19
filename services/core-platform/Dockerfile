# syntax=docker/dockerfile:1

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --optimize-autoloader
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

FROM php:8.5-fpm-alpine AS runtime
RUN apk add --no-cache icu-dev libzip-dev libpq-dev oniguruma-dev $PHPIZE_DEPS \
    && docker-php-ext-install -j1 intl pdo_pgsql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

WORKDIR /app
COPY --from=vendor /app /app
RUN mkdir -p var/cache var/log && chown -R www-data:www-data var

USER www-data
