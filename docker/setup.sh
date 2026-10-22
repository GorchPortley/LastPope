#!/usr/bin/env bash
#
# LastPope setup helper: prepares the .env file for `docker compose up -d`.
#
#   docker/setup.sh                                  asks for domain + e-mail
#   docker/setup.sh -d shop.example.com -e me@example.com   no questions
#
# What it does
#   * copies .env.example to .env if there is no .env yet
#   * asks for the public domain and an e-mail address (Let's Encrypt expiry notices)
#   * generates strong random passwords/keys: database passwords, Redis password, Laravel APP_KEY,
#     Flarum API key and forum admin password, JWT secret, and the id/key/secret of the live-chat
#     (Reverb) WebSocket server
#   * fills in the URLs that depend on the domain
#
# It is safe to run again: secrets that are already set are NEVER replaced (only empty values and
# the well-known placeholders from .env.example are). Nothing leaves your machine; PHP is not needed.
#
#   docker/setup.sh --secrets-only    for scripts (the auto-updater uses it): add only the secrets that are
#                                     still missing and that are safe to add to a running site (the live-chat
#                                     REVERB_* ones); every other line of .env is left exactly as it is

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

ENV_FILE=".env"
EXAMPLE_FILE=".env.example"

DOMAIN_ARG=""
EMAIL_ARG=""
NON_INTERACTIVE=0
PROXY_ARG=""
PROXY_PORT_ARG=""
SECRETS_ONLY=0

usage() {
  cat <<'EOF'
Usage: docker/setup.sh [-d DOMAIN] [-e EMAIL] [--behind-proxy [--proxy-port PORT] | --direct]
                       [--non-interactive] [-h]

  -d, --domain DOMAIN   public domain name, e.g. shop.example.com (use "localhost" for a local test)
  -e, --email  EMAIL    contact address for Let's Encrypt
  --behind-proxy        nginx/Apache/Webmin already uses ports 80/443 on this machine: it handles HTTPS
                        and forwards the domain to the stack on 127.0.0.1:PORT (default 8880)
  --proxy-port PORT     local port for --behind-proxy
  --direct              the stack's own Caddy answers on 80/443 and gets certificates (default)
  -n, --non-interactive never ask; take domain and e-mail from the options or from .env (for CI, scripts)
  --secrets-only        change nothing except adding secrets that are missing from an existing .env and are
                        safe to add to a running site (live chat: REVERB_APP_ID/KEY/SECRET). Never asks.
  -h, --help            show this help

Without options the script asks, and offers the values already in .env as defaults.
EOF
}

while [ $# -gt 0 ]; do
  case "$1" in
    -d | --domain) DOMAIN_ARG="${2:-}"; shift 2 ;;
    -e | --email) EMAIL_ARG="${2:-}"; shift 2 ;;
    --behind-proxy) PROXY_ARG=yes; shift ;;
    --direct) PROXY_ARG=no; shift ;;
    --proxy-port) PROXY_PORT_ARG="${2:-}"; shift 2 ;;
    -n | --non-interactive) NON_INTERACTIVE=1; shift ;;
    --secrets-only) SECRETS_ONLY=1; NON_INTERACTIVE=1; shift ;;
    -h | --help) usage; exit 0 ;;
    *) echo "Unknown option: $1" >&2; usage >&2; exit 2 ;;
  esac
done

# --- tiny helpers -----------------------------------------------------------

