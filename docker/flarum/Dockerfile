# syntax=docker/dockerfile:1.7
#
# LastPope forum image: Flarum 2 and its extensions on the same pinned serversideup/php 8.3 (nginx + PHP-FPM)
# base as the app image. Build context: docker/flarum (compose.yaml does this for you).
#
#   php-base  pinned serversideup/php plus the extra PHP extension Flarum needs
#   vendor    composer install of docker/flarum/composer.json + composer.lock (exact versions, no network
#             access to packagist needed at run time; nothing is ever installed by the running container)
#   runtime   php-base + Flarum's launcher files + vendor/ + nginx site for /forum + start-up scripts
#
# Layout inside the image (/var/www/html is Flarum's root):
#   flarum, site.php, extend.php, public/index.php   launcher files of flarum/flarum 2.0.0 (skeleton/)
#   vendor/                                          from composer.lock
#   config.php                                       written at every start from the environment
#   storage/        volume flarum-storage            caches, logs, sessions, GDPR exports
#   public/assets/  volume flarum-assets             compiled JS/CSS, avatars, uploads, logo
#
# Start-up (entrypoint.d/60-flarum.sh -> lib/start.php): wait for the database, install on an empty
# database or `php flarum migrate` on an existing one, API key, extensions, assets, caches.
# The scheduler container uses this image too, with the command `flarum-scheduler`.
#
# To change what is installed (a new extension, a newer Flarum): edit composer.json, run
# `composer update --no-install --no-scripts` in this directory to refresh composer.lock, rebuild.
# See "Forum" in docs/DEPLOY.md.

# PHP 8.3.36 (v5.0.2): fixes the critical PHP2Shell remote code execution bugs CVE-2026-109618/109619. Pinned by digest
# as well, so the tag cannot change under us; to update, take the next release tag and its digest from Docker Hub.
ARG PHP_IMAGE=serversideup/php:8.3-fpm-nginx-v5.0.2@sha256:e6e9d1a027d603dd6399adc66cb85f83e474ca11fb521e1c2eb1411a82dd5ff0

# ---------------------------------------------------------------------------
FROM ${PHP_IMAGE} AS php-base

USER root

RUN apt-get update
RUN apt-get upgrade -y

# Already in the base image: pdo_mysql, zip, dom, fileinfo, mbstring, openssl, tokenizer, curl, opcache, ...
# Added here: gd (Flarum refuses to install without it: avatars, image uploads), exif (rotates photos by
# their camera orientation) and intl (proper plural forms in the translations; Flarum falls back to a
# polyfill without it).
RUN install-php-extensions gd exif intl \
    && php -m | grep -i -x -E 'gd|exif|intl|zip|pdo_mysql|dom|fileinfo|mbstring' | sort | tr '\n' ' ' && echo

WORKDIR /var/www/html

# ---------------------------------------------------------------------------
FROM php-base AS vendor

# GitHub limits anonymous downloads (~60/hour per IP) and Flarum + extensions is ~160 packages. If the build
# stops with "Could not authenticate against github.com", build with a GitHub token (no scopes needed):
#   COMPOSER_AUTH='{"github-oauth":{"github.com":"ghp_yourtoken"}}' docker compose build flarum
# compose passes it as a build secret, so it never ends up in the image.
COPY composer.json composer.lock ./
RUN --mount=type=secret,id=composer_auth \
    --mount=type=cache,target=/composer/cache \
    if [ -s /run/secrets/composer_auth ]; then export COMPOSER_AUTH="$(cat /run/secrets/composer_auth)"; fi; \
    composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader \
    && test -f vendor/flarum/core/composer.json

# ---------------------------------------------------------------------------
FROM php-base AS runtime

# OPcache keeps checking file times (the default of this base image is to never look again): Flarum rewrites
# compiled templates at run time, for example after an extension is switched on in the administration.
# Caddy is the only thing in front of nginx and sends X-Forwarded-For (TRUSTED_PROXY=local trusts private ranges).
ENV PHP_OPCACHE_ENABLE=1 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=1 \
    PHP_OPCACHE_REVALIDATE_FREQ=2 \
    PHP_MEMORY_LIMIT=512M \
    PHP_FPM_PM_MAX_CHILDREN=10 \
    TRUSTED_PROXY=local \
    NGINX_HTTP_PORT=8000 \
    NGINX_LISTEN_IP_PROTOCOL=ipv4 \
    LOG_OUTPUT_LEVEL=warn

COPY --from=vendor --chown=www-data:www-data /var/www/html/vendor ./vendor
COPY --chown=www-data:www-data skeleton/ ./

# nginx: serve Flarum under /forum (replaces the stock site template of the base image, whose own
# start-up script fills in the placeholders).
COPY nginx/http.conf.template /etc/nginx/site-opts.d/http.conf.template

# Start-up scripts and the scheduler loop.
COPY --chmod=0755 entrypoint.d/ /etc/entrypoint.d/
COPY --chmod=0755 lib/ /usr/local/lib/lastpope/
COPY --chmod=0755 bin/flarum-scheduler /usr/local/bin/flarum-scheduler

# Writable runtime directories. storage/ and public/assets/ are mounted from named volumes by compose.yaml
# (a new volume starts with this content and ownership); start.php re-creates anything missing.
RUN mkdir -p \
        storage/cache storage/formatter storage/less storage/locale storage/logs storage/sessions storage/tmp storage/views \
        public/assets/avatars \
    && chown www-data:www-data /var/www/html \
    && chown -R www-data:www-data storage public \
    && chmod -R ug+rwX storage public/assets

USER www-data

EXPOSE 8000
