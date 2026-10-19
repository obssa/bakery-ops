# PHP 8.3 image for the bakery-ops API. The host machine has PHP 8.0, so every PHP command
# (artisan, composer, pest, phpstan, pint) runs in this image. Build context is the repo root.
#
#   dev : no code baked in; apps/api is bind-mounted by compose.dev.yml; vendor lives in a named volume
#   ci  : code and vendor baked in; used by e2e jobs and, later, by deploys

FROM php:8.3-cli-bookworm AS base

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
        git unzip ca-certificates \
        libpq-dev libzip-dev libicu-dev libpng-dev libjpeg-dev libfreetype6-dev libonig-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql pdo_pgsql pgsql intl bcmath gd zip pcntl opcache \
 && pecl install redis \
 && docker-php-ext-enable redis \
 && apt-get purge -y --auto-remove \
 && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
EXPOSE 8000 8080

# ---------------------------------------------------------------------------- dev
FROM base AS dev
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

# ---------------------------------------------------------------------------- ci
FROM base AS ci
COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader
COPY apps/api ./
RUN composer dump-autoload --optimize \
 && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
 && chmod -R a+rwX storage bootstrap/cache
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
