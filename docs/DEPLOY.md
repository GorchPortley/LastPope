# Deploying LastPope

This guide takes you from "I have a domain and a small server" to a running site with HTTPS, a
forum and (optionally) Stripe payments. No Docker experience needed; every command is copy-and-paste.

- [1. What you are installing](#1-what-you-are-installing)
- [2. What you need](#2-what-you-need)
- [3. Point your domain at the server](#3-point-your-domain-at-the-server)
- [4. Prepare the server and install Docker](#4-prepare-the-server-and-install-docker)
- [5. Get the code and create the settings file](#5-get-the-code-and-create-the-settings-file)
- [6. Start everything](#6-start-everything)
- [7. First run: base data and the first admin user](#7-first-run-base-data-and-the-first-admin-user)
- [8. Forum setup](#8-forum-setup)
- [9. Mail](#9-mail)
- [10. Stripe setup](#10-stripe-setup)
- [11. Backups and restore](#11-backups-and-restore)
- [12. Updating](#12-updating)
- [13. Logs and troubleshooting](#13-logs-and-troubleshooting)
- [14. Local development](#14-local-development)
- [15. Reference](#15-reference)

---

## 1. What you are installing

Seven containers, started together by Docker Compose (`compose.yaml`):

```
Internet (ports 80 / 443)
   |
   v
caddy ........ HTTPS certificates (Let's Encrypt, automatic) and the front door
   |-- /forum*   ->  flarum ...... the forum (Flarum 1.8), served under https://YOURDOMAIN/forum
   '-- (rest)    ->  app ......... the website (Laravel + Wave + Filament, nginx + PHP-FPM)

queue ........ background worker: builds download ZIPs/PDFs, sends mail, talks to the forum
scheduler .... runs the app's scheduled jobs once a minute
mariadb ...... the database server: one database for the site, one for the forum
redis ........ cache, login sessions and the job queue
```

Only `caddy` is reachable from the internet. The database, Redis and the forum never publish a port.

Everything that must survive restarts lives in Docker volumes: database, uploaded files and
downloads (`app-storage`), the forum's files, Redis, and Caddy's certificates.

## 2. What you need

| | |
|---|---|
| **A server (VPS)** | Ubuntu 24.04 LTS (or any recent Linux that runs Docker). **2 vCPU, 4 GB RAM, 40 GB disk** is comfortable. 2 GB RAM works if you add swap (below): the *first build* of the app image is the hungry part, running it is light. Any provider will do (Hetzner, DigitalOcean, Vultr, OVH, ...). |
| **A domain name** | For example `shop.example.com`. You must be able to edit its DNS records. |
| **An e-mail address** | Let's Encrypt sends certificate warnings there. Also the first forum admin's address. |
| **An SMTP mail service** | Needed for password resets and order e-mails: Brevo, Postmark, Mailgun, Amazon SES, or your hosting provider's relay. See [Mail](#9-mail). |
| **A Stripe account** | Only for the marketplace payments: see [Stripe setup](#10-stripe-setup). You can launch without it. |

Optional but recommended: a GitHub token, in case the build complains about GitHub rate limits (see
[Troubleshooting](#13-logs-and-troubleshooting)).

## 3. Point your domain at the server

In your domain provider's DNS panel create:

| Type | Name | Value |
|---|---|---|
| `A` | `shop` (or `@` for the bare domain) | your server's IPv4 address |
| `AAAA` | same name | your server's IPv6 address (only if the server has one) |

Check that it works (this can take a few minutes, sometimes longer):

```bash
dig +short A shop.example.com        # should print your server's IP
```

Do this **before** starting the stack: Let's Encrypt only issues a certificate once the domain
points at the server. If you use Cloudflare, set the record to "DNS only" (grey cloud) at least until
the first certificate has been issued.

## 4. Prepare the server and install Docker

Log in to the server with SSH, then:

**4.1 Update and open the firewall** (only SSH, HTTP and HTTPS may come in):

```bash
sudo apt update && sudo apt -y upgrade
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw allow 443/udp       # HTTP/3
sudo ufw enable
```

Note: Docker publishes ports by itself and bypasses `ufw` for them. That is fine here because only
Caddy publishes ports (80/443). Do not add `ports:` to the other services.

**4.2 (Only with 2 GB RAM) add swap:**

```bash
sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile
sudo mkswap /swapfile && sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
```

**4.3 Install Docker** (official script from docker.com, includes the Compose plugin):

```bash
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker "$USER"      # then log out and back in
docker compose version               # must print v2.24 or newer
```

## 5. Get the code and create the settings file

```bash
git clone <URL-OF-YOUR-REPOSITORY> lastpope
cd lastpope
docker/setup.sh
```

`docker/setup.sh` asks for your **domain** and **e-mail**, then creates the file `.env` with strong
random passwords for the databases, Redis, the Laravel `APP_KEY`, the forum admin and the forum API
key. You can run it again at any time: secrets that are already set are never overwritten.

Without questions: `docker/setup.sh -d shop.example.com -e you@example.com`.

> **Back up `.env` now** (password manager or an encrypted copy). It is the only place the
> passwords live. It is excluded from git on purpose; never commit it. If you lose `APP_KEY`, encrypted
> data in the database can no longer be read.

Things you can change in `.env` later (then run `docker compose up -d` to apply):

- `UPLOAD_MAX_SIZE`: largest single upload (default `256M`). One setting controls PHP, nginx and the
  upload fields in the dashboard (Livewire/Filament).
- `MAIL_*`: your SMTP service, see [Mail](#9-mail).
- `STRIPE_*`: see [Stripe setup](#10-stripe-setup).
- `FLARUM_FORUM_TITLE`: forum name shown at first install.

## 6. Start everything

```bash
docker compose up -d --build
```

The first run downloads images and builds the app image, so allow **5-15 minutes**. Then it
creates the databases, runs the migrations and installs the forum. Follow it with:

```bash
docker compose logs -f          # Ctrl+C stops watching, not the site
docker compose ps               # every service should end up "running" / "healthy"
```

When `app` and `flarum` are `healthy`, open **https://shop.example.com**. The very first request
may take a few seconds while Caddy obtains the certificate.

## 7. First run: base data and the first admin user

A new database is empty. The site needs its base data (user roles, settings, the theme) before it can show
a single page, so on the **first start only** run:

```bash
docker compose exec app php artisan db:seed --force
```

> **Run this once, on a brand-new installation.** The base seeders replace the roles, settings and users
> tables; running them again later on a live site would reset those tables.
> Demo products and demo accounts are only added when `APP_ENV` is `local` or `demo`; the production
> containers run with `APP_ENV=production`, so you get none of that.

<!-- TODO(P2a): confirm the production seeding story. DatabaseSeeder still calls UsersTableSeeder and
     ModelHasRolesTableSeeder, which insert a placeholder admin (admin@sdlabs.cc, old password hash) and
     delete()/re-insert the roles and users tables. Production needs a seeder that only creates roles,
     permissions, settings and themes, and is safe to run twice. Update this section when it exists. -->

Then create your own admin account:

```bash
docker compose exec app php artisan app:create-user
```

It asks for name, e-mail, username, password and role: choose `admin`. Log in at
https://shop.example.com/auth/login, then open the admin panel at `/admin`.

## 8. Forum setup

The forum is installed automatically. After the first start you already have:

- the forum at **https://shop.example.com/forum**, using Flarum 1.8 with the usual default
  extensions switched on (Tags, Likes, Mentions, Markdown, ...) plus the **Embed** extension, which
  the design and driver pages use to show their discussion inside the page
  (`/forum/embed/<discussion>`);
- a forum administrator named `forumadmin` (or `FLARUM_ADMIN_USER`) whose password is
  `FLARUM_ADMIN_PASSWORD` in `.env`. Log in at `/forum` and open **Administration** in the user menu
  to change the title, look and feel, or e-mail settings;
- the master API key (`FLARUM_API_KEY`) already stored in the forum's database, so the website
  can create users and discussions without any manual step;
- a separate database and database user (`flarum`) for the forum.

Members of the website are created in the forum and signed in automatically (single sign-on): the
site sets the forum's login cookie for the path `/forum` on the same domain, which is why the forum
lives under `/forum` and not on a separate sub-domain.

### Connecting the website to the forum

After the first start (and after you have created your admin account), run once:

```bash
docker compose exec app php artisan forum:setup
```

It prints a status table: is the forum reachable from the app, does the API key work, and do the three
forum **tags** exist. Missing tags are created: `designs`, `drivers` and `marketplace` (the slugs come
from `FORUM_DESIGN_TAG`, `FORUM_DRIVER_TAG`, `FORUM_LISTING_TAG`; an empty value switches the automatic
discussions off for that kind of item). The command is safe to repeat; `--check` only reports.

From then on, every design and driver that is **published** (active) gets its own discussion in the
forum, written under the owner's forum account, and the design/driver page shows it in the *Discussion*
tab. Drafts never get a thread. Discussions are created in the background by the `queue` container, so
they appear a few seconds after publishing; if the forum is down they are retried automatically.

Designs and drivers that were published before the forum was connected (or imported demo data) can be
given their discussions in one go:

```bash
docker compose exec app php artisan forum:setup --backfill
```

Marketplace listings follow the same rule once the `listings` table has a `forum_slug` column.

### Forum tips

- Add Flarum extensions by listing them in `.env` (`FLARUM_EXTENSIONS="flarum/embed:^1.8 fof/upload:^1.0"`) and
  running `docker compose up -d flarum`; or use the Extension Manager in the forum administration.
  Only extensions that support **Flarum 1.x** work with this image.
- The forum's mail settings are copied from `MAIL_*` once, on its first start. Change them later
  in the forum administration (Administration, Email).
- A member's forum user name is taken from their site username; Flarum only allows letters, digits, `-` and
  `_` (3-30 characters), so other characters are replaced. The names `admin`, `administrator`, the forum
  admin's own name and a few more are reserved: such members get a numbered name like `admin_2`.
- If the forum is down, logging in to the site still works; the member just is not signed in to the forum
  until the next login.
- Changing `FLARUM_ADMIN_PASSWORD` in `.env` does **not** change an existing forum account's
  password (it is only used for the first install). Change it in the forum itself.

## 9. Mail

The website and the forum send mail (password resets, receipts, notifications). In `.env`:

```
MAIL_MAILER=smtp
MAIL_HOST=smtp.your-provider.example
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="no-reply@shop.example.com"
```

Then `docker compose up -d`. Use your provider's instructions to add the SPF/DKIM DNS records,
otherwise mail ends up in spam. Test with:

```bash
docker compose exec app php artisan tinker --execute="Mail::raw('Hello from LastPope', fn(\$m) => \$m->to('you@example.com')->subject('Test'));"
```

## 10. Stripe setup

Payments use **Stripe Connect Express**: sellers onboard through Stripe, buyers pay once for a
multi-seller cart, and the platform keeps a commission. Do everything in **test mode** first (toggle in
the Stripe dashboard), then repeat with live keys. Stripe renames menu items now and then; the
steps stay the same.

**10.1 Create the account and enable Connect**

1. Create a Stripe account at <https://dashboard.stripe.com/register>.
2. Open **Connect** and click *Get started*. Choose **Platform or marketplace**, accounts are
   **Express**. Complete the platform profile (name, icon, brand colour) under
   *Settings, Connect settings*. Enable the *Express dashboard* for connected accounts.
3. Under *Settings, Connect, Onboarding options* make sure card payments and transfers
   are enabled for your countries.

**10.2 API keys** (Developers, API keys). Put them in `.env`:

```
STRIPE_PUBLISHABLE_KEY=pk_test_...
STRIPE_SECRET_KEY=sk_test_...
```

**10.3 Webhook endpoint 1: marketplace payments** (Developers, Webhooks, *Add endpoint*)

- Endpoint URL: `https://shop.example.com/stripe/marketplace/webhook`
- Listen to events **on your account**
- Events to send:
  - `checkout.session.completed`
  - `checkout.session.async_payment_succeeded`
  - `checkout.session.expired`
  - `charge.refunded`
- Copy the endpoint's *Signing secret* (`whsec_...`) into `.env` as `STRIPE_MARKETPLACE_WEBHOOK_SECRET`.

**10.4 Webhook endpoint 2: connected accounts**

- Click *Add endpoint* again and choose **Listen to events on Connected accounts**.
- Endpoint URL: `https://shop.example.com/stripe/connect/webhook`
- Event to send: `account.updated`
- Copy this endpoint's own signing secret into `.env` as `STRIPE_CONNECT_WEBHOOK_SECRET`.

The two endpoints have **different** signing secrets. The older `STRIPE_WEBHOOK_SECRET` belongs to the
template's subscription billing and is separate from both.

**10.5 Apply and test**

```bash
docker compose up -d app queue scheduler        # re-read .env
```

In the Stripe dashboard use *Send test webhook* on each endpoint; the response must be `200`. Then make
a test purchase with card `4242 4242 4242 4242` (any future date, any CVC). To test locally without a public URL
use the Stripe CLI container, see [Local development](#14-local-development).

**Going live:** switch the dashboard to live mode, create the same two endpoints again (live endpoints
have new signing secrets), put the live keys and secrets in `.env`, and run `docker compose up -d app queue scheduler`.

## 11. Backups and restore

What to back up: **the databases**, **the uploaded files** (volume `app-storage`), **the forum files**
(volume `flarum-data`) and **`.env`**. The certificates (`caddy-data`) are optional: they are re-issued
automatically.

Volume names start with the project name: `lastpope_app-storage`, `lastpope_flarum-data`. List them with
`docker volume ls`.

**Back up** (run in the project folder; the files end up in `~/backups`):

```bash
mkdir -p ~/backups && cd ~/lastpope
STAMP=$(date +%F-%H%M)

# 1. both databases in one compressed file
docker compose exec -T mariadb sh -c \
  'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction --routines --events \
     --databases "$MARIADB_DATABASE" "$FLARUM_DB_DATABASE"' | gzip > ~/backups/db-$STAMP.sql.gz

# 2. uploaded files and downloads
docker run --rm -v lastpope_app-storage:/data:ro -v ~/backups:/backup alpine \
  tar czf /backup/app-storage-$STAMP.tgz -C /data .

# 3. forum files (avatars, installed extensions)
docker run --rm -v lastpope_flarum-data:/data:ro -v ~/backups:/backup alpine \
  tar czf /backup/flarum-data-$STAMP.tgz -C /data .

# 4. the settings file
cp .env ~/backups/env-$STAMP && chmod 600 ~/backups/env-$STAMP
```

Copy `~/backups` off the server regularly (`rsync`, `rclone`, your provider's snapshot feature). A backup
that only exists on the same server is not a backup. To run this daily, put the commands in a script and
add it with `crontab -e`, for example `15 3 * * * /home/you/backup.sh`.

**Restore** (on a new server, or to roll back the current one). Install Docker and clone the project first
(sections 4 and 5), but do **not** run `docker/setup.sh`: use your saved `.env` instead.

```bash
cd ~/lastpope
cp ~/backups/env-STAMP .env

# 1. build the images and create the containers and (empty) volumes, but do not start them yet
docker compose build
docker compose up --no-start

# 2. start only the database, then load the dump (this replaces the site and forum databases)
docker compose up -d mariadb
gunzip < ~/backups/db-STAMP.sql.gz | docker compose exec -T mariadb sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD"'

# 3. unpack the files into the volumes
docker run --rm -v lastpope_app-storage:/data -v ~/backups:/backup alpine \
  sh -c 'cd /data && tar xzf /backup/app-storage-STAMP.tgz'
docker run --rm -v lastpope_flarum-data:/data -v ~/backups:/backup alpine \
  sh -c 'cd /data && tar xzf /backup/flarum-data-STAMP.tgz'

# 4. start everything
docker compose up -d
```

Replace `STAMP` with the date part of your backup file names. The passwords in the restored `.env`
must be the ones the database was created with, which is why `.env` is part of the backup.

## 12. Updating

```bash
cd ~/lastpope
git pull
docker compose up -d --build
```

That rebuilds the images, restarts what changed and runs new database migrations automatically (the
`app` container does it on start). Visitors may see a short interruption (a few seconds). Take a
[backup](#11-backups-and-restore) first when the update contains database changes.

Housekeeping: `docker image prune -f` removes old image layers after an update.

Software versions are pinned in the repository, so nothing changes by itself: MariaDB 11.8, Redis 8.2,
Caddy 2.11, PHP 8.3 with nginx (serversideup/php v5), Flarum 1.8. A new version arrives only when the
repository changes the pinned tag. Do not jump MariaDB across major versions without reading the MariaDB
upgrade notes.

Apply operating-system security updates on the server regularly (`sudo apt update && sudo apt upgrade`, or enable
`unattended-upgrades`).

## 13. Logs and troubleshooting

Useful commands (run in the project folder):

```bash
docker compose ps                         # state and health of every service
docker compose logs -f app                # website (PHP errors, requests)
docker compose logs -f queue              # background jobs
docker compose logs -f flarum             # forum
docker compose logs -f caddy              # HTTPS, certificates, access log
docker compose exec app php artisan about # app environment overview
docker compose exec app php artisan tinker
docker compose restart app                # restart one service
docker stats                              # CPU/RAM per container
```

| Symptom | What to check |
|---|---|
| Browser says the connection is not secure / certificate error | `docker compose logs caddy`. Is the DNS A record correct (`dig +short A your.domain`)? Are ports 80 and 443 open in the server firewall **and** your provider's cloud firewall? Let's Encrypt must reach port 80. |
| `502 Bad Gateway` for a minute after starting or updating | Normal while `app` runs migrations. If it stays, `docker compose logs app`. |
| `app` is `unhealthy` or keeps restarting | `docker compose logs app`. Typical causes: wrong or empty values in `.env`, a failing migration, `route:cache` failing on a closure route. |
| `variable is not set` / `... is empty - run docker/setup.sh first` when starting | `.env` is missing a value: run `docker/setup.sh` again. |
| Build stops with `Could not authenticate against github.com` | GitHub limits anonymous downloads. Create a token at <https://github.com/settings/tokens> (a classic token with **no** scopes is enough) and build once with `COMPOSER_AUTH='{"github-oauth":{"github.com":"ghp_yourtoken"}}' docker compose build`. |
| Build is killed / `npm` or `composer` exits with "Killed" | Out of memory. Add swap (step 4.2) or use a larger server for the build. |
| `Access denied for user 'lastpope'` after changing a DB password in `.env` | MariaDB only reads passwords when its data volume is created. Change it inside the database: `docker compose exec mariadb mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -e "ALTER USER 'lastpope'@'%' IDENTIFIED BY 'the-new-password'"`; for the forum user re-run `docker compose exec mariadb bash /docker-entrypoint-initdb.d/10-flarum.sh`. |
| Forum shows an error page or never gets healthy | `docker compose logs flarum`. The first start installs extensions from packagist.org, so the server needs outbound internet. |
| Forum login / "not logged in to the forum" | `FORUM_ENABLED=true`, `FLARUM_API_KEY` set, and `FLARUM_URL` equals `https://<domain>/forum`. Then `docker compose up -d app queue scheduler`. |
| Designs/drivers get no forum discussion | `docker compose exec app php artisan forum:setup` shows what is wrong (unreachable forum, rejected API key, missing tag). Details are in `docker compose logs queue`. "The number of primary tags must be 1" means the tag exists but is a secondary tag: in the forum administration, Tags, drag it into the primary list. |
| Uploads fail for big files | Raise `UPLOAD_MAX_SIZE` in `.env` and `docker compose up -d app`. (Caddy allows up to 1 GB per request.) |
| Disk is full | `docker system df`, then `docker image prune -f` and `docker builder prune -f`. Logs are rotated automatically (10 MB x 5 per container). |

**Starting from scratch** (this deletes the database, uploads and certificates; only do it on a test setup):

```bash
docker compose down -v
```

## 14. Local development

`compose.dev.yaml` is an optional extra layer for working on the code on your own computer. It is never
used on the server.

```bash
git clone <URL-OF-YOUR-REPOSITORY> lastpope && cd lastpope
docker/setup.sh -d localhost -e dev@example.com

# put these three lines in .env (DEV_UID/DEV_GID: output of `id -u` and `id -g`)
#   DEV_UID=1000
#   DEV_GID=1000
#   COMPOSE_FILE=compose.yaml:compose.dev.yaml

docker compose run --rm --no-deps -e AUTORUN_ENABLED=false app composer install
docker compose up -d --build
```

What you get:

| | |
|---|---|
| **https://localhost** | the site (Caddy uses its own local certificate: accept the browser warning, or trust it with `docker compose cp caddy:/data/caddy/pki/authorities/local/root.crt .` and import that file) |
| **https://localhost/forum** | the forum |
| **http://localhost:8025** | Mailpit: every e-mail the site or queue sends lands here |
| **http://localhost:5173** | Vite dev server (hot reload) for CSS/JS; started as service `vite` |
| `127.0.0.1:3307` | MariaDB from your host (user/password from `.env`) |

Your checkout is mounted into the containers, so PHP changes are live. After editing a job class run
`docker compose restart queue`. Run the tests with `docker compose exec app php artisan test` (or on your host
as usual). Without Docker, `php artisan serve` with SQLite still works as before.

**Stripe webhooks while developing** (needs `STRIPE_SECRET_KEY=sk_test_...` in `.env`):

```bash
docker compose --profile stripe up -d stripe-cli
docker compose logs stripe-cli     # shows:  Ready! Your webhook signing secret is whsec_...
```

The Stripe CLI forwards `/stripe/marketplace/webhook` and `/stripe/connect/webhook` to the app. Put the
`whsec_...` it printed into **both** `STRIPE_MARKETPLACE_WEBHOOK_SECRET` and `STRIPE_CONNECT_WEBHOOK_SECRET`
(the CLI signs all forwarded events with that one secret), then `docker compose up -d app queue`.

## 15. Reference

**Services**

| Service | Image | Notes |
|---|---|---|
| `caddy` | `caddy:2.11.7` | ports 80, 443/tcp, 443/udp; config `docker/caddy/Caddyfile` |
| `app` | built from `docker/app/Dockerfile` on `serversideup/php:8.3-fpm-nginx-v5.0.1` | internal port 8080; runs migrations + caches on start |
| `queue` | same image | `php artisan queue:work --tries=3` |
| `scheduler` | same image | `php artisan schedule:work` |
| `mariadb` | `mariadb:11.8.9` | databases: app (`MARIADB_DATABASE`) and forum (`FLARUM_DB_DATABASE`) |
| `redis` | `redis:8.2.10-alpine` | password protected, append-only persistence |
| `flarum` | built from `docker/flarum/Dockerfile` on `crazymax/flarum:1.8.20` | internal port 8000 |

**Volumes** (prefix `lastpope_`): `mariadb-data`, `redis-data`, `app-storage` (storage/app: uploads, snapshots),
`app-logs` (storage/logs), `flarum-data`, `caddy-data` (certificates), `caddy-config`.

**Variables you may edit in `.env`**

| Variable | Meaning |
|---|---|
| `APP_DOMAIN` | public domain, no `https://` |
| `ACME_EMAIL` | Let's Encrypt contact; default forum admin e-mail |
| `UPLOAD_MAX_SIZE` | largest upload (PHP + nginx) |
| `MARIADB_ROOT_PASSWORD`, `MARIADB_DATABASE`, `MARIADB_USER`, `MARIADB_PASSWORD` | database server and the site's database |
| `REDIS_PASSWORD` | Redis password (used by Redis and the app) |
| `APP_KEY` | Laravel encryption key; never change it once data exists |
| `FORUM_ENABLED` | `true`: the site talks to the forum |
| `FLARUM_URL` | public forum URL, `https://<domain>/forum` |
| `FLARUM_INTERNAL_URL` | forum API base URL used by the app containers, `http://flarum:8000/forum` |
| `FLARUM_API_KEY` | forum master API key |
| `FORUM_DESIGN_TAG`, `FORUM_DRIVER_TAG`, `FORUM_LISTING_TAG` | forum tag slugs for the automatic discussions |
| `FLARUM_FORUM_TITLE`, `FLARUM_ADMIN_USER`, `FLARUM_ADMIN_EMAIL`, `FLARUM_ADMIN_PASSWORD` | first forum install |
| `FLARUM_DB_DATABASE`, `FLARUM_DB_USERNAME`, `FLARUM_DB_PASSWORD` | the forum's own database and user |
| `FLARUM_EXTENSIONS` | extra Flarum extensions to install |
| `MAIL_*` | outgoing mail |
| `STRIPE_*` | Stripe keys and webhook secrets |
| `DEV_UID`, `DEV_GID`, `COMPOSE_FILE` | development only |
