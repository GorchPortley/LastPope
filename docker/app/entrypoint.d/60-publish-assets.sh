#!/bin/sh
# Runs on every start of the web container, after serversideup's own Laravel automations
# (50-laravel-automations.sh: migrate, storage:link, config/route/view/event cache).
#
# Several packages ship their JS/CSS in vendor/ and expect `php artisan ...:assets` /
# `vendor:publish` to copy it into public/. Those copies are not committed to git, and the
# files baked into the image under public/ would be stale after a package update, so we
# refresh them here. Nothing in this file is fatal: a failure is logged and the app still starts.
#
# Only the web container does this (the one started with AUTORUN_ENABLED=true).

if [ "${AUTORUN_ENABLED:-false}" != "true" ]; then
    exit 0
fi

cd "${APP_BASE_DIR:-/var/www/html}" || exit 0

run() {
    echo "Publishing assets: $*"
    if ! "$@"; then
        echo "WARNING: '$*' failed; continuing without it" >&2
    fi
}

# Filament panels + plugins (tables, forms, tiptap editor, charts, ...)
run php artisan filament:assets

# Chatify messaging assets (only if the package is installed)
if php artisan list --raw 2>/dev/null | grep -q '^chatify:install'; then
    run php artisan vendor:publish --tag=chatify-assets --force
fi

exit 0
