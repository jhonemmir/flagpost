# syntax=docker/dockerfile:1

# Install production dependencies separately so the layer is cached.
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader

FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql opcache \
    && rm -rf /var/lib/apt/lists/*

# Production defaults: do not advertise PHP, keep errors in the log, and let
# opcache work for the CLI server. The image is immutable, so file timestamps
# do not need to be checked.
RUN { \
        echo 'expose_php=Off'; \
        echo 'display_errors=Off'; \
        echo 'log_errors=On'; \
        echo 'opcache.enable_cli=1'; \
        echo 'opcache.validate_timestamps=0'; \
    } > /usr/local/etc/php/conf.d/zz-flagpost.ini

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .

# The scaffolded cache files may mention dev-only providers (Pail, Pao).
RUN rm -f bootstrap/cache/*.php \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD php -r "exit(@fsockopen('127.0.0.1', 8000) ? 0 : 1);"

# The built-in server is enough for the demo deployment. Use php-fpm behind a
# reverse proxy if the service ever needs to handle real traffic.
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
