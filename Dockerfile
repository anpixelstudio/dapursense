# Hackathon / Cloud Run image — VERA Laravel web (Cloud SQL + Gen AI stub/Gemini via env).
# Build: docker build -t vera .
# Run:   docker run --rm -p 8080:8080 -e APP_KEY=base64:... -e AI_PROVIDER=stub vera
# Deploy: deploy/cloudrun/provision.sh then deploy/cloudrun/deploy.sh (see docs/GCP.md)

FROM composer:2 AS phpdeps
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --ignore-platform-reqs

FROM php:8.4-cli-bookworm AS wayfinder
RUN apt-get update && apt-get install -y --no-install-recommends git unzip libzip-dev \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
ENV APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
ENV APP_ENV=production
COPY . .
COPY --from=phpdeps /app/vendor ./vendor
# Rebuild discovery for --no-dev vendor (host bootstrap/cache is dockerignored).
RUN mkdir -p bootstrap/cache \
    && php artisan package:discover --ansi \
    && php artisan wayfinder:generate --with-form

FROM node:22-bookworm AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
COPY --from=wayfinder /app/resources/js/routes ./resources/js/routes
COPY --from=wayfinder /app/resources/js/actions ./resources/js/actions
COPY --from=wayfinder /app/resources/js/wayfinder ./resources/js/wayfinder
ENV WAYFINDER_SKIP=1
RUN npm run build

FROM php:8.4-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libzip-dev \
    && docker-php-ext-install pdo_pgsql zip pcntl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY . .
COPY --from=phpdeps /app/vendor ./vendor
COPY --from=frontend /app/public/build ./public/build
COPY --from=wayfinder /app/resources/js/routes ./resources/js/routes
COPY --from=wayfinder /app/resources/js/actions ./resources/js/actions
COPY --from=wayfinder /app/resources/js/wayfinder ./resources/js/wayfinder

ENV APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && php artisan package:discover --ansi \
    && chmod -R ug+rwx storage bootstrap/cache \
    && chmod +x deploy/cloudrun/entrypoint.sh

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    PORT=8080

EXPOSE 8080

ENTRYPOINT ["/var/www/html/deploy/cloudrun/entrypoint.sh"]
