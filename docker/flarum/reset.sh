#!/usr/bin/env bash
#
# Wipes the forum and starts it again from scratch: an empty, freshly installed Flarum 2.
#
#   docker/flarum/reset.sh
#
# What it deletes (after you typed the site's domain to confirm):
#   * the forum database (every discussion, post, member account, setting, forum admin user)
#   * the forum's volumes: flarum-assets (avatars, uploaded files, compiled JS/CSS) and flarum-storage
#     (caches, logs) - and flarum-data, the volume of the old Flarum 1.8 image, when it still exists
# What it keeps: the site database, uploads and everything else, and .env (so the forum admin password and
# the API key stay the same).
#
# Then it starts the forum again (the first start installs Flarum from the FLARUM_* settings in .env) and,
# when the app container is running, runs `php artisan forum:unlink --force` in it: the site forgets the
# forum discussions and accounts it had stored, which no longer exist. Finally it prints the commands that
# put content back (forum:setup, and forum:seed-demo on a demo site).
#
# Run it in the project folder on the server. It never touches the site's own database.
#
# Variables for testing (not needed normally):
#   COMPOSE   the compose command, default "docker compose"  (e.g. "docker compose -p test -f compose.yaml")
#   ENV_FILE  the settings file to read, default .env

set -euo pipefail

SELF="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$(basename "${BASH_SOURCE[0]}")"
ROOT="$(cd "$(dirname "$SELF")/../.." && pwd)"
cd "$ROOT"

ENV_FILE="${ENV_FILE:-.env}"
COMPOSE="${COMPOSE:-docker compose}"

if [ "${1:-}" = "-h" ] || [ "${1:-}" = "--help" ]; then
  sed -n '2,/^$/p' "$SELF" | sed '/^$/d; s/^# \{0,1\}//'
  exit 0
fi

