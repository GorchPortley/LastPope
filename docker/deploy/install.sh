#!/usr/bin/env bash
#
# Installs (or removes) the automatic updater as a systemd timer.
#
#   sudo docker/deploy/install.sh                 install; runs as root (you use sudo for docker anyway)
#   sudo docker/deploy/install.sh --user alice    run as the user "alice" instead (must be in the docker group)
#   sudo docker/deploy/install.sh --uninstall     stop and remove the timer (your settings and backups stay)
#   sudo docker/deploy/install.sh --branch dev    deploy this branch (stored as DEPLOY_BRANCH in deploy.env)
#
# One updater per folder: production and a dev copy on the same server each run their own. The units are named after
# COMPOSE_PROJECT_NAME in the folder's .env (lastpope -> lastpope-deploy, lastpope-dev -> lastpope-dev-deploy).
#
# What it creates (NAME = COMPOSE_PROJECT_NAME, default lastpope):
#   /etc/systemd/system/NAME-deploy.service   one run of docker/deploy/auto-deploy.sh
#   /etc/systemd/system/NAME-deploy.timer     starts the service 2 minutes after boot and every 2 minutes
#   /etc/logrotate.d/NAME-deploy              keeps /var/log/NAME-deploy.log from growing forever
#   docker/deploy/deploy.env                  your settings, copied from deploy.env.example (only if missing)
#
# Safe to run again (it just rewrites the files). Full guide: docs/DEPLOY.md, "Automatic updates".

set -euo pipefail

REPO="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../.." && pwd)"
SYSTEMD_DIR="${SYSTEMD_DIR:-/etc/systemd/system}"
LOGROTATE_DIR="${LOGROTATE_DIR:-/etc/logrotate.d}"
RUN_USER=""
UNINSTALL=0
BRANCH_SETTING=""

usage() {
  sed -n '3,8p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

while [ $# -gt 0 ]; do
  case "$1" in
    --user) RUN_USER="${2:-}"; [ -n "$RUN_USER" ] || { echo "--user needs a name" >&2; exit 2; }; shift 2 ;;
    --uninstall) UNINSTALL=1; shift ;;
    --branch) BRANCH_SETTING="${2:-}"; [ -n "$BRANCH_SETTING" ] || { echo "--branch needs a name" >&2; exit 2; }; shift 2 ;;
    -h | --help) usage; exit 0 ;;
    *) echo "Unknown option: $1" >&2; usage >&2; exit 2 ;;
  esac
done