if [ -t 1 ]; then
  BOLD=$'\033[1m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; RED=$'\033[31m'; RESET=$'\033[0m'
else
  BOLD=""; GREEN=""; YELLOW=""; RED=""; RESET=""
fi

info() { printf '%s\n' "$*"; }
ok() { printf '%s\n' "${GREEN}ok${RESET}  $*"; }
warn() { printf '%s\n' "${YELLOW}!!${RESET}  $*" >&2; }
die() { printf '%s\n' "${RED}error${RESET} $*" >&2; exit 1; }

# env_get KEY [FILE]: last value of KEY in FILE (default .env), surrounding quotes removed.
env_get() {
  local key="$1" file="${2:-$ENV_FILE}" line
  [ -f "$file" ] || return 0
  line="$(grep -E "^${key}=" "$file" | tail -n 1 || true)"
  line="${line#*=}"
  line="${line%$'\r'}"
  if [[ "$line" =~ ^\"(.*)\"$ ]] || [[ "$line" =~ ^\'(.*)\'$ ]]; then
    line="${BASH_REMATCH[1]}"
  fi
  printf '%s' "$line"
}

# env_set KEY VALUE: replace the KEY= line in .env (all occurrences) or append it.
env_set() {
  local key="$1" value="$2" tmp
  tmp="$(mktemp)"
  K="$key" V="$value" awk '
    BEGIN { k = ENVIRON["K"]; v = ENVIRON["V"]; done = 0 }
    index($0, k "=") == 1 { if (!done) { print k "=" v; done = 1 } ; next }
    { print }
    END { if (!done) print k "=" v }
  ' "$ENV_FILE" >"$tmp"
  cat "$tmp" >"$ENV_FILE"
  rm -f "$tmp"
}

# rand_alnum N: N random letters/digits (no shell or YAML special characters on purpose).
rand_alnum() {
  local n="$1" out="" chunk
  while [ "${#out}" -lt "$n" ]; do
    chunk="$(head -c 256 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')"
    out+="$chunk"
  done
  printf '%s' "${out:0:n}"
}

# rand_lower N: N random lowercase letters/digits (Pusher/Reverb style keys).
rand_lower() {
  rand_alnum "$1" | tr 'A-Z' 'a-z'
}

# rand_digits N: a random number of N digits that does not start with 0 (Reverb app id).
rand_digits() {
  local n="$1" out=""
  while [ "${#out}" -lt "$n" ]; do
    out+="$(head -c 64 /dev/urandom | od -An -tu1 | tr -dc '0-9' | sed 's/^0*//')"
  done
  printf '%s' "${out:0:n}"
}

# rand_app_key: a Laravel APP_KEY (base64 of 32 random bytes), same format as `artisan key:generate`.
rand_app_key() {
  printf 'base64:%s' "$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
}

# is_placeholder KEY: true when KEY in .env is empty, "null" or still the sample value from .env.example.
is_placeholder() {
  local key="$1" cur sample
  cur="$(env_get "$key")"
  sample="$(env_get "$key" "$EXAMPLE_FILE")"
  [ -z "$cur" ] && return 0
  [ "$cur" = "null" ] && return 0
  [ -n "$sample" ] && [ "$cur" = "$sample" ] && return 0
  return 1
}

GENERATED=()

# ensure_secret KEY GENERATOR [ARGS...]: generate a value only if KEY is not set yet.
ensure_secret() {
  local key="$1"; shift
  if is_placeholder "$key"; then
    env_set "$key" "$("$@")"
    GENERATED+=("$key")
  fi
}

# ensure_value KEY VALUE: set KEY only if it is empty/placeholder.
ensure_value() {
  local key="$1" value="$2"
  if is_placeholder "$key"; then
    env_set "$key" "$value"
  fi
}

# ensure_stateless_secrets: secrets that hold no state anywhere else (unlike the database passwords, which MariaDB
# stores in its data volume), so a missing one can always be generated, also for a site that is already running.
# Put every secret that a later version of compose.yaml starts to require HERE, so the auto-updater adds it.
ensure_stateless_secrets() {
  # Live chat (Reverb): the app id is just a number, the key is public (browsers get it), the secret is not.
  ensure_secret REVERB_APP_ID rand_digits 8
  ensure_secret REVERB_APP_KEY rand_lower 20
  ensure_secret REVERB_APP_SECRET rand_lower 40
}

ask() {
  # ask "Prompt" "default" -> value on stdout
  local prompt="$1" default="${2:-}" reply
  if [ -n "$default" ]; then
    read -r -p "$prompt [$default]: " reply </dev/tty || reply=""
  else
    read -r -p "$prompt: " reply </dev/tty || reply=""
  fi
  printf '%s' "${reply:-$default}"
}

valid_domain() {
  [[ "$1" == "localhost" ]] || [[ "$1" =~ ^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$ ]]
}

valid_email() {
  [[ "$1" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]]
}

# --- 0. --secrets-only: add what is missing, touch nothing else --------------

if [ "$SECRETS_ONLY" = 1 ]; then
  [ -f "$ENV_FILE" ] || die "$ENV_FILE not found: run docker/setup.sh without --secrets-only first."
  ensure_stateless_secrets
  if [ "${#GENERATED[@]}" -gt 0 ]; then
    ok "added to $ENV_FILE (secrets that were missing): ${GENERATED[*]}"
  else
    ok "$ENV_FILE already has every secret that can be added automatically; nothing changed"
  fi
  exit 0
fi

# --- 1. .env ----------------------------------------------------------------

[ -f "$EXAMPLE_FILE" ] || die "$EXAMPLE_FILE not found; run this script from a full checkout of the project."

CREATED=0
if [ ! -f "$ENV_FILE" ]; then
  cp "$EXAMPLE_FILE" "$ENV_FILE"
  CREATED=1
  ok "created $ENV_FILE from $EXAMPLE_FILE"
else
  ok "using existing $ENV_FILE (existing secrets are kept)"
fi
chmod 600 "$ENV_FILE" 2>/dev/null || true

# "First run" = this script has not configured the file before (new file, or no contact e-mail yet).
FIRST_RUN=0
if [ "$CREATED" = 1 ] || [ -z "$(env_get ACME_EMAIL)" ]; then
  FIRST_RUN=1
fi

# --- 2. domain + e-mail -----------------------------------------------------

has_tty() { ( exec </dev/tty ) 2>/dev/null; }

INTERACTIVE=1
if [ "$NON_INTERACTIVE" = 1 ] || ! has_tty || { [ -n "$DOMAIN_ARG" ] && [ -n "$EMAIL_ARG" ]; }; then
  INTERACTIVE=0
fi

domain="${DOMAIN_ARG:-$(env_get APP_DOMAIN)}"
email="${EMAIL_ARG:-$(env_get ACME_EMAIL)}"

if [ "$INTERACTIVE" = 1 ]; then
  echo
  info "${BOLD}Which domain will the site use?${RESET}"
  info "Point its DNS A record (and AAAA if you have IPv6) at this server first."
  info "For a test on your own computer, enter: localhost"
  while true; do
    domain="$(ask "Domain" "${domain:-}")"
    domain="${domain#http://}"; domain="${domain#https://}"; domain="${domain%%/*}"
    domain="$(printf '%s' "$domain" | tr '[:upper:]' '[:lower:]')"
    if valid_domain "$domain"; then break; fi
    warn "'$domain' does not look like a domain name (example: shop.example.com)"
    domain=""
  done

  echo
  info "${BOLD}Contact e-mail${RESET} (Let's Encrypt sends certificate expiry warnings here; also the first forum admin e-mail)"
  default_email="${email:-}"
  [ -z "$default_email" ] && [ "$domain" = "localhost" ] && default_email="admin@example.com"
  while true; do
    email="$(ask "E-mail" "$default_email")"
    if valid_email "$email"; then break; fi
    warn "'$email' does not look like an e-mail address"
    default_email=""
  done
fi

domain="${domain#http://}"; domain="${domain#https://}"; domain="${domain%%/*}"
domain="$(printf '%s' "$domain" | tr '[:upper:]' '[:lower:]')"
[ -n "$domain" ] && valid_domain "$domain" || die "No valid domain given. Run again with: docker/setup.sh -d shop.example.com -e you@example.com"
[ -n "$email" ] && valid_email "$email" || die "No valid e-mail given. Run again with: docker/setup.sh -d $domain -e you@example.com"

# --- 2b. who answers on ports 80/443 -----------------------------------------

behind="$PROXY_ARG"
[ -z "$behind" ] && [ "$(env_get CADDY_CONFIG)" = "Caddyfile.behind-proxy" ] && behind=yes
proxy_port="${PROXY_PORT_ARG:-$(env_get CADDY_HTTP_BIND)}"
proxy_port="${proxy_port##*:}"
[[ "$proxy_port" =~ ^[0-9]+$ ]] && [ "$proxy_port" != 80 ] || proxy_port=8880

# The CADDY_* lines are only (re)written when you chose something (an option, or an answer below) or when
# they are missing. A plain re-run, e.g. `docker/setup.sh -n` from a script, keeps what is in .env.
caddy_chosen=0
if [ -n "$PROXY_ARG" ] || [ -n "$PROXY_PORT_ARG" ]; then caddy_chosen=1; fi

if [ "$INTERACTIVE" = 1 ] && [ -z "$PROXY_ARG" ] && [ "$domain" != "localhost" ]; then
  caddy_chosen=1
  echo
  info "${BOLD}Does another web server already use ports 80/443 on this machine?${RESET}"
  info "Answer y if nginx, Apache or Webmin/Virtualmin already serve websites here. That server then keeps"
  info "handling HTTPS and forwards $domain to this stack. Answer n if this machine is only for this site."
  while true; do
    yn="$(ask "Existing web server (y/n)" "$([ "$behind" = yes ] && echo y || echo n)")"
    case "$yn" in
      y | Y | yes) behind=yes; break ;;
      n | N | no) behind=no; break ;;
    esac
  done
  if [ "$behind" = yes ]; then
    while true; do
      proxy_port="$(ask "Local port your web server should forward to" "$proxy_port")"
      [[ "$proxy_port" =~ ^[0-9]+$ ]] && [ "$proxy_port" -ge 1024 ] && [ "$proxy_port" -le 65534 ] && break
      warn "use a free port between 1024 and 65534"
    done
  fi
fi

if [ "$caddy_chosen" = 0 ] && [ -n "$(env_get CADDY_CONFIG)" ] && [ -n "$(env_get CADDY_HTTP_BIND)" ] && [ -n "$(env_get CADDY_HTTPS_BIND)" ]; then
  :  # keep the existing CADDY_CONFIG / CADDY_HTTP_BIND / CADDY_HTTPS_BIND
elif [ "$behind" = yes ]; then
  env_set CADDY_CONFIG "Caddyfile.behind-proxy"
  env_set CADDY_HTTP_BIND "127.0.0.1:$proxy_port"
  env_set CADDY_HTTPS_BIND "127.0.0.1:$((proxy_port + 1))"
else
  env_set CADDY_CONFIG "Caddyfile"
  env_set CADDY_HTTP_BIND "80"
  env_set CADDY_HTTPS_BIND "443"
fi

env_set APP_DOMAIN "$domain"
env_set ACME_EMAIL "$email"
env_set APP_URL "https://$domain"
env_set FLARUM_URL "https://$domain/forum"
ensure_value FLARUM_INTERNAL_URL "http://flarum:8000/forum"
ensure_value FLARUM_ADMIN_EMAIL "$email"
if [ "$FIRST_RUN" = 1 ]; then
  env_set FORUM_ENABLED "true"
fi
if [ "$domain" != "localhost" ] && [ "$(env_get MAIL_FROM_ADDRESS)" = "hello@example.com" ]; then
  env_set MAIL_FROM_ADDRESS "\"no-reply@$domain\""
fi

# --- 3. secrets ---------------------------------------------------------------

ensure_secret APP_KEY rand_app_key
ensure_secret MARIADB_ROOT_PASSWORD rand_alnum 32
ensure_secret MARIADB_PASSWORD rand_alnum 32
ensure_secret FLARUM_DB_PASSWORD rand_alnum 32
ensure_secret REDIS_PASSWORD rand_alnum 32
ensure_secret FLARUM_API_KEY rand_alnum 40
ensure_secret FLARUM_ADMIN_PASSWORD rand_alnum 20
ensure_secret JWT_SECRET rand_alnum 64
ensure_stateless_secrets

# Names that must not be empty (the example file normally has them already).
ensure_value MARIADB_DATABASE "lastpope"
ensure_value MARIADB_USER "lastpope"
ensure_value FLARUM_DB_DATABASE "flarum"
ensure_value FLARUM_DB_USERNAME "flarum"
ensure_value FLARUM_ADMIN_USER "forumadmin"

# --- 4. summary ---------------------------------------------------------------

echo
ok "domain ............ $domain"
ok "contact e-mail .... $email"
ok "site URL .......... https://$domain"
ok "forum URL ......... https://$domain/forum"
ok "live chat ......... wss://$domain/ws (Reverb container; nothing goes through pusher.com)"
if [ "$behind" = yes ]; then
  ok "web server ........ your existing one forwards $domain to http://127.0.0.1:$proxy_port"
else
  ok "web server ........ Caddy in Docker, ports 80 and 443"
fi
if [ "${#GENERATED[@]}" -gt 0 ]; then
  ok "generated ......... ${GENERATED[*]}"
else
  ok "no new secrets were needed (all already set)"
fi

if command -v getent >/dev/null 2>&1 && [ "$domain" != "localhost" ]; then
  if ! getent hosts "$domain" >/dev/null 2>&1; then
    warn "'$domain' does not resolve yet. Create the DNS A record before starting, otherwise no HTTPS certificate can be issued."
  fi
fi

if command -v docker >/dev/null 2>&1; then
  if docker compose version >/dev/null 2>&1; then
    if docker compose config --quiet >/dev/null 2>&1; then
      ok "docker compose configuration is valid"
    else
      warn "docker compose config reported a problem; run 'docker compose config' to see it"
    fi
  else
    warn "Docker Compose v2 not found (need 'docker compose', version 2.24 or newer)"
  fi
else
  warn "Docker is not installed on this machine yet (see docs/DEPLOY.md, step 'Install Docker')"
fi

forum_admin="$(env_get FLARUM_ADMIN_USER)"
cat <<EOF

${BOLD}Next steps${RESET}
  1. Back up the file  ${BOLD}$ROOT/$ENV_FILE${RESET}  somewhere safe (password manager). It holds every secret;
     lose it and you cannot read your own database. It is git-ignored: never commit it.
  2. Fill in the optional parts in $ENV_FILE when you are ready: Stripe keys, SMTP mail settings.
$( [ "$behind" = yes ] && printf '%s\n' "  2b. Configure your existing web server to forward https://$domain to http://127.0.0.1:$proxy_port" \
      "      (nginx example incl. the WebSocket location for live chat + HTTPS certificate: section" \
      "      'Behind an existing web server' in docs/DEPLOY.md)." )
  3. Start everything:
         docker compose up -d --build
     The first start takes a few minutes (building the image, creating the databases, installing the forum).
     Watch it with:  docker compose logs -f
  4. First start only: load the base data (roles, settings, theme), connect the forum, create your admin:
         docker compose exec app php artisan db:seed --force
         docker compose exec app php artisan forum:setup
         docker compose exec app php artisan app:create-user
  5. Open https://$domain
     The forum is at https://$domain/forum  (forum admin login: ${BOLD}$forum_admin${RESET}, password = FLARUM_ADMIN_PASSWORD in $ENV_FILE)

Full guide: docs/DEPLOY.md
EOF
