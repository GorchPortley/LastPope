# syntax=docker/dockerfile:1.7
#
# LastPope app image (Laravel + Wave + Filament + Livewire).
#
#   php-base  pinned serversideup/php 8.3 (nginx + PHP-FPM) plus the extra PHP extensions
#   vendor    composer install --no-dev (needs the PHP extensions, so it builds on php-base)
#   frontend  npm ci && npm run build (Vite). Needs vendor/ too: Tailwind scans the Filament
#             views and imports the Filament preset from vendor/filament.
#   runtime   php-base + app code + vendor + public/build   <- the image that gets used
#
# Build from the repository root:  docker build -f docker/app/Dockerfile -t lastpope-app .
# (compose.yaml does this for you.)

# PHP 8.3.36 (v5.0.2): fixes the critical PHP2Shell remote code execution bugs CVE-2026-109618/109619. Pinned by digest
# as well, so the tag cannot change under us; to update, take the next release tag and its digest from Docker Hub.
ARG PHP_IMAGE=serversideup/php:8.3-fpm-nginx-v5.0.2@sha256:e6e9d1a027d603dd6399adc66cb85f83e474ca11fb521e1c2eb1411a82dd5ff0
ARG NODE_IMAGE=node:22.23-alpine3.24

# ---------------------------------------------------------------------------
FROM ${PHP_IMAGE} AS php-base

USER root

# Already in the base image: pdo_mysql, redis, zip, pcntl, opcache, mbstring, xml, curl, ...
# Added here: gd (images, PDF/dompdf), exif, intl (Number::currency, Filament), bcmath.
RUN install-php-extensions gd exif intl bcmath \
    && php -m | grep -i -x -E 'gd|exif|intl|bcmath|zip|pdo_mysql|redis' | sort | tr '\n' ' ' && echo

# Development only (compose.dev.yaml): make www-data match your host user so bind-mounted
# files stay writable by both. Production leaves the defaults (33:33) alone.
ARG USER_ID=33
ARG GROUP_ID=33
RUN if [ "${USER_ID}:${GROUP_ID}" != "33:33" ]; then \
        docker-php-serversideup-set-id www-data "${USER_ID}:${GROUP_ID}" \
        && docker-php-serversideup-set-file-permissions --owner "${USER_ID}:${GROUP_ID}" --service nginx; \
    fi

WORKDIR /var/www/html

# ---------------------------------------------------------------------------
FROM php-base AS vendor

# 1) dependencies only: this layer is cached until composer.json / composer.lock change.
#    GitHub limits anonymous downloads (~60/hour per IP) and ~170 packages is more than that.
#    If the build stops with "Could not authenticate against github.com", create a GitHub token
#    (no scopes needed) and build with:
#      COMPOSER_AUTH='{"github-oauth":{"github.com":"ghp_yourtoken"}}' docker compose build
#    compose passes it as a build secret, so it never ends up in the image.
COPY composer.json composer.lock ./
RUN --mount=type=secret,id=composer_auth \
    --mount=type=cache,target=/composer/cache \
    if [ -s /run/secrets/composer_auth ]; then export COMPOSER_AUTH="$(cat /run/secrets/composer_auth)"; fi; \
    composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

# 2) application code, then build the optimised autoloader (classmap includes app/ and wave/).
#    --no-scripts: package:discover & friends need a booted app (database, env) and run at
#    container start instead (the package manifest is rebuilt automatically on first boot).
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts --no-interaction

# ---------------------------------------------------------------------------
FROM ${NODE_IMAGE} AS frontend

WORKDIR /app
COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm npm ci

COPY . .
COPY --from=vendor /var/www/html/vendor ./vendor
RUN npm run build

# ---------------------------------------------------------------------------
FROM php-base AS runtime

# Production defaults. compose.yaml adds the per-container settings (AUTORUN_*, upload limits).
ENV PHP_OPCACHE_ENABLE=1 \
    TRUSTED_PROXY=local \
    NGINX_HTTP_PORT=8080 \
    NGINX_LISTEN_IP_PROTOCOL=ipv4 \
    LOG_OUTPUT_LEVEL=warn

# app code + optimised vendor/ (everything .dockerignore did not exclude)
COPY --from=vendor --chown=www-data:www-data /var/www/html /var/www/html
# freshly built Vite assets (public/build is excluded from the build context on purpose)
COPY --from=frontend --chown=www-data:www-data /app/public/build /var/www/html/public/build

# Our own start-up hooks (run by serversideup's entrypoint, after its Laravel automations).
COPY --chmod=0755 docker/app/entrypoint.d/ /etc/entrypoint.d/

# TLS is terminated by Caddy: tell PHP "HTTPS=on" when X-Forwarded-Proto says https.
COPY docker/app/nginx/00-forwarded-proto.conf /etc/nginx/conf.d/00-forwarded-proto.conf
RUN sed -i 's|^fastcgi_param  HTTPS .*$|fastcgi_param  HTTPS              $lp_https if_not_empty;|' /etc/nginx/fastcgi_params \
    && grep -q 'lp_https' /etc/nginx/fastcgi_params

# Writable runtime directories. storage/app and storage/logs are mounted from named volumes
# by compose.yaml; the rest of storage/ and bootstrap/cache are throwaway per container.
RUN mkdir -p \
        storage/app/public storage/app/private storage/logs \
        storage/framework/cache/data storage/framework/sessions storage/framework/views storage/framework/testing \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

USER www-data

EXPOSE 8080
