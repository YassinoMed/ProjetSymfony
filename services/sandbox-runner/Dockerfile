# syntax=docker/dockerfile:1

FROM composer:2 AS vendor
WORKDIR /sandbox
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --prefer-dist --no-scripts --optimize-autoloader
COPY . .

FROM php:8.5-cli-alpine AS runtime
RUN apk add --no-cache git unzip icu-dev libzip-dev $PHPIZE_DEPS \
    && docker-php-ext-install intl zip \
    && apk del $PHPIZE_DEPS \
    && addgroup -S sandbox \
    && adduser -S sandbox -G sandbox

WORKDIR /sandbox
COPY --from=vendor /sandbox /sandbox
RUN chown -R sandbox:sandbox /sandbox

USER sandbox
ENTRYPOINT ["php", "bin/sandbox"]
CMD ["list"]
