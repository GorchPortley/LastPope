#!/bin/sh
# Build-time patches for the upstream crazymax/flarum installer.
# The build fails loudly if upstream changes its script and a patch no longer applies.
#
# 1. Upstream hard-codes the first administrator as flarum / flarum, which would be
#    publicly guessable for the minutes between "container started" and "owner
#    changed the password". We read the admin account from the environment instead.
#
# 2. Upstream re-computes its "baseline" list of bundled extensions on EVERY start from the
#    composer.json in the container. After a plain `docker compose restart` that file already
#    contains the extensions installed earlier, so they get folded into the baseline and are
#    dropped from /data/extensions/list; a later container re-creation (image update) then
#    loses them. We freeze the baseline at build time instead.
set -eu

# crazymax/flarum moved to s6-overlay v3 in a re-published 1.8.20 build (2026-10-21): the
# installer went from /etc/cont-init.d/03-config.sh to /etc/s6-overlay/scripts/config.
if [ -f /etc/s6-overlay/scripts/config ]; then
  f=/etc/s6-overlay/scripts/config
else
  f=/etc/cont-init.d/03-config.sh
fi
[ -f "$f" ] || { echo "patch-installer: upstream installer script not found" >&2; exit 1; }

sed -i \
  -e 's|^  username: flarum$|  username: ${FLARUM_ADMIN_USER}|' \
  -e 's|^  password: flarum$|  password: "${FLARUM_ADMIN_PASSWORD}"|' \
  -e 's|^  password_confirmation: flarum$|  password_confirmation: "${FLARUM_ADMIN_PASSWORD}"|' \
  -e 's|^  email: flarum@flarum.docker$|  email: ${FLARUM_ADMIN_EMAIL}|' \
  -e 's|^  echo ">> WARNING: Flarum has been installed with the default credentials (flarum/flarum)"$|  echo ">> Flarum installed. Admin user: ${FLARUM_ADMIN_USER}"|' \
  -e 's|^  echo ">> Please connect to ${FLARUM_BASE_URL} and change them!"$|  echo ">> Log in at ${FLARUM_BASE_URL} with the FLARUM_ADMIN_PASSWORD from .env"|' \
  "$f"

for needle in 'FLARUM_ADMIN_USER' 'FLARUM_ADMIN_PASSWORD' 'FLARUM_ADMIN_EMAIL'; do
  if ! grep -q "$needle" "$f"; then
    echo "patch-installer: could not patch $f (missing $needle); upstream installer changed" >&2
    exit 1
  fi
done

if grep -qE '^  (username|password|password_confirmation): flarum$' "$f"; then
  echo "patch-installer: default flarum/flarum credentials still present in $f" >&2
  exit 1
fi

# --- 2. freeze the baseline of bundled extensions ----------------------------------
baseline=/opt/flarum/.docker-flarum-base-extensions
sh /usr/local/bin/extension baseline
[ -s "$baseline" ] || { echo "patch-installer: baseline file is empty" >&2; exit 1; }

sed -i \
  -e "s~^gosu flarum:flarum sh /usr/local/bin/extension baseline\$~[ -s $baseline ] || gosu flarum:flarum sh /usr/local/bin/extension baseline~" \
  "$f"
grep -q "\[ -s $baseline \]" "$f" || {
  echo "patch-installer: could not patch the baseline step in $f; upstream installer changed" >&2
  exit 1
}
