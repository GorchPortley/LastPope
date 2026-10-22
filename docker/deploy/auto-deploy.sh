#!/usr/bin/env bash
#
# LastPope auto-deploy: look for new code on GitHub and, when there is some (and the tests are
# green), update the running Docker stack. Meant to be started every 2 minutes by a systemd timer
# (install it with `sudo docker/deploy/install.sh`), but you can also run it by hand:
#
#   sudo docker/deploy/auto-deploy.sh             check now and deploy if there is something new
#   sudo docker/deploy/auto-deploy.sh --dry-run   only report what would happen, change nothing
#   sudo docker/deploy/auto-deploy.sh --force     deploy even if the CI tests have not passed
#
# One run, in plain words:
#   1. git fetch. Nothing new on the branch?  Stop quietly (this is what happens 99% of the time).
#   2. Safety checks: the server checkout must be clean (no hand edits to tracked files) and must
#      not contain commits that GitHub does not have. Otherwise we refuse, log it and send ONE
#      notification per new commit.
#   3. CI gate: ask GitHub whether the tests workflow passed for that exact commit. Still running or
#      not started yet: wait (we simply look again on the next run). Failed: do not deploy.
#   4. Back up both databases (gzip'd SQL dump). If the backup fails, nothing is changed.
#   5. git merge --ff-only, add secrets the new code needs and .env lacks (docker/setup.sh --secrets-only),
#      docker compose build, docker compose up -d, migrations + seeding.
#   6. Health check. Unhealthy?  Put the old code back (git reset --hard <old>), rebuild, restart.
#   7. Tell you (optional NOTIFY_URL) and remove old dangling Docker images.
#
# Settings: docker/deploy/deploy.env (see deploy.env.example, all optional) or environment
# variables of the same name. Full guide: docs/DEPLOY.md, section "Automatic updates".
#
# Needs: bash, git, curl, flock, gzip, docker with the compose plugin, and (only for the CI gate)
# python3, or jq, or php to read GitHub's JSON answer. Ubuntu/Debian have all of that, except
# Docker itself.

set -euo pipefail

# Everything lives in main() and is called on the last line: bash then has read the whole script
# before running it, so a `git merge` that replaces this very file cannot confuse the running copy.

