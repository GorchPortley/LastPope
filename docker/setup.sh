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
#     Flarum API key and forum admin password, JWT secret
#   * fills in the URLs that depend on the domain
#
# It is safe to run again: secrets that are already set are NEVER replaced (only empty values and
# the well-known placeholders from .env.example are). Nothing leaves your machine; PHP is not needed.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

ENV_FILE=".env"
EXAMPLE_FILE=".env.example"

DOMAIN_ARG=""
EMAIL_ARG=""
NON_INTERACTIVE=0

usage() {
  cat <<'EOF'
Usage: docker/setup.sh [-d DOMAIN] [-e EMAIL] [--non-interactive] [-h]

  -d, --domain DOMAIN   public domain name, e.g. shop.example.com (use "localhost" for a local test)
  -e, --email  EMAIL    contact address for Let's Encrypt
  -n, --non-interactive never ask; take domain and e-mail from the options or from .env (for CI, scripts)
  -h, --help            show this help

Without options the script asks, and offers the values already in .env as defaults.
EOF
}

while [ $# -gt 0 ]; do
  case "$1" in
    -d | --domain) DOMAIN_ARG="${2:-}"; shift 2 ;;
    -e | --email) EMAIL_ARG="${2:-}"; shift 2 ;;
    -n | --non-interactive) NON_INTERACTIVE=1; shift ;;
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
if [ "${#GENERATED[@]}" -gt 0 ]; then
  ok "generated ......... ${GENERATED[*]}"
else
  ok "no new secrets were needed (all already set)"
fi

if command -v getent >/dev/null 2>&1 && [ "$domain" != "localhost" ]; then
  if ! getent hosts "$domain" >/dev/null 2>&1; then
    warn "'$domain' does not resolve yet. Create the DNS A record before starting, otherwise Let's Encrypt cannot issue the certificate."
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
