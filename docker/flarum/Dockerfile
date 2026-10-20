# Flarum for LastPope: the upstream crazymax/flarum image plus a few small
# provisioning scripts so that `docker compose up -d` gives a ready-to-use forum
# without clicking through an installer.
#
# What we add on top of upstream (see docker/flarum/cont-init.d/):
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
FROM crazymax/flarum:1.8.20

# FLARUM_AUTO_ENABLE: upstream leaves *every* extension disabled after the install, so we
# switch on Flarum's usual default set (the same list the official installer uses) plus the
# embed extension, once. Tags is required by the Laravel integration (design/driver/listing tags).
ENV FLARUM_ADMIN_USER=forumadmin \
    FLARUM_EXTENSIONS="flarum/embed:^1.8" \
    FLARUM_AUTO_ENABLE="flarum-tags flarum-embed flarum-approval flarum-bbcode flarum-emoji flarum-lang-english flarum-flags flarum-likes flarum-lock flarum-markdown flarum-mentions flarum-statistics flarum-sticky flarum-subscriptions flarum-suspend"

COPY patch-installer.sh /tmp/patch-installer.sh
COPY cont-init.d/ /etc/cont-init.d/
COPY enable-extensions.php /usr/local/share/lastpope/enable-extensions.php

RUN sh /tmp/patch-installer.sh \
    && rm /tmp/patch-installer.sh \
    && chmod 0755 /etc/cont-init.d/02-zz-extensions.sh /etc/cont-init.d/03-zz-provision.sh