main() {
  local SCRIPT_PATH SCRIPT_DIR REPO
  SCRIPT_PATH="$(readlink -f "${BASH_SOURCE[0]}")"
  SCRIPT_DIR="$(dirname "$SCRIPT_PATH")"
  REPO="$(cd "$SCRIPT_DIR/../.." && pwd)"
  cd "$REPO"

  # systemd services do not always have HOME; git (ssh keys, credentials) and docker need it.
  if [ -z "${HOME:-}" ]; then
    HOME="$(getent passwd "$(id -u)" | cut -d: -f6)"
    export HOME
  fi
  # Never wait for a password/passphrase prompt that nobody can answer.
  export GIT_TERMINAL_PROMPT=0
  export GIT_SSH_COMMAND="${GIT_SSH_COMMAND:-ssh -o BatchMode=yes -o ConnectTimeout=20}"

  # Git refuses repositories owned by somebody else ("dubious ownership"); this run is allowed to
  # use this one. (Command-line `-c` counts as trusted config, so no global git config is needed.)
  git() { command git -c "safe.directory=$REPO" "$@"; }
  dc() { docker compose "$@"; }

  # ------------------------------------------------------------------------------------------
  # Options
  # ------------------------------------------------------------------------------------------
  local DRY_RUN=0 FORCE=0 VERBOSE=0
  while [ $# -gt 0 ]; do
    case "$1" in
      -n | --dry-run) DRY_RUN=1 ;;
      -f | --force) FORCE=1 ;;
      -v | --verbose) VERBOSE=1 ;;
      -h | --help) usage; return 0 ;;
      *) printf 'Unknown option: %s\n\n' "$1" >&2; usage >&2; return 2 ;;
    esac
    shift
  done

  # Chatty mode: a person is watching (terminal, --dry-run, --force, --verbose), so also say
  # "nothing to do". Under systemd (no terminal) the quiet runs stay silent.
  local CHATTY=0
  if [ -t 1 ] || [ "$DRY_RUN" = 1 ] || [ "$FORCE" = 1 ] || [ "$VERBOSE" = 1 ]; then CHATTY=1; fi

  # ------------------------------------------------------------------------------------------
  # Small helpers: key=value files, logging, state
  # ------------------------------------------------------------------------------------------

  # kv_get FILE KEY: last value of KEY= in FILE with surrounding quotes removed (prints nothing if
  # the file or key is missing). We read these files ourselves instead of `source`-ing them, so a
  # stray space or $ in a password can never run code.
  kv_get() {
    local file="$1" key="$2" line
    [ -f "$file" ] || return 0
    line="$(grep -E "^[[:space:]]*${key}=" "$file" | tail -n 1 || true)"
    line="${line#*=}"
    line="${line%$'\r'}"
    if [[ "$line" =~ ^\"(.*)\"$ ]] || [[ "$line" =~ ^\'(.*)\'$ ]]; then line="${BASH_REMATCH[1]}"; fi
    printf '%s' "$line"
  }

  is_true() {
    case "$(printf '%s' "${1:-}" | tr '[:upper:]' '[:lower:]')" in
      1 | true | yes | y | on) return 0 ;;
      *) return 1 ;;
    esac
  }

  local DEPLOY_LOG_OK=0
  log() {
    local line
    line="$(date '+%Y-%m-%d %H:%M:%S') $*"
    printf '%s\n' "$line"
    if [ "$DEPLOY_LOG_OK" = 1 ]; then printf '%s\n' "$line" >>"$DEPLOY_LOG" 2>/dev/null || true; fi
  }

  # log_block PREFIX: log every line read from stdin (used for command output).
  log_block() {
    local prefix="$1" l
    while IFS= read -r l; do log "$prefix$l"; done
  }

  # ------------------------------------------------------------------------------------------
  # Settings: environment variable > deploy.env > default
  # ------------------------------------------------------------------------------------------
  local DEPLOY_ENV_FILE="${DEPLOY_ENV_FILE:-$SCRIPT_DIR/deploy.env}"
  local key val
  # The GitHub token is read from deploy.env ONLY: an unrelated GITHUB_TOKEN lying around in the
  # environment (CI helpers, shell profiles) must never be sent to GitHub by accident.
  unset GITHUB_TOKEN
  for key in DEPLOY_BRANCH REQUIRE_CI GITHUB_REPO GITHUB_TOKEN CI_WORKFLOW CI_WAIT_MAX GITHUB_API_URL \
    NOTIFY_URL BACKUP_DIR BACKUP_KEEP HEALTH_URL HEALTH_TIMEOUT HEALTH_INTERVAL HEALTH_SERVICES \
    ROLLBACK_ON_FAILURE DEPLOY_LOG COMPOSER_AUTH; do
    if [ -z "${!key+x}" ]; then
      val="$(kv_get "$DEPLOY_ENV_FILE" "$key")"
      printf -v "$key" '%s' "$val"
    fi
  done

  local ENV_FILE="$REPO/.env"
  local CURRENT_BRANCH
  CURRENT_BRANCH="$(git symbolic-ref --short -q HEAD || true)"

  : "${DEPLOY_BRANCH:=$CURRENT_BRANCH}"
  : "${REQUIRE_CI:=true}"
  : "${CI_WORKFLOW:=tests.yml}"
  : "${CI_WAIT_MAX:=45}" # minutes without any CI run for the commit before we complain
  : "${GITHUB_API_URL:=https://api.github.com}"
  : "${BACKUP_DIR:=$REPO/backups}"
  : "${BACKUP_KEEP:=10}"
  : "${HEALTH_TIMEOUT:=180}"
  : "${HEALTH_INTERVAL:=5}"
  : "${HEALTH_SERVICES:=app flarum queue scheduler}"
  : "${ROLLBACK_ON_FAILURE:=true}"
  : "${DEPLOY_LOG:=/var/log/lastpope-deploy.log}"
  # Optional GitHub token for `composer install` inside the image build (compose.yaml passes it on
  # as a build secret). Only needed when GitHub's anonymous download limit stops a build.
  [ -z "${COMPOSER_AUTH:-}" ] || export COMPOSER_AUTH
  [[ "$BACKUP_DIR" = /* ]] || BACKUP_DIR="$REPO/$BACKUP_DIR"
  [[ "$BACKUP_KEEP" =~ ^[0-9]+$ ]] && [ "$BACKUP_KEEP" -ge 1 ] || BACKUP_KEEP=10
  [[ "$HEALTH_TIMEOUT" =~ ^[0-9]+$ ]] || HEALTH_TIMEOUT=180
  [[ "$HEALTH_INTERVAL" =~ ^[0-9]+$ ]] && [ "$HEALTH_INTERVAL" -ge 1 ] || HEALTH_INTERVAL=5
  [[ "$CI_WAIT_MAX" =~ ^[0-9]+$ ]] || CI_WAIT_MAX=45

  # Log file: only when we can write to it (and never for a dry run, which must leave no trace).
  if [ "$DRY_RUN" = 0 ] && [ -n "$DEPLOY_LOG" ] && { : >>"$DEPLOY_LOG"; } 2>/dev/null; then DEPLOY_LOG_OK=1; fi

  # ------------------------------------------------------------------------------------------
  # State directory (inside .git, so it is never part of the code and survives `git reset --hard`)
  # ------------------------------------------------------------------------------------------
  local GIT_DIR_ABS STATE_DIR
  GIT_DIR_ABS="$(git rev-parse --absolute-git-dir 2>/dev/null)" || {
    log "ERROR: $REPO is not a git checkout."
    return 1
  }
  STATE_DIR="${DEPLOY_STATE_DIR:-$GIT_DIR_ABS/lastpope-deploy}"
  mkdir -p "$STATE_DIR"
  local SEEN="$STATE_DIR/seen" RUN_LOG="$STATE_DIR/last-deploy.log"

  # Missing tools are a setup problem: say so clearly.
  local tool missing=""
  for tool in git curl flock gzip docker; do
    command -v "$tool" >/dev/null 2>&1 || missing="$missing $tool"
  done
  if [ -n "$missing" ]; then
    log "ERROR: missing required program(s):$missing"
    return 1
  fi

  # One run at a time. A second run (timer tick during a long build, or you running it by hand)
  # simply leaves again.
  exec 9>"$STATE_DIR/lock"
  if ! flock -n 9; then
    [ "$CHATTY" = 1 ] && log "Another deploy run is in progress; leaving."
    return 0
  fi

  # state_has LINE / state_add LINE / state_del LINE: tiny memory between runs ("I already told you
  # about this commit"). One line per fact in $SEEN.
  state_has() { grep -Fxq -- "$1" "$SEEN" 2>/dev/null; }
  state_add() {
    [ "$DRY_RUN" = 1 ] && return 0
    printf '%s\n' "$1" >>"$SEEN"
    if [ "$(wc -l <"$SEEN")" -gt 400 ]; then
      tail -n 200 "$SEEN" >"$SEEN.tmp" && mv "$SEEN.tmp" "$SEEN"
    fi
  }
  state_del() {
    [ "$DRY_RUN" = 1 ] && return 0
    [ -f "$SEEN" ] || return 0
    { grep -vxF -- "$1" "$SEEN" || true; } >"$SEEN.tmp"
    mv "$SEEN.tmp" "$SEEN"
  }
  # once KEY: true the first time it is asked (and remembers), false afterwards. A dry run never
  # remembers anything.
  once() {
    if state_has "$1"; then return 1; fi
    state_add "$1"
    return 0
  }

  # ------------------------------------------------------------------------------------------
  # Notifications (ntfy.sh topic URL, Discord webhook, or anything that accepts a POST)
  # ------------------------------------------------------------------------------------------
  json_escape() {
    local s="$1"
    s="${s//\\/\\\\}"
    s="${s//\"/\\\"}"
    s="${s//$'\n'/\\n}"
    s="${s//$'\r'/\\r}"
    s="${s//$'\t'/\\t}"
    printf '%s' "$s"
  }

  # notify LEVEL MESSAGE   (LEVEL: ok | warn | fail | crit; only matters for ntfy's icon/priority)
  notify() {
    local level="$1" msg="$2" tags prio
    msg="[$(hostname -s 2>/dev/null || echo server)] $msg"
    msg="${msg:0:1500}"
    if [ "$DRY_RUN" = 1 ]; then
      log "[dry-run] would notify ($level): $msg"
      return 0
    fi
    [ -n "${NOTIFY_URL:-}" ] || return 0
    # The URL is a secret for Discord; hand it to curl on stdin so it never shows up in `ps`.
    if [[ "$NOTIFY_URL" == *discord.com/api/webhooks* ]]; then
      printf 'url = "%s"\n' "$NOTIFY_URL" | curl -sS -K - --max-time 15 -o /dev/null -f \
        -H 'Content-Type: application/json' --data-binary "{\"content\": \"$(json_escape "$msg")\"}" ||
        log "WARNING: could not send the Discord notification."
    else
      case "$level" in
        ok) tags=white_check_mark prio=3 ;;
        warn) tags=warning prio=4 ;;
        fail) tags=x prio=4 ;;
        *) tags=rotating_light prio=5 ;;
      esac
      printf 'url = "%s"\n' "$NOTIFY_URL" | curl -sS -K - --max-time 15 -o /dev/null -f \
        -H 'Title: LastPope deploy' -H "Tags: $tags" -H "Priority: $prio" \
        --data-binary "$msg" || log "WARNING: could not send the notification to NOTIFY_URL."
    fi
    return 0
  }

  # say_once KEY LEVEL MESSAGE: log + notify the first time KEY is seen. A person at the terminal
  # always sees the message, but the notification still goes out only once.
  say_once() {
    local k="$1" level="$2" msg="$3"
    if once "$k"; then
      log "$msg"
      notify "$level" "$msg"
    elif [ "$CHATTY" = 1 ]; then
      log "$msg"
    fi
  }

  # ------------------------------------------------------------------------------------------
  # 1. Which branch, what is new?
  # ------------------------------------------------------------------------------------------
  if [ -z "$CURRENT_BRANCH" ]; then
    # Detached HEAD = somebody pinned a commit on purpose (see "deploy a specific commit").
    say_once "detached:$(git rev-parse HEAD)" warn \
      "The checkout is on a fixed commit (detached HEAD), so auto-deploy is paused. 'git checkout <branch>' resumes it."
    return 0
  fi
  if [ -z "$DEPLOY_BRANCH" ] || [ "$CURRENT_BRANCH" != "$DEPLOY_BRANCH" ]; then
    say_once "branch:$CURRENT_BRANCH:$DEPLOY_BRANCH" warn \
      "The checkout is on branch '$CURRENT_BRANCH' but DEPLOY_BRANCH is '$DEPLOY_BRANCH'. Run 'git checkout $DEPLOY_BRANCH' once (or change DEPLOY_BRANCH). Auto-deploy is paused."
    return 0
  fi
  if ! git remote get-url origin >/dev/null 2>&1; then
    log "ERROR: this checkout has no 'origin' remote."
    return 1
  fi

  # A previous run that died in the middle of a deploy (power cut, reboot, killed build) leaves
  # this marker. The code may already be the new version while the containers are still the old
  # ones, so put the old code back; the normal flow below then simply tries again.
  if [ -f "$STATE_DIR/in-progress" ]; then
    local ip_old ip_new
    read -r ip_old ip_new <"$STATE_DIR/in-progress" || true
    log "A previous deploy was interrupted (${ip_old:0:7} -> ${ip_new:0:7}). Restoring the old code before trying again."
    if [ "$DRY_RUN" = 0 ]; then
      if [ -n "$ip_old" ] && git cat-file -e "${ip_old}^{commit}" 2>/dev/null; then
        git reset --hard "$ip_old" >/dev/null 2>&1 || log "WARNING: could not reset to ${ip_old:0:7}."
      fi
      rm -f "$STATE_DIR/in-progress"
      state_add "interrupted:$ip_new"
      if [ "$(grep -Fxc "interrupted:$ip_new" "$SEEN" || true)" -ge 2 ]; then
        state_add "failed:$ip_new"
        notify fail "Deploying ${ip_new:0:7} was interrupted twice. Skipping it; fix it or run docker/deploy/auto-deploy.sh --force."
      fi
    fi
  fi

  local LOCAL_SHA REMOTE_SHA
  # (`timeout` cannot call our git() function, hence the explicit -c.)
  if ! timeout 120 git -c "safe.directory=$REPO" fetch --quiet --no-tags origin "+refs/heads/$DEPLOY_BRANCH:refs/remotes/origin/$DEPLOY_BRANCH" 2>"$STATE_DIR/fetch.err"; then
    # Offline, GitHub down, wrong credentials... Say it once, then stay quiet until it works again.
    say_once "fetch-failed" warn "git fetch failed (will keep trying): $(tr '\n' ' ' <"$STATE_DIR/fetch.err" | cut -c1-300)"
    return 0
  fi
  state_del "fetch-failed"
  LOCAL_SHA="$(git rev-parse HEAD)"
  REMOTE_SHA="$(git rev-parse "refs/remotes/origin/$DEPLOY_BRANCH")"

  if [ "$LOCAL_SHA" = "$REMOTE_SHA" ]; then
    [ "$CHATTY" = 1 ] && log "Up to date at $(git rev-parse --short=7 HEAD) ($DEPLOY_BRANCH); nothing to deploy."
    return 0
  fi

  local LOCAL_SHORT REMOTE_SHORT SUBJECT
  LOCAL_SHORT="$(git rev-parse --short=7 "$LOCAL_SHA")"
  REMOTE_SHORT="$(git rev-parse --short=7 "$REMOTE_SHA")"
  SUBJECT="$(git log -1 --format=%s "$REMOTE_SHA")"

  # ------------------------------------------------------------------------------------------
  # 2. Safety checks on the server's checkout
  # ------------------------------------------------------------------------------------------
  local dirty
  dirty="$(git status --porcelain --untracked-files=no | head -n 8 | tr '\n' ';' || true)"
  if [ -n "$dirty" ]; then
    say_once "dirty:$REMOTE_SHA" warn \
      "NOT deploying $REMOTE_SHORT: tracked files were changed by hand on the server ($dirty). Undo with 'git -C $REPO checkout -- .' (or 'git stash'). Server-only settings belong in .env or compose.override.yaml, never in tracked files."
    return 0
  fi
  if ! git merge-base --is-ancestor "$LOCAL_SHA" "$REMOTE_SHA"; then
    say_once "diverged:$LOCAL_SHA:$REMOTE_SHA" warn \
      "NOT deploying $REMOTE_SHORT: the server has commits that are not on origin/$DEPLOY_BRANCH (or the branch was rewritten). Server-only changes belong in .env or compose.override.yaml. To discard them: git -C $REPO reset --hard origin/$DEPLOY_BRANCH"
    return 0
  fi

  if [ "$FORCE" = 0 ] && state_has "failed:$REMOTE_SHA"; then
    [ "$CHATTY" = 1 ] && log "Skipping $REMOTE_SHORT: its earlier deploy failed. Push a fix, or retry with --force."
    return 0
  fi

  # ------------------------------------------------------------------------------------------
  # 3. CI gate: did the tests pass for exactly this commit?
  # ------------------------------------------------------------------------------------------

  # ci_parse FILE: print "none" or "STATUS CONCLUSION URL" for the newest run in GitHub's answer.
  ci_parse() {
    local file="$1"
    if command -v python3 >/dev/null 2>&1; then
      python3 -I -c '
import json, sys
runs = (json.load(open(sys.argv[1])).get("workflow_runs") or [])
if not runs:
    print("none")
else:
    r = runs[0]
    print(r.get("status") or "-", r.get("conclusion") or "-", r.get("html_url") or "-")
' "$file"
    elif command -v jq >/dev/null 2>&1; then
      jq -r 'if (.workflow_runs // []) | length == 0 then "none" else .workflow_runs[0] | "\(.status // "-") \(.conclusion // "-") \(.html_url // "-")" end' "$file"
    elif command -v php >/dev/null 2>&1; then
      # shellcheck disable=SC2016 # the $variables below are PHP's, not the shell's
      php -r '
$d = json_decode(file_get_contents($argv[1]), true);
$runs = is_array($d) ? ($d["workflow_runs"] ?? []) : [];
if (!$runs) { echo "none\n"; exit; }
$r = $runs[0];
echo ($r["status"] ?? "-"), " ", ($r["conclusion"] ?? "-"), " ", ($r["html_url"] ?? "-"), "\n";
' "$file"
    else
      return 1
    fi
  }

  # ci_check SHA: sets CI_STATE to success | pending | none | failed | error (and CI_INFO).
  local CI_STATE="" CI_INFO=""
  ci_check() {
    local sha="$1" url body http parsed status conclusion link
    url="$GITHUB_API_URL/repos/$GITHUB_REPO/actions/workflows/$CI_WORKFLOW/runs?head_sha=$sha&per_page=1"
    body="$(mktemp)"
    # The token goes to curl on stdin (-K -) so it never appears in the process list.
    http="$({
      [ -n "${GITHUB_TOKEN:-}" ] && printf 'header = "Authorization: Bearer %s"\n' "$GITHUB_TOKEN"
      true
    } | curl -sS -K - --max-time 20 -o "$body" -w '%{http_code}' \
      -H 'Accept: application/vnd.github+json' -H 'X-GitHub-Api-Version: 2022-11-28' \
      -H 'User-Agent: lastpope-auto-deploy' "$url" 2>/dev/null)" || http=000
    if [ "$http" != 200 ]; then
      CI_STATE=error
      case "$http" in
        000) CI_INFO="could not reach $GITHUB_API_URL" ;;
        401) CI_INFO="GitHub rejected the token (HTTP 401): check GITHUB_TOKEN" ;;
        403) CI_INFO="GitHub refused the request (HTTP 403): rate limit, or the token lacks 'Actions: read'" ;;
        404) CI_INFO="GitHub says not found (HTTP 404): wrong GITHUB_REPO/CI_WORKFLOW, or a private repository without GITHUB_TOKEN" ;;
        *) CI_INFO="GitHub answered HTTP $http" ;;
      esac
      rm -f "$body"
      return 0
    fi
    if ! parsed="$(ci_parse "$body")"; then
      CI_STATE=error
      CI_INFO="cannot read GitHub's answer: install python3 (or jq, or php)"
      rm -f "$body"
      return 0
    fi
    rm -f "$body"
    if [ "$parsed" = none ]; then
      CI_STATE=none
      CI_INFO="no run of $CI_WORKFLOW found yet"
      return 0
    fi
    read -r status conclusion link <<<"$parsed"
    CI_INFO="$link"
    if [ "$status" != completed ]; then
      CI_STATE=pending
    elif [ "$conclusion" = success ]; then
      CI_STATE=success
    else
      CI_STATE=failed
      CI_INFO="$conclusion - $link"
    fi
  }

  if is_true "$REQUIRE_CI" && [ "$FORCE" = 0 ]; then
    if [ -z "${GITHUB_REPO:-}" ]; then
      # git@github.com:owner/repo.git, https://github.com/owner/repo(.git), ssh://git@github.com/owner/repo
      local origin_url
      origin_url="$(git remote get-url origin)"
      origin_url="${origin_url%/}"
      origin_url="${origin_url%.git}"
      GITHUB_REPO="$(printf '%s' "$origin_url" | sed -nE 's#.*[:/]([^/:@]+)/([^/]+)$#\1/\2#p')"
      if [ -z "$GITHUB_REPO" ]; then
        log "ERROR: cannot work out the GitHub repository from the origin URL. Set GITHUB_REPO=owner/name in deploy.env (or REQUIRE_CI=false)."
        return 1
      fi
    fi
    ci_check "$REMOTE_SHA"
    [ "$CI_STATE" = error ] || state_del "ci-error" # the API works again: report the next outage afresh
    case "$CI_STATE" in
      success)
        log "CI passed for $REMOTE_SHORT ($CI_INFO)"
        rm -f "$STATE_DIR/ci-wait"
        ;;
      pending)
        log "Waiting for CI to finish for $REMOTE_SHORT ($CI_INFO)"
        return 0
        ;;
      none)
        # CI may simply not have started yet. Complain (once) only if it never shows up.
        local first_seen="" sha_waiting="" now
        now="$(date +%s)"
        if [ -f "$STATE_DIR/ci-wait" ]; then
          read -r sha_waiting first_seen <"$STATE_DIR/ci-wait" || true
          [ "${sha_waiting:-}" = "$REMOTE_SHA" ] || first_seen=""
        fi
        if [ -z "$first_seen" ]; then
          first_seen="$now"
          [ "$DRY_RUN" = 1 ] || printf '%s %s\n' "$REMOTE_SHA" "$now" >"$STATE_DIR/ci-wait"
        fi
        log "Waiting for CI: no $CI_WORKFLOW run for $REMOTE_SHORT yet"
        if [ $((now - first_seen)) -ge $((CI_WAIT_MAX * 60)) ]; then
          say_once "ci-missing:$REMOTE_SHA" warn \
            "No CI run appeared for $REMOTE_SHORT within $CI_WAIT_MAX minutes. Check the Actions tab on GitHub, or deploy by hand with: sudo $SCRIPT_PATH --force"
        fi
        return 0
        ;;
      failed)
        say_once "ci-failed:$REMOTE_SHA" fail \
          "NOT deploying $REMOTE_SHORT ($SUBJECT): CI failed ($CI_INFO)."
        return 0
        ;;
      *)
        say_once "ci-error" warn "Could not check CI for $REMOTE_SHORT: $CI_INFO. Will try again."
        return 0
        ;;
    esac
  elif [ "$FORCE" = 1 ]; then
    log "CI gate skipped (--force)."
  fi

  # ------------------------------------------------------------------------------------------
  # What the deploy will do (also what --dry-run prints)
  # ------------------------------------------------------------------------------------------
  local APP_DOMAIN STACK_ENV_VALUE HTTP_BIND HTTP_HOST HTTP_PORT SEED_DB=1 BACKUP_FILE CHECK_SERVICES=""
  APP_DOMAIN="$(kv_get "$ENV_FILE" APP_DOMAIN)"
  STACK_ENV_VALUE="${STACK_ENV:-$(kv_get "$ENV_FILE" STACK_ENV)}"
  STACK_ENV_VALUE="$(printf '%s' "${STACK_ENV_VALUE:-production}" | tr '[:upper:]' '[:lower:]')"
  # Same rule as database/seeders/DatabaseSeeder.php: on local/demo/testing, db:seed also adds
  # demo users and content. Never do that on a deploy; the first seeding was done by hand.
  case "$STACK_ENV_VALUE" in local | demo | testing) SEED_DB=0 ;; esac

  if [ -z "${HEALTH_URL:-}" ]; then
    # Caddy's published port from .env: "80", "8880", or "127.0.0.1:8880".
    HTTP_BIND="${CADDY_HTTP_BIND:-$(kv_get "$ENV_FILE" CADDY_HTTP_BIND)}"
    HTTP_HOST=127.0.0.1
    HTTP_PORT=80
    if [[ "$HTTP_BIND" == *:* ]]; then
      HTTP_HOST="${HTTP_BIND%:*}"
      HTTP_PORT="${HTTP_BIND##*:}"
      case "$HTTP_HOST" in "" | 0.0.0.0 | "[::]" | "::") HTTP_HOST=127.0.0.1 ;; esac
    elif [[ "$HTTP_BIND" =~ ^[0-9]+$ ]]; then
      HTTP_PORT="$HTTP_BIND"
    fi
    HEALTH_URL="http://$HTTP_HOST:$HTTP_PORT/"
  fi

  BACKUP_FILE="$BACKUP_DIR/$(date +%Y%m%d-%H%M%S)-$LOCAL_SHORT.sql.gz"

  if [ "$DRY_RUN" = 1 ]; then
    log "[dry-run] $LOCAL_SHORT -> $REMOTE_SHORT is ready to deploy: $SUBJECT"
    git log -n 15 --oneline --no-decorate "$LOCAL_SHA..$REMOTE_SHA" | log_block "[dry-run]   "
    log "[dry-run] would back up both databases to $BACKUP_FILE (keeping the newest $BACKUP_KEEP)"
    log "[dry-run] would run: git merge --ff-only; docker/setup.sh --secrets-only (adds secrets missing from .env, changes nothing else); docker compose build; docker compose up -d --remove-orphans; restart caddy if docker/caddy changed"
    if [ "$SEED_DB" = 1 ]; then
      log "[dry-run] would run: php artisan migrate --force; php artisan db:seed --force"
    else
      log "[dry-run] would run: php artisan migrate --force (no seeding: STACK_ENV=$STACK_ENV_VALUE)"
    fi
    log "[dry-run] would wait up to ${HEALTH_TIMEOUT}s for these services (those that exist): $HEALTH_SERVICES; and for $HEALTH_URL${APP_DOMAIN:+ (Host: $APP_DOMAIN)} to answer"
    if is_true "$ROLLBACK_ON_FAILURE"; then
      log "[dry-run] on failure: git reset --hard $LOCAL_SHORT, rebuild, restart"
    else
      log "[dry-run] on failure: leave things as they are (ROLLBACK_ON_FAILURE=false)"
    fi
    notify ok "Deployed $REMOTE_SHORT: $SUBJECT"
    log "[dry-run] nothing was changed."
    return 0
  fi

  # ------------------------------------------------------------------------------------------
  # 4-7. The deploy
  # ------------------------------------------------------------------------------------------
  : >"$RUN_LOG"

  # step DESCRIPTION COMMAND...: run a command with its output going to $RUN_LOG; on failure show
  # the last lines. Returns the command's exit status (so call it as `step ... || handle`).
  step() {
    local desc="$1" rc=0
    shift
    log "-> $desc"
    printf '\n===== %s: %s\n' "$(date '+%F %T')" "$*" >>"$RUN_LOG"
    "$@" >>"$RUN_LOG" 2>&1 || rc=$?
    if [ "$rc" != 0 ]; then
      log "   FAILED (exit $rc): $desc. Last lines of its output (full output: $RUN_LOG):"
      grep -v '^[[:space:]]*$' "$RUN_LOG" | tail -n 15 | log_block "   | " || true
    fi
    return "$rc"
  }

  # backup_databases: gzip'd SQL dump of the site database and the forum database, taken inside the
  # mariadb container (it already has the root password and both database names in its environment,
  # so no secret appears on the host command line). Written to a .partial file, checked, then renamed.
  backup_databases() {
    local tmp="$BACKUP_FILE.partial" tail_text
    mkdir -p "$BACKUP_DIR" && chmod 700 "$BACKUP_DIR" || return 1
    log "-> backing up databases to $BACKUP_FILE"
    # shellcheck disable=SC2016 # the $VARIABLES must be expanded by the shell INSIDE the container
    if ! dc exec -T mariadb sh -c \
      'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction --routines --events --databases "$MARIADB_DATABASE" "$FLARUM_DB_DATABASE"' \
      2>>"$RUN_LOG" | gzip -c >"$tmp"; then
      rm -f "$tmp"
      return 1
    fi
    # A complete dump ends with a "-- Dump completed" comment; a truncated one does not.
    if ! gzip -t "$tmp" 2>>"$RUN_LOG"; then rm -f "$tmp"; return 1; fi
    tail_text="$(gzip -dc "$tmp" | tail -n 3)"
    if [[ "$tail_text" != *"Dump completed"* ]]; then
      echo "dump looks incomplete" >>"$RUN_LOG"
      rm -f "$tmp"
      return 1
    fi
    chmod 600 "$tmp" && mv "$tmp" "$BACKUP_FILE" || return 1
    log "   backup ok ($(du -h "$BACKUP_FILE" | cut -f1))"
    # Keep the newest BACKUP_KEEP (file names start with a timestamp, so sorting by name = by age).
    local f i=0
    while IFS= read -r f; do
      i=$((i + 1))
      if [ "$i" -gt "$BACKUP_KEEP" ]; then
        rm -f -- "$f" && log "   removed old backup $(basename "$f")"
      fi
    done < <(find "$BACKUP_DIR" -maxdepth 1 -type f -name '[0-9]*-[0-9]*-*.sql.gz' | sort -r)
    return 0
  }

  # services_ready: are all CHECK_SERVICES running and (if they have a health check) healthy?
  # PENDING says who is not. Reads `docker compose ps`, one "service|state|health" line each.
  local PENDING=""
  services_ready() {
    local states svc line state health
    PENDING=""
    states="$(dc ps -a --format '{{.Service}}|{{.State}}|{{.Health}}' 2>/dev/null || true)"
    for svc in "$@"; do
      line="$(grep -E "^${svc}\|" <<<"$states" | head -n 1 || true)"
      if [ -z "$line" ]; then PENDING="$PENDING $svc(missing)"; continue; fi
      IFS='|' read -r _ state health <<<"$line"
      if [ "$state" != running ] || { [ -n "$health" ] && [ "$health" != healthy ]; }; then
        PENDING="$PENDING $svc(${state}${health:+/$health})"
      fi
    done
    [ -z "$PENDING" ]
  }

  # url_ok: does the site answer (2xx or 3xx) on HEALTH_URL?
  local URL_CODE=""
  url_ok() {
    local -a args=(-s -o /dev/null -w '%{http_code}' --max-time 10)
    [ -n "$APP_DOMAIN" ] && args+=(-H "Host: $APP_DOMAIN")
    URL_CODE="$(curl "${args[@]}" "$HEALTH_URL" 2>/dev/null)" || URL_CODE=000
    [[ "$URL_CODE" =~ ^[23] ]]
  }

  # wait_ready DEADLINE [--url] SERVICES...: poll until the services are ready (and, with --url,
  # HEALTH_URL answers too) or the clock (bash's $SECONDS) passes DEADLINE.
  wait_ready() {
    local deadline="$1" check_url=0
    shift
    [ "${1:-}" = "--url" ] && { check_url=1; shift; }
    while :; do
      if services_ready "$@" && { [ "$check_url" = 0 ] || url_ok; }; then return 0; fi
      if [ "$SECONDS" -ge "$deadline" ]; then
        if [ -n "$PENDING" ]; then log "   not ready after ${HEALTH_TIMEOUT}s:$PENDING"; else log "   $HEALTH_URL answered HTTP $URL_CODE"; fi
        return 1
      fi
      sleep "$HEALTH_INTERVAL"
    done
  }

  # Which of HEALTH_SERVICES does this compose project actually have?
  local s all_services
  all_services="$(dc config --services 2>/dev/null || true)"
  for s in $HEALTH_SERVICES; do
    if grep -qx "$s" <<<"$all_services"; then CHECK_SERVICES="$CHECK_SERVICES $s"; fi
  done
  local -a CHECK_LIST=()
  read -r -a CHECK_LIST <<<"$CHECK_SERVICES"
  local APP_IN_STACK=0
  grep -qx app <<<"$all_services" && APP_IN_STACK=1

  # Caddy reads docker/caddy/* (a bind mount) only when it starts, and `docker compose up -d` does not
  # recreate a container just because a mounted file changed, so a new route would never take effect.
  # restart_caddy_if_changed FROM TO: restart caddy when docker/caddy differs between the two commits.
  local CADDY_IN_STACK=0
  grep -qx caddy <<<"$all_services" && CADDY_IN_STACK=1
  restart_caddy_if_changed() {
    [ "$CADDY_IN_STACK" = 1 ] || return 0
    git diff --quiet "$1" "$2" -- docker/caddy && return 0
    step "restart caddy (its configuration in docker/caddy changed)" dc restart caddy
  }

  # mark_failed: remember this commit as bad (no retry until a new commit or --force).
  mark_failed() { state_add "failed:$REMOTE_SHA"; }

  # finish_failed LEVEL MESSAGE: common end of every failed deploy.
  finish_failed() {
    rm -f "$STATE_DIR/in-progress"
    mark_failed
    log "$2"
    notify "$1" "$2"
  }

  log "Deploying $LOCAL_SHORT -> $REMOTE_SHORT: $SUBJECT"

  # --- backup (nothing has been changed yet) ---
  if ! backup_databases; then
    grep -v '^[[:space:]]*$' "$RUN_LOG" | tail -n 10 | log_block "   | " || true
    say_once "backup-failed:$REMOTE_SHA" fail \
      "Deploy of $REMOTE_SHORT aborted: the database backup failed, nothing was changed. Is the stack running ('docker compose ps')? Retrying on the next run."
    return 1
  fi

  # --- new code ---
  printf '%s %s\n' "$LOCAL_SHA" "$REMOTE_SHA" >"$STATE_DIR/in-progress"
  if ! step "git merge --ff-only $REMOTE_SHORT" git merge --ff-only "$REMOTE_SHA"; then
    rm -f "$STATE_DIR/in-progress"
    say_once "merge-failed:$REMOTE_SHA" fail "Deploy of $REMOTE_SHORT aborted: git could not fast-forward the checkout. Nothing was changed."
    return 1
  fi

  # Everything below can be undone by putting the old code back.
  # rollback REASON: old code + rebuild + restart + health check, then tell the owner.
  rollback() {
    local reason="$1" msg backup_note="Database backup from before the deploy: $BACKUP_FILE (migrations are NOT undone automatically; restore steps: docs/DEPLOY.md)."
    if ! is_true "$ROLLBACK_ON_FAILURE"; then
      finish_failed fail "Deploy of $REMOTE_SHORT FAILED ($reason). ROLLBACK_ON_FAILURE is off, so the new version is still in place. $backup_note"
      return 1
    fi
    log "Rolling back to $LOCAL_SHORT ..."
    if ! step "git reset --hard $LOCAL_SHORT" git reset --hard "$LOCAL_SHA"; then
      finish_failed crit "Deploy of $REMOTE_SHORT FAILED ($reason) and the rollback could not reset the code. Manual action needed on the server."
      return 1
    fi
    if step "rebuild the previous version" dc build &&
      step "start the previous version" dc up -d --remove-orphans &&
      restart_caddy_if_changed "$REMOTE_SHA" "$LOCAL_SHA"; then
      if wait_ready $((SECONDS + HEALTH_TIMEOUT)) --url "${CHECK_LIST[@]}"; then
        msg="Deploy of $REMOTE_SHORT FAILED ($reason). Rolled back to $LOCAL_SHORT, the site is running the old code again. $backup_note"
        finish_failed fail "$msg"
        return 1
      fi
    fi
    finish_failed crit "Deploy of $REMOTE_SHORT FAILED ($reason) and the rolled-back version is NOT healthy either. The site may be down: check 'docker compose ps' and 'docker compose logs'. $backup_note"
    return 1
  }

  # abort_untouched REASON: the running containers were never touched; only the code moves back.
  abort_untouched() {
    git reset --hard "$LOCAL_SHA" >>"$RUN_LOG" 2>&1 || log "WARNING: could not reset the code to $LOCAL_SHORT"
    finish_failed fail "Deploy of $REMOTE_SHORT FAILED ($1). The running site was not touched and the code is back at $LOCAL_SHORT. Fix it and push, or retry with --force."
  }

  # The new code may need settings that this server's .env does not have yet (the live-chat REVERB_* secrets came
  # that way). Add exactly the missing ones now, with the setup.sh that was just merged, so the compose check
  # below does not refuse every deploy until somebody logs in. --secrets-only changes nothing else in .env, never
  # asks a question and prints names only, never values. It is not fatal here: the compose check decides.
  if [ -f "$ENV_FILE" ] && [ -f "$REPO/docker/setup.sh" ]; then
    if step "add secrets that are missing from .env (docker/setup.sh --secrets-only)" bash "$REPO/docker/setup.sh" --secrets-only </dev/null; then
      grep -E '^(ok|!!) ' "$RUN_LOG" | tail -n 1 | log_block "   " || true
    else
      log "   WARNING: setup.sh could not complete; continuing, the compose check below tells whether anything is still missing."
    fi
  fi
  if ! step "check the compose configuration" dc config --quiet; then
    abort_untouched "docker compose configuration is invalid"
    return 1
  fi
  if ! step "build images (a few minutes if dependencies changed)" dc build; then
    abort_untouched "the image build failed"
    return 1
  fi

  # --- new containers ---
  local DEADLINE
  if ! step "start containers" dc up -d --remove-orphans; then
    rollback "docker compose up failed" || true
    return 1
  fi
  if ! restart_caddy_if_changed "$LOCAL_SHA" "$REMOTE_SHA"; then
    rollback "caddy could not be restarted with its new configuration" || true
    return 1
  fi
  DEADLINE=$((SECONDS + HEALTH_TIMEOUT))

  # The app container runs the migrations itself when it starts (AUTORUN_ENABLED in compose.yaml),
  # so wait for it first, then run migrate once more as a safety net (does nothing when up to date)
  # and seed the base data (idempotent: only inserts what is missing).
  if [ "$APP_IN_STACK" = 1 ]; then
    log "-> waiting for the app container (it runs migrations on start)"
    if ! wait_ready "$DEADLINE" app; then
      rollback "the app container did not become healthy" || true
      return 1
    fi
    if ! step "php artisan migrate --force" dc exec -T app php artisan migrate --force; then
      rollback "database migration failed" || true
      return 1
    fi
    if [ "$SEED_DB" = 1 ]; then
      if ! step "php artisan db:seed --force" dc exec -T app php artisan db:seed --force; then
        rollback "seeding the base data failed" || true
        return 1
      fi
    else
      log "-> skipping db:seed (STACK_ENV=$STACK_ENV_VALUE would add demo data)"
    fi
  fi

  log "-> health check: ${CHECK_LIST[*]:-none} and $HEALTH_URL"
  if ! wait_ready "$DEADLINE" --url "${CHECK_LIST[@]}"; then
    rollback "health check failed" || true
    return 1
  fi

  # --- done ---
  rm -f "$STATE_DIR/in-progress"
  log "Deployed $REMOTE_SHORT: $SUBJECT"
  notify ok "Deployed $REMOTE_SHORT: $SUBJECT"
  if step "remove dangling images" docker image prune -f; then :; fi
  return 0
}

usage() {
  cat <<'EOF'
Usage: auto-deploy.sh [--dry-run] [--force] [--verbose]

  -n, --dry-run   do the checks (git fetch, CI) and print what would happen; change nothing
  -f, --force     deploy even if CI has not passed (once), and retry a commit that failed before
  -v, --verbose   also print "nothing to do" lines (automatic when run from a terminal)
  -h, --help      this text

Settings (docker/deploy/deploy.env or environment variables), all optional:
  DEPLOY_BRANCH, REQUIRE_CI, GITHUB_REPO, GITHUB_TOKEN (deploy.env only), CI_WORKFLOW, NOTIFY_URL,
  BACKUP_DIR, BACKUP_KEEP, HEALTH_URL, ROLLBACK_ON_FAILURE, COMPOSER_AUTH.
  See docker/deploy/deploy.env.example.
EOF
}

main "$@"
exit $?
