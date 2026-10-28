#!/usr/bin/env bash
# Move the site's data out of the Docker volumes into plain folders in this directory, so it can be read and backed up
# from the server itself:
#
#   data/app-storage     uploads, design files, snapshots (storage/app)
#   data/app-logs        the site's logs
#   data/flarum-storage  forum caches, logs, sessions
#   data/flarum-assets   forum avatars, uploaded files, compiled JS/CSS
#   data/mariadb-data    the database files (use the backups in docs/DEPLOY.md to copy the data elsewhere)
#   data/redis-data      cache and queue
#   data/caddy-data      HTTPS certificates
#   data/caddy-config    Caddy's own settings
#
# It stops the stack, copies every volume (owners and permissions kept, so the containers can still write), adds the
# VOL_* settings to .env that point compose.yaml at the folders, and starts the stack again. The old volumes are kept
# until you remove them yourself (the command is printed at the end). Run it once, as root, in the project folder:
#
#   sudo docker/data/move-to-host.sh            # into ./data
#   sudo docker/data/move-to-host.sh /srv/sdlabs-data
#
# Files belong to the containers' users (www-data, mysql), so read them with sudo, e.g. `sudo ls data/app-storage`.
#
# Environment:
#   ENV_FILE  the settings file to change, default .env
#   COMPOSE   the compose command, default "docker compose"

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
  BOLD=$'\033[1m'; GREEN=$'\033[32m'; RED=$'\033[31m'; RESET=$'\033[0m'
else
  BOLD=""; GREEN=""; RED=""; RESET=""
fi

die() { printf '%s\n' "${RED}error${RESET} $*" >&2; exit 1; }
info() { printf '%s\n' "$*"; }
ok() { printf '%s\n' "${GREEN}ok${RESET}  $*"; }

# compose: run the compose command (COMPOSE may hold several words on purpose).
compose() {
  # shellcheck disable=SC2086
  $COMPOSE "$@"
}

# The compose volume names and the .env setting each one is switched by.
NAMES=(app-storage app-logs flarum-storage flarum-assets mariadb-data redis-data caddy-data caddy-config)
setting_for() { local n="${1^^}"; printf 'VOL_%s' "${n//-/_}"; }

command -v docker >/dev/null 2>&1 || die "docker is not installed (or not in PATH)."
[ -f "$ENV_FILE" ] || die "$ENV_FILE not found. Run this script in the project folder."
[ -w "$ENV_FILE" ] || die "$ENV_FILE is not writable: run it with sudo."

if grep -qE '^VOL_[A-Z_]+=.+' "$ENV_FILE"; then
  die "$ENV_FILE already has VOL_* settings: the data was moved before. Nothing was changed."
fi

TARGET="${1:-$ROOT/data}"
mkdir -p "$TARGET"
TARGET="$(cd "$TARGET" && pwd)"
# Inside the project folder the settings use a relative path, so the folder can be moved together with the project.
REL="$TARGET"
if [[ "$TARGET" == "$ROOT"/* ]]; then
  REL="./${TARGET#"$ROOT"/}"
fi

PROJECT="$(compose config 2>/dev/null | sed -n 's/^name: *//p' | head -n 1)"
[ -n "$PROJECT" ] || die "could not read the compose project name (does 'docker compose config' work?)."

for name in "${NAMES[@]}"; do
  docker volume inspect "${PROJECT}_${name}" >/dev/null 2>&1 \
    || die "volume ${PROJECT}_${name} not found. Start the site once (docker compose up -d) before moving its data."
  if [ -d "$TARGET/$name" ] && [ -n "$(ls -A "$TARGET/$name" 2>/dev/null)" ]; then
    die "$TARGET/$name is not empty. Nothing was changed."
  fi
done

info
info "${BOLD}Moving the data of ${PROJECT} to ${TARGET}${RESET}"
info "The site is offline while the volumes are copied (a few seconds to a few minutes, depending on uploads)."
info

info "${BOLD}1/3${RESET} stopping the stack..."
compose stop >/dev/null

info "${BOLD}2/3${RESET} copying the volumes..."
for name in "${NAMES[@]}"; do
  mkdir -p "$TARGET/$name"
  # cp -a in a throwaway container keeps owners, permissions and timestamps exactly as the containers expect them.
  docker run --rm -v "${PROJECT}_${name}:/from:ro" -v "$TARGET/$name:/to" alpine:3.22 sh -c 'cp -a /from/. /to/' \
    || die "copying ${PROJECT}_${name} failed. The stack is stopped and .env is unchanged: fix the problem and run again, or start it with 'docker compose up -d'."
  ok "$name ($(du -sh "$TARGET/$name" | cut -f1))"
done

{
  printf '\n# Site data in host folders instead of Docker volumes (docker/data/move-to-host.sh, %s).\n' "$(date +%F)"
  for name in "${NAMES[@]}"; do
    printf '%s=%s/%s\n' "$(setting_for "$name")" "$REL" "$name"
  done
} >> "$ENV_FILE"
ok "added the VOL_* settings to $ENV_FILE"

info "${BOLD}3/3${RESET} starting the stack on the new folders..."
compose up -d >/dev/null
ok "started"

info
info "Done. The data is now in ${TARGET}. Check the site, then remove the old volumes when you are happy:"
printf '  docker volume rm'
for name in "${NAMES[@]}"; do printf ' %s_%s' "$PROJECT" "$name"; done
printf '\n'
