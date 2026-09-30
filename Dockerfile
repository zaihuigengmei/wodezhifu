FROM php:8.3-fpm-alpine

RUN set -eux; \
    apk add --no-cache \
      freetype-dev libjpeg-turbo-dev libpng-dev libzip-dev oniguruma-dev \
      unzip git ca-certificates curl; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli gd zip mbstring bcmath opcache

WORKDIR /var/www/html
COPY --chown=82:82 . /var/www/html/