if [ -t 1 ]; then
  BOLD=$'\033[1m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; RED=$'\033[31m'; RESET=$'\033[0m'
else
  BOLD=""; GREEN=""; YELLOW=""; RED=""; RESET=""
fi

die() { printf '%s\n' "${RED}error${RESET} $*" >&2; exit 1; }
info() { printf '%s\n' "$*"; }
ok() { printf '%s\n' "${GREEN}ok${RESET}  $*"; }
warn() { printf '%s\n' "${YELLOW}!!${RESET}  $*" >&2; }

# compose: run the compose command (COMPOSE may hold several words on purpose).
compose() {
  # shellcheck disable=SC2086
  $COMPOSE "$@"
}

# quiet COMMAND...: run a command, show its output only when it fails.
quiet() {
  local log
  if ! log="$("$@" 2>&1)"; then
    printf '%s\n' "$log" >&2
    return 1
  fi
}

# env_get KEY [DEFAULT]: last value of KEY in the settings file, surrounding quotes removed.
env_get() {
  local key="$1" default="${2:-}" line=""
  if [ -f "$ENV_FILE" ]; then
    line="$(grep -E "^${key}=" "$ENV_FILE" | tail -n 1 || true)"
    line="${line#*=}"
    line="${line%$'\r'}"
    if [[ "$line" =~ ^\"(.*)\"$ ]] || [[ "$line" =~ ^\'(.*)\'$ ]]; then
      line="${BASH_REMATCH[1]}"
    fi
  fi
  printf '%s' "${line:-$default}"
}

command -v docker >/dev/null 2>&1 || die "docker is not installed (or not in PATH)."
[ -f "$ENV_FILE" ] || die "$ENV_FILE not found. Run this script in the project folder, after docker/setup.sh."

DOMAIN="$(env_get APP_DOMAIN)"
ROOT_PASSWORD="$(env_get MARIADB_ROOT_PASSWORD)"
FORUM_DB="$(env_get FLARUM_DB_DATABASE flarum)"
FORUM_USER="$(env_get FLARUM_DB_USERNAME flarum)"
STACK_ENV="$(env_get STACK_ENV production)"

[ -n "$DOMAIN" ] || die "APP_DOMAIN is empty in $ENV_FILE."
[ -n "$ROOT_PASSWORD" ] || die "MARIADB_ROOT_PASSWORD is empty in $ENV_FILE."
[[ "$FORUM_DB" =~ ^[A-Za-z0-9_]{1,32}$ ]] || die "FLARUM_DB_DATABASE may only contain letters, digits and underscores."
[[ "$FORUM_USER" =~ ^[A-Za-z0-9_]{1,32}$ ]] || die "FLARUM_DB_USERNAME may only contain letters, digits and underscores."

PROJECT="$(compose config 2>/dev/null | sed -n 's/^name: *//p' | head -n 1)"
[ -n "$PROJECT" ] || die "could not read the compose project name (does 'docker compose config' work?)."

VOLUMES=()
FOLDERS=()
for name in flarum-storage flarum-assets flarum-data; do
  # Moved to a host folder (docker/data/move-to-host.sh): empty that folder instead of removing a volume.
  key="VOL_${name^^}"; key="${key//-/_}"
  folder="$(env_get "$key")"
  if [ -n "$folder" ]; then
    [[ "$folder" = /* ]] || folder="$ROOT/${folder#./}"
    [ -d "$folder" ] && FOLDERS+=("$folder")
  elif docker volume inspect "${PROJECT}_${name}" >/dev/null 2>&1; then
    VOLUMES+=("${PROJECT}_${name}")
  fi
done

# --- confirmation -----------------------------------------------------------------------------------

cat <<EOF

${BOLD}This DELETES the whole forum of ${DOMAIN}${RESET}

  * the forum database "${FORUM_DB}": all discussions, posts, member accounts, settings
  * the forum volumes: ${VOLUMES[*]:-(none found)}${FOLDERS[*]:+
  * the contents of the forum folders: ${FOLDERS[*]}}
  * the site's stored links to forum discussions and accounts (php artisan forum:unlink)

The forum is then installed again, empty. The site itself is not touched. This cannot be undone: back up
first (docs/DEPLOY.md, "Backups and restore") if there is anything worth keeping.

EOF

read -r -p "Type the domain (${DOMAIN}) to confirm: " answer || answer=""
if [ "$answer" != "$DOMAIN" ]; then
  die "that is not '${DOMAIN}'. Nothing was changed."
fi

# --- 1. stop the forum and remove its containers and volumes ------------------------------------------------

info
info "${BOLD}1/4${RESET} stopping the forum..."
compose stop flarum flarum-scheduler >/dev/null 2>&1 || true
compose rm -f flarum flarum-scheduler >/dev/null 2>&1 || true

for volume in "${VOLUMES[@]}"; do
  docker volume rm "$volume" >/dev/null || die "could not remove volume $volume (is another container still using it?)."
  ok "removed volume $volume"
done

# The folders themselves stay (with their owner, so the forum can write into them again); only their contents go.
for folder in "${FOLDERS[@]}"; do
  docker run --rm -v "$folder:/d" alpine:3.22 find /d -mindepth 1 -delete || die "could not empty $folder."
  ok "emptied $folder"
done

# --- 2. drop and recreate the forum database ---------------------------------------------------------------

info "${BOLD}2/4${RESET} recreating the database ${FORUM_DB}..."
quiet compose up -d --wait mariadb || die "the mariadb container did not become healthy."

# The password goes through the environment of this command only, never onto a command line.
MYSQL_PWD="$ROOT_PASSWORD" compose exec -T -e MYSQL_PWD mariadb mariadb -uroot <<SQL || die "could not recreate the database (wrong MARIADB_ROOT_PASSWORD?)."
DROP DATABASE IF EXISTS \`${FORUM_DB}\`;
CREATE DATABASE \`${FORUM_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON \`${FORUM_DB}\`.* TO '${FORUM_USER}'@'%';
FLUSH PRIVILEGES;
SQL
ok "database ${FORUM_DB} is empty"

# --- 3. start the forum again ---------------------------------------------------------------------------------

info "${BOLD}3/4${RESET} starting the forum (first start installs Flarum, about a minute)..."
quiet compose up -d --wait --wait-timeout 600 flarum flarum-scheduler \
  || die "the forum did not become healthy. See: docker compose logs flarum"
ok "the forum is up"

# --- 4. the site forgets the old forum links ---------------------------------------------------------------------

UNLINKED=0
info "${BOLD}4/4${RESET} telling the site that the forum is empty..."
if [ -n "$(compose ps -q --status running app 2>/dev/null)" ] \
  && compose exec -T app php artisan forum:unlink --force >/dev/null 2>&1; then
  UNLINKED=1
  ok "forum:unlink done"
else
  warn "could not run forum:unlink in the app container (not running?). Run it yourself, see below."
fi

# --- next steps ------------------------------------------------------------------------------------------------------

echo
echo "${BOLD}Done. The forum is empty and freshly installed.${RESET} Next, put content back:"
echo
if [ "$UNLINKED" = 0 ]; then
  echo "  docker compose exec app php artisan forum:unlink --force"
fi
echo "  docker compose exec app php artisan forum:setup"
if [ "$STACK_ENV" = "demo" ]; then
  echo "  docker compose exec app php artisan forum:seed-demo     # demo content: a thread plus replies for every design, driver and listing"
else
  echo "  docker compose exec app php artisan forum:setup --backfill   # a discussion for every published design, driver and listing"
fi
echo
echo "Forum admin login: $(env_get FLARUM_ADMIN_USER forumadmin) / FLARUM_ADMIN_PASSWORD from $ENV_FILE, at https://${DOMAIN}/forum"
