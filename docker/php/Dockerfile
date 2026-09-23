# syntax=docker/dockerfile:1.7
#
# Zwei Ziele aus einer Datei:
#   app  PHP-FPM mit Anwendung, Abhängigkeiten ohne dev und gebauten Assets (Dienste php, worker)
#   web  Nginx mit dem öffentlichen Verzeichnis aus "app" (Dienst nginx)

FROM composer:2 AS composer

FROM php:8.3-fpm-bookworm AS app

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libicu-dev \
        libzip-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libavif-dev \
        fonts-liberation \
        unzip; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp --with-avif; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql intl gd opcache zip; \
    rm -rf /var/lib/apt/lists/*; \
    cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY docker/php/php-production.ini /usr/local/etc/php/conf.d/zz-hvm-production.ini
COPY docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-hvm-pool.conf

WORKDIR /var/www/html

# Abhängigkeiten zuerst (Layer-Cache), dann Quelltext
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress --prefer-dist

COPY . .
RUN set -eux; \
    composer dump-autoload --no-dev --optimize --classmap-authoritative; \
    php bin/build-assets.php --quiet; \
    rm -f .env; \
    mkdir -p storage/logs storage/cache/twig storage/uploads storage/ratelimit; \
    chown -R www-data:www-data storage

USER www-data
EXPOSE 9000
CMD ["php-fpm"]


FROM nginx:1.27-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY docker/nginx/security-headers.conf /etc/nginx/snippets/security-headers.conf
COPY --from=app /var/www/html/public /var/www/html/public
