#!/bin/sh
# Runs on every start of the web container, after serversideup's own Laravel automations
# (50-laravel-automations.sh: storage:link, migrate, config/route/view/event cache).
#
# Filament and its plugins (tiptap editor, Google Analytics widget, ...) ship their JS/CSS inside
# vendor/ and copy it to public/js|css/... with `php artisan filament:assets`. Those copies are not
# in git, and the Vite build does not produce them, so they are published here. It needs the
# booted application (Wave reads the active theme from the database), which is why this is a
# start-up step and not part of the image build.
#
# Not needed:
#   * Livewire serves its own JavaScript through a route (/livewire/livewire.js).
#   * The Chatify assets are committed to the repository (public/css|js/chatify).
#   * The Vite build (public/build) happens in the image build.
#
# Nothing in this file is fatal: a failure is logged and the app still starts.
#
# Only the web container does this (the one started with AUTORUN_ENABLED=true).

if [ "${AUTORUN_ENABLED:-false}" != "true" ]; then
    exit 0
fi

cd "${APP_BASE_DIR:-/var/www/html}" || exit 0

echo "Publishing Filament assets: php artisan filament:assets"
if ! php artisan filament:assets; then
    echo "WARNING: 'php artisan filament:assets' failed; the admin panel may be missing styles" >&2
fi

exit 0
