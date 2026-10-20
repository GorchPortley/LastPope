#!/bin/bash
# Creates the Flarum database and its own database user.
#
# The official mariadb image runs this automatically, once, when the data volume is
# empty (first start). It is safe to run again by hand, for example to change the
# Flarum DB password later or to add the forum DB to an existing installation:
#
#   docker compose exec mariadb bash /docker-entrypoint-initdb.d/10-flarum.sh
#
# Needs these variables in the mariadb container (compose.yaml passes them):
#   MARIADB_ROOT_PASSWORD, FLARUM_DB_DATABASE, FLARUM_DB_USERNAME, FLARUM_DB_PASSWORD
set -euo pipefail

: "${MARIADB_ROOT_PASSWORD:?MARIADB_ROOT_PASSWORD is not set}"
: "${FLARUM_DB_DATABASE:=flarum}"
: "${FLARUM_DB_USERNAME:=flarum}"
: "${FLARUM_DB_PASSWORD:?FLARUM_DB_PASSWORD is not set}"

for v in FLARUM_DB_DATABASE FLARUM_DB_USERNAME; do
  if [[ ! "${!v}" =~ ^[A-Za-z0-9_]{1,32}$ ]]; then
    echo "10-flarum.sh: $v may only contain letters, digits and underscores (max 32)" >&2
    exit 1
  fi
done

# Escape the password for a single-quoted SQL string.
pw="${FLARUM_DB_PASSWORD//\\/\\\\}"
pw="${pw//\'/\\\'}"

echo "10-flarum.sh: creating database '${FLARUM_DB_DATABASE}' and user '${FLARUM_DB_USERNAME}'"

mariadb -uroot -p"${MARIADB_ROOT_PASSWORD}" <<SQL
CREATE DATABASE IF NOT EXISTS \`${FLARUM_DB_DATABASE}\`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${FLARUM_DB_USERNAME}'@'%' IDENTIFIED BY '${pw}';
ALTER USER '${FLARUM_DB_USERNAME}'@'%' IDENTIFIED BY '${pw}';
GRANT ALL PRIVILEGES ON \`${FLARUM_DB_DATABASE}\`.* TO '${FLARUM_DB_USERNAME}'@'%';
FLUSH PRIVILEGES;
SQL
