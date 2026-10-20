#!/bin/sh
# Build-time patch for the upstream crazymax/flarum installer.
#
# Upstream hard-codes the first administrator as flarum / flarum, which would be
# publicly guessable for the minutes between "container started" and "owner
# changed the password". We read the admin account from the environment instead.
# The build fails loudly if upstream changes its script and the patch no longer applies.
set -eu

f=/etc/cont-init.d/03-config.sh

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
