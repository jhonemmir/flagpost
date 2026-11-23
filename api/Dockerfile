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
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .

# The scaffolded cache files may mention dev-only providers (Pail, Pao).
RUN rm -f bootstrap/cache/*.php \
    && php artisan package:discover --ansi

EXPOSE 8000

# The built-in server is enough for the demo deployment. Use php-fpm behind a
# reverse proxy if the service ever needs to handle real traffic.
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
