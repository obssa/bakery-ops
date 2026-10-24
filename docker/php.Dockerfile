# PHP 8.3 image for the bakery-ops API. The host machine has PHP 8.0, so every PHP command
# (artisan, composer, pest, phpstan, pint) runs in this image. Build context is the repo root.
#
#   dev : no code baked in; apps/api is bind-mounted by compose.dev.yml; vendor lives in a named volume
#   ci  : code and vendor baked in; used by e2e jobs and, later, by deploys

FROM php:8.3-cli-bookworm AS base

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

# phpredis is built from its tagged GitHub release, pinned by version and checksum, not fetched from pecl.php.net:
# pecl was unreachable for hours on 2026-10-21 and every image build failed with it. The checksum also keeps the
# build reproducible. To upgrade, change both values (sha256 of the release tarball).
ARG PHPREDIS_VERSION=6.3.0
ARG PHPREDIS_SHA256=cb8f81df1a275599e4f8ddcfec7e1f65ed1953e6f5673649149fd680ebff4cad

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
        git unzip ca-certificates \
        libpq-dev libzip-dev libicu-dev libpng-dev libjpeg-dev libwebp-dev libfreetype6-dev libonig-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql pdo_pgsql pgsql intl bcmath gd exif zip pcntl opcache \
 && mkdir -p /usr/src/php/ext/redis \
 && curl -fsSL --retry 5 --retry-all-errors -o /tmp/phpredis.tar.gz \
        "https://github.com/phpredis/phpredis/archive/refs/tags/${PHPREDIS_VERSION}.tar.gz" \
 && echo "${PHPREDIS_SHA256}  /tmp/phpredis.tar.gz" | sha256sum -c - \
 && tar -xzf /tmp/phpredis.tar.gz -C /usr/src/php/ext/redis --strip-components=1 \
 && docker-php-ext-install redis \
 && docker-php-source delete \
 && apt-get purge -y --auto-remove \
 && rm -rf /var/lib/apt/lists/* /tmp/phpredis.tar.gz

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
