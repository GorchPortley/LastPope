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
- [13. Automatic updates](#13-automatic-updates)
- [14. Logs and troubleshooting](#14-logs-and-troubleshooting)
- [15. Local development](#15-local-development)
- [16. Reference](#16-reference)
- [17. Seasonal themes](#17-seasonal-themes)

---

## 1. What you are installing

Eight containers, started together by Docker Compose (`compose.yaml`):

```
Internet (ports 80 / 443)
   |
   v
caddy ........ HTTPS certificates (Let's Encrypt, automatic) and the front door
   |-- /forum*     ->  flarum ...... the forum (Flarum 1.8), served under https://YOURDOMAIN/forum
   |-- /ws/app/*   ->  reverb ...... live chat: the WebSocket server (Laravel Reverb), wss://YOURDOMAIN/ws
   '-- (rest)      ->  app ......... the website (Laravel + Wave + Filament, nginx + PHP-FPM)

queue ........ background worker: builds download ZIPs/PDFs, sends mail, talks to the forum
scheduler .... runs the app's scheduled jobs once a minute
mariadb ...... the database server: one database for the site, one for the forum
redis ........ cache, login sessions and the job queue
```

Only `caddy` is reachable from the internet. The database, Redis, the forum and Reverb never publish a port.

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
[Troubleshooting](#14-logs-and-troubleshooting)).

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
random passwords for the databases, Redis, the Laravel `APP_KEY`, the forum admin, the forum API
key and the id/key/secret of the live-chat server (Reverb). You can run it again at any time: secrets that are already set are never overwritten.

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

### Behind an existing web server (nginx, Apache, Webmin)

If this machine already runs a web server for other sites, it owns ports 80 and 443 and the
stack's Caddy cannot have them. Answer **y** to "Does another web server already use ports
80/443?" in `docker/setup.sh` (or run `docker/setup.sh --behind-proxy`). That sets in `.env`:

```dotenv
CADDY_CONFIG=Caddyfile.behind-proxy
CADDY_HTTP_BIND=127.0.0.1:8880
CADDY_HTTPS_BIND=127.0.0.1:8881
```

The stack then listens on `127.0.0.1:8880` only (not reachable from the internet), and your
web server forwards the domain to it and handles HTTPS. For nginx, create
`/etc/nginx/sites-available/shop.example.com` (Debian/Ubuntu; on other systems put it in
`/etc/nginx/conf.d/shop.example.com.conf`), or paste it via Webmin → Servers → Nginx Webserver:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name shop.example.com;

    # Large design / driver uploads (keep >= UPLOAD_MAX_SIZE in .env).
    client_max_body_size 1024m;

    # Live chat (WebSocket, served by the reverb container through the stack's Caddy). Its own location, so
    # no http-level "map" is needed. Without the Upgrade / Connection headers the chat stays offline.
    location /ws/app/ {
        proxy_pass http://127.0.0.1:8880;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
        proxy_buffering off;
    }

    location / {
        proxy_pass http://127.0.0.1:8880;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;
        proxy_request_buffering off;
        proxy_read_timeout 300s;
        proxy_send_timeout 300s;
    }
}
```

Enable it and get the certificate (certbot adds the HTTPS `server` block and the http → https
redirect to this file for you, and renews automatically):

```bash
sudo ln -s /etc/nginx/sites-available/shop.example.com /etc/nginx/sites-enabled/   # Debian/Ubuntu only
sudo nginx -t && sudo systemctl reload nginx
sudo apt install certbot python3-certbot-nginx      # once
sudo certbot --nginx -d shop.example.com
```

`X-Forwarded-Proto $scheme` matters: after certbot it sends `https`, which is how the site knows
to build `https://` links and secure cookies. If pages load without styles or log you out
straight away, this header is missing.

The `location /ws/app/` block is what makes the live chat work behind your own web server: a WebSocket
is an ordinary HTTP request that asks to be "upgraded", and nginx only passes that on when it is told to
(`proxy_http_version 1.1`, the `Upgrade` and `Connection "upgrade"` headers). `proxy_read_timeout` must be long,
otherwise nginx cuts every chat connection after 60 seconds of silence. certbot keeps your `location` blocks
when it adds HTTPS, so the block works over `wss://` too. In **Webmin** (Servers, Nginx Webserver) add it as
one more "location" of the site with the same lines. Check it with `sudo nginx -t && sudo systemctl reload nginx`, then
see [Live chat (Reverb)](#live-chat-reverb).

### Live chat (Reverb)

The messenger (`/dashboard/messages`, Chatify) needs a WebSocket server so new messages, the typing indicator
and "online" dots appear without reloading. It used to be a paid third-party service (pusher.com). Now it is
**Laravel Reverb**, a server that speaks the same protocol and runs in your own stack as the `reverb`
container: no account, no keys from a third party, no chat data leaves your server.

- Settings: `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` in `.env`, generated by
  `docker/setup.sh` (safe to run again: it only fills in what is empty). The key is public by design (the
  browser needs it); the secret never leaves the server. Everything else is wired by `compose.yaml`.
- The browser connects to `wss://YOURDOMAIN/ws/app/<key>`. Caddy forwards that path (and only that path) to
  the `reverb` container. The server API that the website uses to push messages (`/ws/apps/...`) is only
  reachable inside Docker (`http://reverb:8080`), never from the internet. The `/ws` prefix keeps the chat
  clear of the website's own URLs.
- Only pages of your own domain may open a connection (`REVERB_ALLOWED_ORIGINS`, default your domain).
- It is light: one small PHP process, fine for a community site (hundreds of simultaneous chats), and it
  stores nothing (connections live in memory; chat messages stay in the database). It starts after `app` is
  healthy. If it is down, everything else keeps working; messages are still saved, but the sender sees an
  error and the receiver has to reload to see them.
- Not using nginx in front? Nothing to do: Caddy passes WebSockets through by itself.

**Check it**

```bash
docker compose ps reverb                  # should be "healthy"
docker compose logs -f reverb
docker compose exec app curl -s http://reverb:8080/ws/up     # {"health":"OK"}
```

In the browser, open the messenger, press F12 → Network → "WS": a request to `/ws/app/<key>` should show
status `101 Switching Protocols`. A red `4009` or a failing request means one of the rows in
[Logs and troubleshooting](#14-logs-and-troubleshooting).

**Coming from the old pusher.com setup?** The old chat page printed the Pusher *secret* into the HTML of
every messenger page, so anyone could read it. If your `.env` ever contained `PUSHER_APP_SECRET`, delete all
`PUSHER_*` and `MIX_PUSHER_*` lines from `.env` and **rotate the secret** at pusher.com (App Keys, Reset secret,
or delete the app). To keep using pusher.com anyway, set `CHATIFY_DRIVER=pusher` and the `PUSHER_APP_*`
values in `.env` (not recommended).

## 7. First run: base data and the first admin user

A new database is empty. The site needs its base data (user roles, settings, the theme) before it can show
a single page, so on the **first start only** run:

```bash
docker compose exec app php artisan db:seed --force
```

`db:seed --force` only adds missing base data (roles, categories, settings, theme). It never deletes
or changes existing rows and never creates users, so it is safe to run again after every update.
Demo products and demo accounts are only added when `APP_ENV` is `local`, `demo` or `testing`; the
production containers run with `APP_ENV=production`, so you get none of that.

**A dev/demo site with sample content** (e.g. `dev.example.com`): put these in `.env`, run
`docker compose up -d` so the containers pick them up, then `db:seed --force`:

```dotenv
STACK_ENV=demo
DEMO_PASSWORD=pick-something-long   # every demo account gets this; the default "password" is public knowledge
```

The demo admin is `admin@sdlabs.test`. Error pages still stay non-debug in demo mode.

The demo content includes the forum: once the forum is up (section 8) run
`docker compose exec app php artisan forum:setup && docker compose exec app php artisan forum:seed-demo` and every
demo design, driver and listing gets a discussion with a few replies from the other demo users. (When the forum
is already connected, `db:seed --force` does this for you at the end.)

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

Marketplace listings follow the same rule (a discussion in the `marketplace` tag once a listing is active); this
includes the store listings of new drivers.

### Demo content for the forum

A dev/demo site (`APP_ENV=local` or `demo`, see section 7) starts with an empty forum. To fill it:

```bash
docker compose exec app php artisan forum:setup        # tags, once the forum is up
docker compose exec app php artisan forum:seed-demo
```

`forum:seed-demo` makes sure the tags exist, creates the discussion of every public design, driver and listing
that has none (written by its owner, right away instead of through the queue), and then adds 2 to 4 replies to each
thread from the other demo users: build and crossover questions on designs, measurement remarks on drivers,
condition and shipping questions with the seller's answers on listings. Run it again whenever you like: threads that
already have replies are left alone, so a second run adds nothing. It refuses to run when `APP_ENV` is not `local`
or `demo`, and `db:seed` runs it for you when the forum is switched on and reachable (otherwise `db:seed` prints
the command to run later).

Flarum lets each member post once every 10 seconds, so the first run against a real forum takes a few minutes: the
command takes turns between members and waits when the forum says "slow down" (`FORUM_THROTTLE_WAIT`). To make it
instant on a throw-away demo forum, open the forum administration, **Permissions**, and give the *Member* group
"Post without throttling".

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
  - `charge.dispute.created` (a buyer's bank opened a dispute: the order's payouts are put on hold and the admins are told)
  - `charge.dispute.closed` (the outcome, won or lost, is recorded on the case)
- Copy the endpoint's *Signing secret* (`whsec_...`) into `.env` as `STRIPE_MARKETPLACE_WEBHOOK_SECRET`.

**10.4 Webhook endpoint 2: connected accounts**

- Click *Add endpoint* again and choose **Listen to events on Connected accounts**.
- Endpoint URL: `https://shop.example.com/stripe/connect/webhook`
- Event to send: `account.updated`
- Copy this endpoint's own signing secret into `.env` as `STRIPE_CONNECT_WEBHOOK_SECRET`.

The two endpoints have **different** signing secrets. The older `STRIPE_WEBHOOK_SECRET` belongs to the
template's subscription billing and is separate from both. While a secret is empty its endpoint answers `503`
and ignores everything, so a mistyped URL or secret never fulfils an order; Stripe retries failed deliveries
for days, and duplicates are harmless.

`STRIPE_PUBLISHABLE_KEY` and `STRIPE_SECRET_KEY` are the same names the template's subscriptions use, so one
set of keys serves both. Order e-mails go through the `queue` container and the hourly `marketplace:auto-complete`
job (completes parcels that were shipped more than `MARKETPLACE_AUTO_COMPLETE_DAYS` ago, **releases the payouts that
are due** and retries failed ones) through the `scheduler` container, so keep both running.

**When sellers are paid** (`.env`, see also [section 16](#16-reference)):

| Variable | Default | Meaning |
|---|---|---|
| `MARKETPLACE_PHYSICAL_PAYOUT_TRIGGER` | `shipped_delay` | `shipped_delay`: physical items are paid out `MARKETPLACE_PHYSICAL_PAYOUT_DELAY_DAYS` days after the seller marks them shipped (the buyer's protection period). `shipped`: as soon as they are marked shipped. `paid`: as soon as the buyer pays |
| `MARKETPLACE_PHYSICAL_PAYOUT_DELAY_DAYS` | `14` | the delay of `shipped_delay` |
| `MARKETPLACE_ISSUE_WINDOW_DAYS` | `14` | buyers can report a problem until this many days after shipping (digital items: after payment); an order that is not shipped yet can be reported at any time |
| `MARKETPLACE_ISSUE_ESCALATION_DAYS` | `3` | buyer and seller get this long to agree before either may ask the admins to decide |

Digital items (designs) are paid out right after the payment. Confirming receipt does not pay a seller earlier.
A seller order with an unresolved problem report is never paid out and does not complete by itself; resolving the
report releases it with the next hourly run (or at once). Restart the `app queue scheduler` containers after you
change these values.

**Problem reports ("Report a problem")**: buyers open one per seller order from their order page; the seller answers or
offers a full refund, a partial refund or a return for a refund; if they cannot agree, either side can ask the admins
to decide. Orders sold by SDLabs itself (store orders) go to the admins straight away. Admins work the queue in
**Admin > Marketplace > Problem reports** (the badge counts the cases waiting for a decision) and can reply, add
internal notes, refund fully or partly, or close without action. A refund before the payout shrinks the payout (a full
refund cancels it; the platform fee stays on partial refunds and is waived on full ones); after the payout that amount is
taken back from the seller's transfer. Photos attached to a report are stored on the private disk.

**Bank disputes (chargebacks)**: when a buyer's bank disputes a payment, Stripe sends `charge.dispute.created`. Every
seller order of that order gets an escalated case, their payouts stay on hold and the admins get an e-mail. Send your
evidence in the Stripe dashboard before the due date; Stripe sends `charge.dispute.closed` with the outcome, which is
recorded on the case. A lost dispute keeps the payouts blocked until an admin chooses **Recover from seller** (reverses the
transfer, or cancels a payout that was not made yet).

**10.5 Apply and test**

```bash
docker compose up -d app queue scheduler        # re-read .env
```

In the Stripe dashboard use *Send test webhook* on each endpoint; the response must be `200`. Then make
a test purchase with card `4242 4242 4242 4242` (any future date, any CVC). To test locally without a public URL
use the Stripe CLI container, see [Local development](#15-local-development).

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
`app` container does it on start, `php artisan migrate --force`). Migrations only ever add: existing ones are never
edited, so a running site with data keeps working. Visitors may see a short interruption (a few seconds). Take a
[backup](#11-backups-and-restore) first when the update contains database changes.

Housekeeping: `docker image prune -f` removes old image layers after an update.

**An update that adds a secret** (the live-chat `REVERB_*` ones were the first) makes `docker compose` stop with
`REVERB_APP_ID is empty - run docker/setup.sh first`. Run `docker/setup.sh --secrets-only` once: it adds only
what is missing and leaves every other line of `.env` exactly as it is (plain `docker/setup.sh` also does it, but
may ask questions). Then repeat the update command. The [automatic updater](#13-automatic-updates) does this step
by itself.

**The update that introduces the live chat** is the one exception for a server with automatic updates: the updater
that is running is the old one and does not know that step yet, so that single deploy is refused with "docker
compose configuration is invalid" (the running site is not touched). Do it by hand once, then everything is
automatic again:

```bash
cd ~/lastpope
git pull
docker/setup.sh --secrets-only
docker compose up -d --build
```

Prefer not to log in for every update? The server can check GitHub itself, back up the database and update
when the tests are green: see [13. Automatic updates](#13-automatic-updates).

Software versions are pinned in the repository, so nothing changes by itself: MariaDB 11.8, Redis 8.2,
Caddy 2.11, PHP 8.3 with nginx (serversideup/php v5), Flarum 1.8. A new version arrives only when the
repository changes the pinned tag. Do not jump MariaDB across major versions without reading the MariaDB
upgrade notes.

Apply operating-system security updates on the server regularly (`sudo apt update && sudo apt upgrade`, or enable
`unattended-upgrades`).

## 13. Automatic updates

Instead of logging in and typing `git pull` after every change, the server can look after itself: **every
2 minutes it asks GitHub whether there is new code, and deploys it once the tests have passed.** It *pulls*;
GitHub never connects to your server, so you open no port on your router and give GitHub no SSH key.

### What happens on each push

1. You push a commit to your branch on GitHub.
2. GitHub Actions runs the tests (`.github/workflows/tests.yml`; it runs on every branch). This takes a few minutes.
3. At most 2 minutes after the tests turn green, the server notices the commit.
4. It dumps both databases to a backup file, pulls the code, rebuilds the images, restarts what changed, runs
   the database migrations and the (harmless, repeatable) base-data seeding. If the new code needs a secret that
   `.env` does not have yet (the live-chat `REVERB_*` ones, for example), it adds just those first with
   `docker/setup.sh --secrets-only`: that mode never asks anything, never changes or removes an existing line,
   and writes only the names of what it added to the deploy log, never the values. (`--dry-run` only reports it.)
5. It waits until the site and the forum answer. Then you get a message: `Deployed 1a2b3c4: <your commit title>`.
6. If the new version does **not** become healthy, the old code is put back automatically and you get
   `Deploy of ... FAILED ... Rolled back`. If the tests fail, nothing is deployed and you are told once.

The visitors' side: the site is unreachable for a few seconds while the app container is replaced.

### How it works

- `systemd timer` (`lastpope-deploy.timer`): every 2 minutes it starts
- `docker/deploy/auto-deploy.sh` (as root, so it may use Docker):
  - `git fetch`: nothing new? Stop, silently. This is what happens almost every time.
  - Is the server's copy untouched (no hand edits, no commits that GitHub lacks)? If not: refuse and tell you once.
  - Did the GitHub **Tests** workflow succeed for exactly this commit? Still running or not started: look again in
    2 minutes. Failed: do not deploy, tell you once.
  - Backup: both databases to `backups/<time>-<old commit>.sql.gz`. No backup, no deploy.
  - `git merge --ff-only`, `docker compose build`, `docker compose up -d`, `php artisan migrate`, `php artisan db:seed`.
  - Health check (up to 3 minutes): `app`, `flarum`, `queue`, `scheduler` healthy and the site answers on Caddy's local port.
  - Healthy: notify, remove old images. Unhealthy: `git reset --hard <old commit>`, rebuild, restart, notify.

Only one run happens at a time (a lock), so a long build never overlaps with the next check.

### Install (once)

You need the stack running already (sections 5 to 7) from a `git clone`, on the branch you want to follow
(`git branch --show-current` shows it). In the project folder (here `/SDLabs`):

```bash
cd /SDLabs
git pull                                  # get the updater scripts
sudo docker/deploy/install.sh             # creates the timer and docker/deploy/deploy.env
sudo docker/deploy/auto-deploy.sh --dry-run   # shows what it would do right now; changes nothing
```

`install.sh` runs the updater as root, because you use `sudo` for Docker anyway. To use a normal user
instead: `sudo docker/deploy/install.sh --user alice` (the user must be in the `docker` group and own the folder).

Watch it work:

```bash
journalctl -u lastpope-deploy -f          # live (Ctrl+C stops watching, not the updater)
systemctl list-timers lastpope-deploy.timer   # when it ran last, when it runs next
less /var/log/lastpope-deploy.log         # history of everything it did
```

Pause and resume: `sudo systemctl stop lastpope-deploy.timer` / `sudo systemctl start lastpope-deploy.timer`.
Remove it: `sudo docker/deploy/install.sh --uninstall` (your settings and backups stay).
Check right now, without waiting: `sudo docker/deploy/auto-deploy.sh`.

It needs `git`, `curl`, `gzip`, `flock` (all standard) and `python3` (standard on Ubuntu and Debian; used only to read
GitHub's answer; `jq` or `php` work too).

### The golden rule: the server's copy of the code is read-only

> **Server-only changes go in `.env` or `compose.override.yaml`. Never edit tracked files on the server.**

Every deploy is a fast-forward of the server's checkout to the commit on GitHub. If you edit a tracked file by
hand, or commit on the server, the updater refuses to deploy (and tells you once) instead of overwriting your work.

- Passwords, domain, limits: `.env` (not in git).
- A different setting for compose that only this server needs: create `compose.override.yaml` next to `compose.yaml`.
  Docker Compose merges it automatically; git ignores it. Example for a small home server:

  ```yaml
  services:
    app:
      environment:
        PHP_FPM_PM_MAX_CHILDREN: "4"
  ```
- Got the "changed by hand" warning? See what changed with `git status` and `git diff`, then either move it into
  `.env` / `compose.override.yaml` and `git checkout -- <file>`, or park it with `git stash`.
- Got the "commits that are not on origin" warning? `git log origin/main..HEAD` shows them. Push them from your
  computer if they are wanted, otherwise `git reset --hard origin/main`.

### Database changes must be new migration files

Every deploy runs `php artisan migrate --force` on the live data. So (see `docs/PLAN.md`, section 7): **never edit a
migration that already exists; every schema change is a new migration file.** An edited old migration is not run
again, so the live database would silently differ from your test one. Also remember that a rollback restores the old
*code* only. Migrations are **not** undone, so keep changes additive (add columns and tables first; remove old ones in
a later release when no running code needs them).

### Settings (`docker/deploy/deploy.env`)

All optional; the defaults work. `install.sh` creates the file from `deploy.env.example`, which explains every line.
Changes apply on the next run (every 2 minutes), no restart needed.

| Setting | Default | Meaning |
|---|---|---|
| `DEPLOY_BRANCH` | the branch checked out on the server | branch to follow |
| `REQUIRE_CI` | `true` | only deploy commits whose GitHub tests passed (`false`: deploy every push at once) |
| `CI_WORKFLOW` | `tests.yml` | workflow file under `.github/workflows/` that must pass |
| `GITHUB_REPO` | from `git remote get-url origin` | `owner/name` |
| `GITHUB_TOKEN` | empty | only for a private repository, see below |
| `NOTIFY_URL` | empty | where to send messages, see below |
| `BACKUP_DIR`, `BACKUP_KEEP` | `<project>/backups`, `10` | where the pre-deploy database dumps go and how many to keep |
| `HEALTH_URL` | `http://127.0.0.1:<Caddy port from .env>/` | must answer 200 to 399 after a deploy (Host header = `APP_DOMAIN`) |
| `HEALTH_TIMEOUT` | `180` | seconds to wait for the containers to become healthy |
| `ROLLBACK_ON_FAILURE` | `true` | put the old code back when the new version is unhealthy |

If `STACK_ENV` in `.env` is `demo` (or `local`), the updater skips `db:seed`, because there it would add demo users
and content. On a normal production server it runs on every deploy and only inserts base data that is missing.

### Private repository: letting the server see the test results

For a public repository you need nothing. For a private one GitHub hides the test results from anonymous visitors, so
create a read-only token:

1. On GitHub: your profile picture, **Settings**, **Developer settings**, **Personal access tokens**, **Fine-grained tokens**,
   **Generate new token**.
2. Name it `lastpope-server`, pick an expiry (a year is fine; the updater tells you when it stops working).
3. **Repository access**: *Only select repositories*, and choose this one repository.
4. **Permissions**, **Repository permissions**, **Actions**: set **Read-only**. Leave everything else at *No access*
   (GitHub adds "Metadata: read-only" itself).
5. **Generate token** and copy it (it is shown once). Put it in `docker/deploy/deploy.env`:
   `GITHUB_TOKEN=github_pat_...`. The file is readable by root only and never committed.

The token can only read test results. To *fetch the code* of a private repository the server also needs read access.
The usual way is a read-only deploy key, created as root because the updater runs as root:

```bash
sudo ssh-keygen -t ed25519 -N '' -f /root/.ssh/lastpope_deploy
sudo cat /root/.ssh/lastpope_deploy.pub     # GitHub: the repository, Settings, Deploy keys, Add (leave "write" off)
printf 'Host github.com\n  IdentityFile /root/.ssh/lastpope_deploy\n  IdentitiesOnly yes\n' | sudo tee -a /root/.ssh/config
sudo ssh-keyscan github.com | sudo tee -a /root/.ssh/known_hosts
sudo git -C /SDLabs remote set-url origin git@github.com:YOURNAME/LastPope.git
sudo git -C /SDLabs fetch                   # must work without asking anything
```

### Messages on your phone (ntfy)

[ntfy](https://ntfy.sh) is a free service that turns an HTTP request into a phone notification. No account needed.

1. Install the **ntfy** app (Android: Google Play or F-Droid; iPhone: App Store).
2. Choose a topic name that nobody can guess, for example `lastpope-` plus random letters:
   `echo lastpope-$(openssl rand -hex 8)`. Anyone who knows the name can read and post to it.
3. In the app: **+**, subscribe to that topic on `ntfy.sh`.
4. In `docker/deploy/deploy.env`: `NOTIFY_URL=https://ntfy.sh/lastpope-3f9a2c1d8b7e4a65` (your topic).
5. Test it: `curl -d "hello from the server" https://ntfy.sh/lastpope-3f9a2c1d8b7e4a65`.

For Discord, create a webhook (channel settings, Integrations, Webhooks) and use its URL as `NOTIFY_URL`; the updater
detects `discord.com/api/webhooks` and sends the format Discord expects.

You are messaged when: a deploy succeeded, a deploy failed or was rolled back, the tests failed for a new commit, the
server's copy has hand edits or local commits (deploy refused), the backup failed, or no test run showed up. Each
problem is reported **once** per commit, not every 2 minutes.

### Deploy a specific commit, or go back

- **Normal way back (recommended):** on your computer `git revert <bad commit>` and push. The revert goes through the
  tests, the backup and the deploy like any other change, and your history shows what happened.
- **Right now, without the tests:** `sudo docker/deploy/auto-deploy.sh --force` deploys the newest commit even if its
  tests have not passed (or failed). It also retries a commit that failed before.
- **Pin an exact commit on the server** (emergency):

  ```bash
  cd /SDLabs
  sudo systemctl stop lastpope-deploy.timer
  sudo git fetch origin
  sudo git checkout --detach 1a2b3c4          # the commit you want; auto-deploy now stays paused by itself
  sudo docker compose up -d --build
  ```

  To resume automatic updates: `sudo git checkout main` (your branch) and `sudo systemctl start lastpope-deploy.timer`.

### Backups made by the updater, and restoring one

Before every deploy the updater writes `backups/YYYYMMDD-HHMMSS-<previous commit>.sql.gz` (in `/SDLabs/backups` unless
you set `BACKUP_DIR`): both databases, readable by root only. The newest `BACKUP_KEEP` (10) are kept, which with
frequent pushes is only the last few deploys. It is **not** the full backup of [section 11](#11-backups-and-restore):
uploaded files, forum files and `.env` are not included. Copy the folder off the server now and then.

To go back to the state before a deploy (this **replaces** the site and forum databases with the dump; changes made on
the site since then are lost):

```bash
cd /SDLabs
sudo systemctl stop lastpope-deploy.timer                   # no updates while you work
ls -1 backups/                                              # pick the file; the commit in its name is the code that matches it
sudo git checkout --detach 1a2b3c4                          # that commit (rebuild below)

# empty both databases, then load the dump (it recreates them)
sudo docker compose exec -T mariadb sh -c \
  'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -e "DROP DATABASE IF EXISTS \`$MARIADB_DATABASE\`; DROP DATABASE IF EXISTS \`$FLARUM_DB_DATABASE\`"'
gunzip < backups/20261022-031502-1a2b3c4.sql.gz | sudo docker compose exec -T mariadb sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD"'

sudo docker compose up -d --build
```

Then, when you are ready for updates again: `sudo git checkout main` and `sudo systemctl start lastpope-deploy.timer`.

### Problems

| You see (journal, log or message) | What it means and what to do |
|---|---|
| `Waiting for CI ...` | Normal: the tests are queued or running. It tries again in 2 minutes. |
| `No CI run appeared for ... within 45 minutes` | The Tests workflow did not start. Open the repository's **Actions** tab (are Actions enabled? is `tests.yml` there?). Deploy by hand with `--force` if you must. |
| `NOT deploying ...: CI failed` | The tests failed. Fix and push; the next commit is picked up automatically. |
| `Could not check CI ... HTTP 404` | Wrong `GITHUB_REPO`/`CI_WORKFLOW`, or a private repository without `GITHUB_TOKEN`. `HTTP 401`: the token expired or is wrong. `HTTP 403`: rate limit, or the token lacks *Actions: read*. |
| `tracked files were changed by hand` / `not on origin` | See the golden rule above. |
| `git fetch failed` | No internet, GitHub down, or the server has no access to the repository. Try `sudo git -C /SDLabs fetch`. It keeps trying. |
| `database backup failed, nothing was changed` | The stack is not running (`docker compose ps`) or the disk is full (`df -h`). Nothing was touched; it retries every 2 minutes. |
| `the image build failed` | Details are in the log and in `.git/lastpope-deploy/last-deploy.log`. The running site was not touched. Common cause: GitHub download limits during `composer install` ("Could not authenticate against github.com"): put a token in `COMPOSER_AUTH` in `deploy.env` (see `deploy.env.example`) or see [Troubleshooting](#14-logs-and-troubleshooting). Fix and push, or `--force` to retry the same commit. |
| `FAILED ... Rolled back` | The new version did not become healthy; the old code runs again. Read `docker compose logs app` for the reason. The commit is skipped until you push a new one. |
| `... NOT healthy either. The site may be down` | Even the old code does not start. Look at `docker compose ps` and `docker compose logs`, then restore the backup named in the message if the database is the problem. |
| Nothing happens at all | `systemctl list-timers lastpope-deploy.timer` (is it listed?), `journalctl -u lastpope-deploy -n 50`, and `sudo docker/deploy/auto-deploy.sh --dry-run`. |

**Security:** whatever lands on the followed branch and passes the tests runs on your server with root rights over Docker.
Protect your GitHub account with two-factor authentication, give push access only to people you trust, and keep
`REQUIRE_CI=true`. The updater itself only talks to GitHub (and your `NOTIFY_URL`); the image builds download packages from the usual places.

## 14. Logs and troubleshooting

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
| `variable is not set` / `... is empty - run docker/setup.sh first` when starting | `.env` is missing a value: run `docker/setup.sh --secrets-only` (new secrets only; changes nothing else) or `docker/setup.sh` again. |
| Build stops with `Could not authenticate against github.com` | GitHub limits anonymous downloads. Create a token at <https://github.com/settings/tokens> (a classic token with **no** scopes is enough) and build once with `COMPOSER_AUTH='{"github-oauth":{"github.com":"ghp_yourtoken"}}' docker compose build`. |
| Build is killed / `npm` or `composer` exits with "Killed" | Out of memory. Add swap (step 4.2) or use a larger server for the build. |
| `Access denied for user 'lastpope'` after changing a DB password in `.env` | MariaDB only reads passwords when its data volume is created. Change it inside the database: `docker compose exec mariadb mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -e "ALTER USER 'lastpope'@'%' IDENTIFIED BY 'the-new-password'"`; for the forum user re-run `docker compose exec mariadb bash /docker-entrypoint-initdb.d/10-flarum.sh`. |
| Forum shows an error page or never gets healthy | `docker compose logs flarum`. The first start installs extensions from packagist.org, so the server needs outbound internet. |
| Forum login / "not logged in to the forum" | `FORUM_ENABLED=true`, `FLARUM_API_KEY` set, and `FLARUM_URL` equals `https://<domain>/forum`. Then `docker compose up -d app queue scheduler`. |
| Designs/drivers get no forum discussion | `docker compose exec app php artisan forum:setup` shows what is wrong (unreachable forum, rejected API key, missing tag). Details are in `docker compose logs queue`. "The number of primary tags must be 1" means the tag exists but is a secondary tag: in the forum administration, Tags, drag it into the primary list. |
| Chat shows "offline" / no live updates, browser WS request fails or is `4009` | `docker compose ps reverb` and `docker compose logs reverb`. `4009 Origin not allowed`: the page is not served from `APP_DOMAIN` (add the host to `REVERB_ALLOWED_ORIGINS`). Request ends with `400`/`500`/`502` and you use your own nginx: the `location /ws/app/` block with the `Upgrade` headers is missing or not inside the HTTPS server (see [Behind an existing web server](#behind-an-existing-web-server-nginx-apache-webmin)). |
| Sending a chat message shows an error but the message is saved | The app cannot reach the push server: `docker compose logs reverb`, then `docker compose up -d reverb`. Check that `REVERB_APP_ID/KEY/SECRET` in `.env` did not change since the last `docker compose up -d`. |
| Uploads fail for big files | Raise `UPLOAD_MAX_SIZE` in `.env` and `docker compose up -d app`. (Caddy allows up to 1 GB per request.) |
| Disk is full | `docker system df`, then `docker image prune -f` and `docker builder prune -f`. Logs are rotated automatically (10 MB x 5 per container). |

**Starting from scratch** (this deletes the database, uploads and certificates; only do it on a test setup):

```bash
docker compose down -v
```

## 15. Local development

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
| `wss://localhost/ws` | live chat: the `reverb` service, reached through Caddy like in production (restart it after PHP changes: `docker compose restart reverb`) |

Your checkout is mounted into the containers, so PHP changes are live. After editing a job class run
`docker compose restart queue`. Run the tests with `docker compose exec app php artisan test` (or on your host
as usual). Without Docker, `php artisan serve` with SQLite still works as before; for the live chat there
also put some `REVERB_APP_ID`, `REVERB_APP_KEY` and `REVERB_APP_SECRET` values in `.env` and run
`php artisan reverb:start` in a second terminal (the browser then connects to `ws://localhost:8080/ws`; use
the same host name in the address bar as in `APP_URL`, or set `REVERB_ALLOWED_ORIGINS`).

**Stripe webhooks while developing** (needs `STRIPE_SECRET_KEY=sk_test_...` in `.env`):

```bash
docker compose --profile stripe up -d stripe-cli
docker compose logs stripe-cli     # shows:  Ready! Your webhook signing secret is whsec_...
```

The Stripe CLI forwards `/stripe/marketplace/webhook` and `/stripe/connect/webhook` to the app. Put the
`whsec_...` it printed into **both** `STRIPE_MARKETPLACE_WEBHOOK_SECRET` and `STRIPE_CONNECT_WEBHOOK_SECRET`
(the CLI signs all forwarded events with that one secret), then `docker compose up -d app queue`.

## 16. Reference

**Services**

| Service | Image | Notes |
|---|---|---|
| `caddy` | `caddy:2.11.7` | ports 80, 443/tcp, 443/udp; config `docker/caddy/Caddyfile` |
| `app` | built from `docker/app/Dockerfile` on `serversideup/php:8.3-fpm-nginx-v5.0.1` | internal port 8080; runs migrations + caches on start |
| `queue` | same image | `php artisan queue:work --tries=3` |
| `scheduler` | same image | `php artisan schedule:work` |
| `reverb` | same image | `php artisan reverb:start`, internal port 8080, WebSocket server for live chat (Caddy: `/ws/app/*`) |
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
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | live chat (Reverb): app id, public key, secret; made by `docker/setup.sh` |
| `REVERB_ALLOWED_ORIGINS` | optional: hosts allowed to open a chat WebSocket (default `APP_DOMAIN`) |
| `CHATIFY_DRIVER` | optional: `pusher` to use pusher.com instead of Reverb (then fill in `PUSHER_APP_*`) |
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
| `MARKETPLACE_*` | fee, payout timing, problem-report windows and stock reservation, see [section 10.4](#10-stripe-setup) and `.env.example` |
| `DEV_UID`, `DEV_GID`, `COMPOSE_FILE` | development only |

## 17. Seasonal themes

The public site (not the admin panel) can wear a seasonal look: re-coloured header, footer, buttons, badges
and links, plus a small decoration along the header and, for winter, gently falling snow. **An admin switches it
by hand; nothing changes by date.**

**Switching a season**: log in as admin, open **Admin > Settings > Appearance**, click a season (each card shows
its colours), press **Save**. It is live on the next page load for everybody. **Preview** (the button at the top,
or the link on a card) opens the home page in the selected season for you only: the same works on any public page
by adding `?preview_season=halloween` to the address, and is ignored for everybody who is not an admin.
Choose **Default** to go back to the normal purple look. The choice is stored in the Wave setting `site.season`
(Admin > Settings), so it survives updates and is part of the database backup.

**How the colours work**: the theme never hard-codes the purple. Tailwind classes `brand-50 ... brand-950` (main
colour: header, footer, buttons, badges, links), `accent-*` (hover highlight on the dark header and footer, cart
badge), `link-*` (link hover, focus rings, carousel dots), `bg-header` and `bg-footer` read CSS variables holding
RGB channels (`--brand-600: 147 51 234`). The defaults are the `:root` block in
`resources/themes/blank/assets/css/app.css`; a season only overrides some of them on
`<html data-season="halloween">`. The layouts print those overrides in a small `<style>` block, so a new season
needs no rebuild of the CSS.

**Adding a season** (for example Easter):

1. Add one entry to `config/seasons.php`: a `label`, a `description`, the `colors` (hex values for the shades of
   `brand`, optionally `accent` and `link`, and optionally `header-bg` / `footer-bg`; anything left out keeps
   the default), a `garnish` name (or `null`) and an `effect` (`snow` or `none`). Re-use one of the palettes at the top of
   the file or add one.
2. Only if it has a header decoration: create `resources/themes/blank/components/seasonal/garnish/<name>.blade.php`
   with an inline SVG (copy one of the existing six; no external images). Only if it needs a new effect or special
   styling: add the CSS to `resources/themes/blank/assets/css/seasons.css` (rules for one season go under
   `html[data-season="<key>"]`).
3. Run `npm run build` when you touched `seasons.css` (or the Tailwind classes of a garnish); a config-only season needs
   nothing.
4. Run `php artisan test tests/Feature/Seasons`. It checks every season in the config automatically: valid hex
   colours, the garnish view exists, and WCAG AA contrast (4.5:1) for white text on the brand shades, header and
   footer, and for the footer and badge text. If a contrast check fails the message names the pair; pick a darker shade.

The snow layer ignores the pointer, sits below dropdowns and dialogs, and is hidden for visitors whose system asks for
reduced motion.

**Front-end libraries are bundled.** Chart.js, Flowbite (JS and CSS), the Phosphor icon font and the Alpine focus
plugin are installed from npm with pinned versions (`package.json`) and built by `npm run build`; the pages no
longer load them from a CDN, so the charts and icons also work offline or behind a strict firewall. (Paddle, Stripe,
and the profile page's cropper still use their own hosts.) After changing a version, run `npm install` and
`npm run build`.
