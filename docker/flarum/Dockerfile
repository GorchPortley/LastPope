# Flarum for LastPope: the upstream crazymax/flarum image plus a few small
# provisioning scripts so that `docker compose up -d` gives a ready-to-use forum
# without clicking through an installer.
#
# What we add on top of upstream (see docker/flarum/cont-init.d/ and the RUN step below):
#   * the first admin account comes from FLARUM_ADMIN_* instead of the
#     well-known default login flarum/flarum (patch-installer.sh)
#   * extra extensions (default: flarum/embed) are installed from FLARUM_EXTENSIONS
#   * the master API key FLARUM_API_KEY is written to the database, so the
#     Laravel app can talk to the forum API without any manual step
#   * Flarum's default extensions plus flarum/embed are enabled once, and Flarum's
#     mail settings are copied from MAIL_* on the very first start
#
# crazymax/flarum ships Flarum 1.8.x (PHP 8.5). Flarum 2.x is not available in
# this image yet, so extensions must be Flarum 1.x compatible.
# Pinned by digest: crazymax re-publishes the same tag (1.8.20 was rebuilt on 2026-10-21 with a
# different init system), which would otherwise change the image under us without warning.
# To update: pull the new tag, run `docker image inspect crazymax/flarum:<tag> --format '{{index .RepoDigests 0}}'`
# and paste the digest here, then rebuild and check `docker compose logs flarum`.
FROM crazymax/flarum:1.8.20@sha256:69bbe8f7f80078162058cb166dca52090b93e79751ca3c2ff1614688e7122185

# FLARUM_AUTO_ENABLE: upstream leaves *every* extension disabled after the install, so we
# switch on Flarum's usual default set (the same list the official installer uses) plus the
# embed extension, once. Tags is required by the Laravel integration (design/driver/listing tags).
ENV FLARUM_ADMIN_USER=forumadmin \
    FLARUM_EXTENSIONS="flarum/embed:^1.8" \
    FLARUM_AUTO_ENABLE="flarum-tags flarum-embed flarum-approval flarum-bbcode flarum-emoji flarum-lang-english flarum-flags flarum-likes flarum-lock flarum-markdown flarum-mentions flarum-statistics flarum-sticky flarum-subscriptions flarum-suspend"

COPY patch-installer.sh /tmp/patch-installer.sh
COPY cont-init.d/02-zz-extensions.sh /etc/cont-init.d/02-zz-extensions.sh
COPY cont-init.d/03-zz-provision.sh /usr/local/share/lastpope/provision.sh
COPY enable-extensions.php /usr/local/share/lastpope/enable-extensions.php

# 02-zz-extensions only appends to /data/extensions/list, so it must run BEFORE upstream installs
# Flarum; legacy /etc/cont-init.d scripts still do that under s6-overlay v3.
# provision (API key, enabling extensions, mail settings) must run AFTER the install:
#   * s6-overlay v3 layout: a oneshot service that depends on upstream's `config` service, and
#     nginx / php-fpm wait for it, so the forum is never served half set up. A provisioning
#     failure is logged but does not keep the forum down (same as before).
#   * older cont-init.d layout: it simply runs after 03-config.sh.
RUN sh /tmp/patch-installer.sh \
    && rm /tmp/patch-installer.sh \
    && chmod 0755 /etc/cont-init.d/02-zz-extensions.sh /usr/local/share/lastpope/provision.sh \
    && if [ -d /etc/s6-overlay/s6-rc.d/config ]; then \
         d=/etc/s6-overlay/s6-rc.d/lastpope-provision \
         && mkdir -p "$d/dependencies.d" \
         && echo oneshot > "$d/type" \
         && touch "$d/dependencies.d/config" \
         && echo 'foreground { /usr/local/share/lastpope/provision.sh } exit 0' > "$d/up" \
         && touch /etc/s6-overlay/s6-rc.d/nginx/dependencies.d/lastpope-provision \
                  /etc/s6-overlay/s6-rc.d/php-fpm/dependencies.d/lastpope-provision; \
       else \
         cp /usr/local/share/lastpope/provision.sh /etc/cont-init.d/03-zz-provision.sh; \
       fi