ok() { printf '  ok   %s\n' "$*"; }
warn() { printf '  WARN %s\n' "$*" >&2; }
die() { printf 'error: %s\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "run this with sudo:  sudo $0 $*"

# The compose project of this folder names the units, so every folder (production, dev) gets its own updater.
PROJECT="$(sed -n -E 's/^COMPOSE_PROJECT_NAME=["'"'"']?([A-Za-z0-9_-]+).*/\1/p' "$REPO/.env" 2>/dev/null | tail -n 1)"
PROJECT="${PROJECT:-lastpope}"
UNIT="${PROJECT}-deploy"
LOG_FILE="/var/log/${UNIT}.log"
SERVICE="${UNIT}.service"
TIMER="${UNIT}.timer"

# systemd really running? (false in containers and WSL1: files are still written, nothing is enabled)
HAVE_SYSTEMD=0
if command -v systemctl >/dev/null 2>&1 && [ -d /run/systemd/system ]; then HAVE_SYSTEMD=1; fi

sysctl_do() {
  if [ "$HAVE_SYSTEMD" = 1 ]; then systemctl "$@"; else warn "systemd is not running here; skipped: systemctl $*"; fi
}

# ---------------------------------------------------------------------------------------------
if [ "$UNINSTALL" = 1 ]; then
  echo "Removing the automatic updater ..."
  if [ "$HAVE_SYSTEMD" = 1 ]; then
    systemctl disable --now "$TIMER" 2>/dev/null || true
    systemctl stop "$SERVICE" 2>/dev/null || true
  fi
  rm -f "$SYSTEMD_DIR/$SERVICE" "$SYSTEMD_DIR/$TIMER" "$LOGROTATE_DIR/$UNIT"
  sysctl_do daemon-reload
  ok "timer and service removed"
  echo
  echo "Left in place on purpose: docker/deploy/deploy.env, the backups folder, $LOG_FILE."
  echo "The site keeps running; it just no longer updates itself. Update by hand with: git pull && docker compose up -d --build"
  exit 0
fi

# ---------------------------------------------------------------------------------------------
echo "Installing the automatic updater for $REPO (project $PROJECT, units $UNIT.*) ..."

# Two folders with the same project name would replace each other's containers and share their data.
OTHER="$(docker ps -a --filter "label=com.docker.compose.project=$PROJECT" --format '{{.Label "com.docker.compose.project.working_dir"}}' 2>/dev/null | sort -u | grep -vxF "$REPO" | head -n 1 || true)"
[ -z "$OTHER" ] || die "project '$PROJECT' is already used by $OTHER. Give this folder its own COMPOSE_PROJECT_NAME in .env (e.g. lastpope-dev) and its own CADDY_HTTP_BIND port first (docs/DEPLOY.md, section 20)."

case "$REPO" in
  *[[:space:]]* | *\"* | *\'* | *\\* | *%*) die "the folder name ($REPO) must not contain spaces or quotes; move the project to a plain path such as /SDLabs" ;;
esac
[ -f "$REPO/compose.yaml" ] || die "$REPO/compose.yaml not found: run this from a full checkout of the project"
[ -x "$REPO/docker/deploy/auto-deploy.sh" ] || chmod +x "$REPO/docker/deploy/auto-deploy.sh"

RUN_HOME=/root
if [ -n "$RUN_USER" ]; then
  id "$RUN_USER" >/dev/null 2>&1 || die "user '$RUN_USER' does not exist"
  RUN_HOME="$(getent passwd "$RUN_USER" | cut -d: -f6)"
  if ! id -nG "$RUN_USER" | tr ' ' '\n' | grep -qx docker; then
    warn "$RUN_USER is not in the docker group, so the updater could not run docker. Fix: sudo usermod -aG docker $RUN_USER"
  fi
  if [ "$(stat -c %U "$REPO")" != "$RUN_USER" ]; then
    warn "$REPO is owned by $(stat -c %U "$REPO"), not $RUN_USER: git pull will fail. Fix: sudo chown -R $RUN_USER: $REPO"
  fi
fi

# --- checks that explain themselves later -------------------------------------------------------
command -v git >/dev/null 2>&1 || die "git is not installed"
command -v flock >/dev/null 2>&1 || die "flock is missing (package util-linux)"
command -v docker >/dev/null 2>&1 || die "docker is not installed (docs/DEPLOY.md, section 4)"
docker compose version >/dev/null 2>&1 || die "the 'docker compose' plugin is missing (docs/DEPLOY.md, section 4)"
git -c "safe.directory=$REPO" -C "$REPO" remote get-url origin >/dev/null 2>&1 ||
  die "this checkout has no 'origin' remote; clone the project with git clone so there is something to pull from"
[ -f "$REPO/.env" ] || warn "there is no .env yet: run docker/setup.sh and start the stack first (docker compose up -d --build)"
if ! command -v python3 >/dev/null 2>&1 && ! command -v jq >/dev/null 2>&1 && ! command -v php >/dev/null 2>&1; then
  warn "none of python3, jq, php found: the CI check cannot read GitHub's answer. Fix: sudo apt install python3  (or set REQUIRE_CI=false)"
fi
BRANCH="$(git -c "safe.directory=$REPO" -C "$REPO" symbolic-ref --short -q HEAD || true)"
[ -n "$BRANCH" ] || warn "the checkout is on a fixed commit (detached HEAD): the updater stays paused until you 'git checkout <branch>'"

# git run by root refuses a checkout owned by somebody else; allow it for manual git commands too.
if [ -z "$RUN_USER" ] && [ "$(stat -c %u "$REPO")" != 0 ]; then
  if ! git config --global --get-all safe.directory | grep -qxF "$REPO"; then
    git config --global --add safe.directory "$REPO"
    ok "git config --global --add safe.directory $REPO (root may use this checkout)"
  fi
fi

# --- settings file ------------------------------------------------------------------------------
if [ ! -f "$REPO/docker/deploy/deploy.env" ]; then
  cp "$REPO/docker/deploy/deploy.env.example" "$REPO/docker/deploy/deploy.env"
  ok "created docker/deploy/deploy.env (all settings optional; edit it to change the defaults)"
else
  ok "kept your existing docker/deploy/deploy.env"
fi
if [ -n "$BRANCH_SETTING" ]; then
  if grep -qE '^#?DEPLOY_BRANCH=' "$REPO/docker/deploy/deploy.env"; then
    sed -i -E "s|^#?DEPLOY_BRANCH=.*|DEPLOY_BRANCH=$BRANCH_SETTING|" "$REPO/docker/deploy/deploy.env"
  else
    printf '\nDEPLOY_BRANCH=%s\n' "$BRANCH_SETTING" >>"$REPO/docker/deploy/deploy.env"
  fi
  ok "deploys branch $BRANCH_SETTING (DEPLOY_BRANCH in docker/deploy/deploy.env)"
  [ "$BRANCH" = "$BRANCH_SETTING" ] || warn "the checkout is on '${BRANCH:-a fixed commit}': run 'git checkout $BRANCH_SETTING' here, or the updater stays paused"
fi
chmod 600 "$REPO/docker/deploy/deploy.env"
[ -z "$RUN_USER" ] || chown "$RUN_USER" "$REPO/docker/deploy/deploy.env"

# --- log file + rotation --------------------------------------------------------------------------
touch "$LOG_FILE" 2>/dev/null || warn "cannot create $LOG_FILE (the updater will log to the journal only)"
chmod 640 "$LOG_FILE" 2>/dev/null || true
[ -z "$RUN_USER" ] || chown "$RUN_USER" "$LOG_FILE" 2>/dev/null || true
if [ -d "$LOGROTATE_DIR" ]; then
  cat >"$LOGROTATE_DIR/$UNIT" <<EOF
$LOG_FILE {
    weekly
    rotate 8
    compress
    missingok
    notifempty
    copytruncate
}
EOF
  ok "log rotation: $LOGROTATE_DIR/$UNIT"
fi

# --- systemd units ------------------------------------------------------------------------------
mkdir -p "$SYSTEMD_DIR"
{
  cat <<EOF
[Unit]
Description=LastPope automatic deploy of $REPO (checks GitHub, updates the Docker stack)
After=network-online.target docker.service
Wants=network-online.target

[Service]
Type=oneshot
WorkingDirectory=$REPO
ExecStart=$REPO/docker/deploy/auto-deploy.sh
Environment=HOME=$RUN_HOME
Environment=DEPLOY_LOG=$LOG_FILE
# A build can take several minutes; give it room but never hang forever.
TimeoutStartSec=2h
# Be nice to the website while it builds.
Nice=10
IOSchedulingClass=best-effort
IOSchedulingPriority=7
EOF
  [ -z "$RUN_USER" ] || echo "User=$RUN_USER"
} >"$SYSTEMD_DIR/$SERVICE"

cat >"$SYSTEMD_DIR/$TIMER" <<EOF
[Unit]
Description=Check GitHub for a new LastPope version every 2 minutes

[Timer]
OnBootSec=2min
OnUnitActiveSec=2min
Persistent=true
AccuracySec=10s
Unit=$SERVICE

[Install]
WantedBy=timers.target
EOF
ok "wrote $SYSTEMD_DIR/$SERVICE and $SYSTEMD_DIR/$TIMER"

sysctl_do daemon-reload
sysctl_do enable --now "$TIMER"
[ "$HAVE_SYSTEMD" = 1 ] && ok "timer enabled and started"

cat <<EOF

Done. The server now checks GitHub every 2 minutes${RUN_USER:+ (running as $RUN_USER)}.
Following branch: ${BRANCH_SETTING:-${BRANCH:-"(none: detached HEAD)"}}

  See what it would do right now (changes nothing):  sudo $REPO/docker/deploy/auto-deploy.sh --dry-run
  Watch it live:                                     journalctl -u $UNIT -f
  History:                                           less $LOG_FILE
  Next / last run:                                   systemctl list-timers $TIMER
  Pause updates:                                     sudo systemctl stop $TIMER
  Resume:                                            sudo systemctl start $TIMER
  Remove completely:                                 sudo $REPO/docker/deploy/install.sh --uninstall

Settings (phone notifications, private repository token, ...): $REPO/docker/deploy/deploy.env
Rule: never edit tracked files on the server. Server-only changes go in .env or compose.override.yaml.
EOF
