# One image for the web server, queue worker and scheduler.
# FrankenPHP is PHP inside the Caddy web server, which gets and renews the
# HTTPS certificate on its own.

FROM node:22-alpine AS frontend
WORKDIR /src
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ ./
RUN npm run build

FROM composer:2 AS vendor
WORKDIR /src
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY backend/ ./
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative \
    && rm -f .env

FROM dunglas/frankenphp:1-php8.3
RUN install-php-extensions pdo_pgsql pgsql redis intl zip pcntl opcache \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY deploy/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY deploy/Caddyfile /etc/frankenphp/Caddyfile
COPY --from=vendor /src /app/backend
COPY --from=frontend /src/dist /app/frontend/dist
WORKDIR /app/backend
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
