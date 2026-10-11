# syntax=docker/dockerfile:1

# Bookshelf's production images, built from one file:
#   docker build --target app -t bookshelf-app .    PHP-FPM running the application
#   docker build --target web -t bookshelf-web .    nginx serving static files and passing the rest to app
# compose.yaml builds both.

# ---- base: PHP-FPM with the extensions Bookshelf needs ----------------------------------------------------------
FROM php:8.5-fpm-trixie AS base

# pdo_sqlite, mbstring and OPcache are part of the official image; intl needs the ICU library first.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev \
    && docker-php-ext-install -j"$(nproc)" intl \
    && rm -rf /var/lib/apt/lists/*

# Start from PHP's recommended production settings, then apply Bookshelf's own.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/production.ini "$PHP_INI_DIR/conf.d/zz-bookshelf.ini"
COPY docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-bookshelf.conf

WORKDIR /srv/bookshelf

# ---- vendor: Composer dependencies, without development tools -------------------------------------------------
FROM base AS vendor

# Composer unpacks downloaded packages with unzip; git is its fallback for packages without an archive.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# Dependencies change less often than code: installing them in their own layer lets Docker reuse it.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-autoloader --no-scripts --no-interaction --no-progress
COPY src/ src/
RUN composer dump-autoload --no-dev --classmap-authoritative

# ---- app: the image that runs Bookshelf ---------------------------------------------------------------------------
FROM base AS app

COPY --from=vendor /srv/bookshelf/vendor/ vendor/
COPY bin/ bin/
COPY config/ config/
COPY migrations/ migrations/
COPY public/ public/
COPY src/ src/
COPY templates/ templates/

# var/ holds the SQLite database; compose.yaml mounts a volume there. Only this directory is writable by PHP.
RUN mkdir var && chown www-data:www-data var
ENV BOOKSHELF_DATABASE=/srv/bookshelf/var/bookshelf.sqlite

# PHP-FPM's master process runs as www-data too, so nothing in this container runs as root.
USER www-data

# ---- web: nginx with the static files -----------------------------------------------------------------------------
FROM nginx:1.30-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY public/ /srv/bookshelf/public/
