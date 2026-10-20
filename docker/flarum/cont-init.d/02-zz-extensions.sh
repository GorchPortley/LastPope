#!/usr/bin/with-contenv bash
# shellcheck shell=bash
#
# Runs before upstream's 03-config.sh. Adds the extensions we want (FLARUM_EXTENSIONS,
# space or comma separated "vendor/package[:constraint]") to /data/extensions/list.
# 03-config.sh then runs `composer require` for everything in that list on every start
# (cached in /data/extensions/.cache) and runs the Flarum migrations.
#
# Extensions added later through the Flarum admin "Extension Manager" or via
# `docker compose exec flarum extension require vendor/package` end up in the same list.

list=/data/extensions/list
mkdir -p /data/extensions
touch "$list"

wanted="${FLARUM_EXTENSIONS//,/ }"
for spec in $wanted; do
  pkg="${spec%%:*}"
  if [[ ! "$pkg" =~ ^[a-z0-9_.-]+/[a-z0-9_.-]+$ ]]; then
    echo "WARNING (extensions): ignoring invalid package name '$spec'" >&2
    continue
  fi
  if ! grep -qE "^${pkg}(:|\$)" "$list"; then
    echo "Adding extension ${spec} to ${list}"
    echo "$spec" >>"$list"
  fi
done
