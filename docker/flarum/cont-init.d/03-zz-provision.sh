#!/usr/bin/with-contenv bash
# shellcheck shell=bash
#
# Runs after upstream's 03-config.sh (Flarum is installed and migrated at this point).
# Everything here is idempotent and driven by environment variables:
#
#   FLARUM_API_KEY      master API key; inserted into <prefix>api_keys if missing, so the
#                       Laravel app can call the API as "Authorization: Token <key>; userId=1"
#   FLARUM_AUTO_ENABLE  extension ids (e.g. flarum-tags) switched on once, the first time
#                       they are seen. Later changes in the admin panel are never overridden.
#   FLARUM_MAIL_*       copied into Flarum's mail settings once, on the first start only.
#
# Nothing here is fatal: a failure is logged and the forum still starts.

set -u

if [ "${SIDECAR_CRON:-0}" = "1" ]; then
  exit 0
fi

state_dir=/data/.provision
mkdir -p "$state_dir"

# Same *_FILE convention as upstream (Docker secrets).
for v in DB_USER DB_PASSWORD; do
  f="${v}_FILE"
  if [ -z "${!v:-}" ] && [ -n "${!f:-}" ] && [ -r "${!f}" ]; then
    export "$v=$(<"${!f}")"
  fi
done

prefix="${DB_PREFIX:-flarum_}"
if [ "${DB_NOPREFIX:-false}" = "true" ]; then
  prefix=""
fi

sql() {
  MYSQL_PWD="${DB_PASSWORD}" mariadb -h "${DB_HOST}" -P "${DB_PORT:-3306}" -u "${DB_USER}" \
    --batch --skip-column-names "${DB_NAME}" "$@"
}

# Escape a value for use inside a single-quoted SQL string.
q() {
  local s="${1//\\/\\\\}"
  printf '%s' "${s//\'/\\\'}"
}

if ! sql -e "SELECT 1 FROM \`${prefix}settings\` LIMIT 1" >/dev/null 2>&1; then
  echo "WARNING (provision): Flarum tables not found, skipping provisioning" >&2
  exit 0
fi

# --- 1. master API key -------------------------------------------------------
if [ -n "${FLARUM_API_KEY:-}" ]; then
  if [[ "${FLARUM_API_KEY}" =~ ^[A-Za-z0-9]{32,100}$ ]]; then
    key="$(q "$FLARUM_API_KEY")"
    if sql -e "INSERT INTO \`${prefix}api_keys\` (\`key\`, \`user_id\`, \`created_at\`)
               SELECT '${key}', NULL, NOW() FROM DUAL
               WHERE NOT EXISTS (SELECT 1 FROM \`${prefix}api_keys\` WHERE \`key\` = '${key}')"; then
      echo "Flarum API key is in place."
    else
      echo "WARNING (provision): could not store FLARUM_API_KEY" >&2
    fi
  else
    echo "WARNING (provision): FLARUM_API_KEY must be 32-100 letters/digits; not stored" >&2
  fi
else
  echo "FLARUM_API_KEY is empty: the Laravel app will not be able to use the forum API."
fi

changed=0

# --- 2. enable extensions once ----------------------------------------------
# Through Flarum's ExtensionManager (runs the extension's migrations, publishes assets).
# Fresh crazymax/flarum installs start with *no* extension enabled, not even Tags.
# Ids already handled are remembered, so an admin who disables one later is not overridden.
enabled_marker="$state_dir/enabled"
touch "$enabled_marker"
todo=()
for ext in ${FLARUM_AUTO_ENABLE//,/ }; do
  [[ "$ext" =~ ^[a-z0-9_.-]+$ ]] || continue
  grep -qxF "$ext" "$enabled_marker" || todo+=("$ext")
done

if [ "${#todo[@]}" -gt 0 ]; then
  while IFS= read -r line; do
    case "$line" in
      "enabled: "* | "already enabled: "*)
        echo "$line"
        echo "${line##*: }" >>"$enabled_marker"
        changed=1
        ;;
      *) echo "$line" ;;
    esac
  done < <(gosu flarum:flarum php /usr/local/share/lastpope/enable-extensions.php "${todo[@]}" 2>&1)
fi

# --- 3. mail settings, first start only -------------------------------------
mail_marker="$state_dir/mail"
if [ ! -e "$mail_marker" ] && [ "${FLARUM_MAIL_DRIVER:-}" = "smtp" ] && [ -n "${FLARUM_MAIL_HOST:-}" ]; then
  enc="${FLARUM_MAIL_ENCRYPTION:-}"
  case "$enc" in null | none | NULL) enc="" ;; esac
  upsert() {
    sql -e "INSERT INTO \`${prefix}settings\` (\`key\`, \`value\`) VALUES ('$1', '$(q "$2")')
            ON DUPLICATE KEY UPDATE \`value\` = VALUES(\`value\`)"
  }
  if upsert mail_driver smtp \
     && upsert mail_host "$FLARUM_MAIL_HOST" \
     && upsert mail_port "${FLARUM_MAIL_PORT:-587}" \
     && upsert mail_encryption "$enc" \
     && upsert mail_username "${FLARUM_MAIL_USERNAME:-}" \
     && upsert mail_password "${FLARUM_MAIL_PASSWORD:-}" \
     && upsert mail_from "${FLARUM_MAIL_FROM:-noreply@localhost}"; then
    touch "$mail_marker"
    echo "Copied SMTP settings from the app's MAIL_* variables into Flarum (first start only)."
    changed=1
  else
    echo "WARNING (provision): could not store mail settings" >&2
  fi
fi

# --- 4. refresh caches if anything changed ----------------------------------
if [ "$changed" = "1" ]; then
  gosu flarum:flarum php /opt/flarum/flarum cache:clear || true
fi

exit 0
