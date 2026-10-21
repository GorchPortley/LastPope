# LastPope build plan

LastPope is the rebuild of **SDLabs** (a speaker-design sharing site), with a marketplace on top.
This file is the single source of truth for everyone working on the rebuild. Read it before changing code.

## 0. What exists (inherited from SDLabs)

Stack: Laravel + the Wave starter kit (vendored in `/wave`, MIT), Filament 3 admin, Livewire 3 + Volt, Laravel Folio
pages in `resources/themes/blank/pages` (active theme is `blank`; `anchor` is an unused leftover), Tailwind + Vite, Pest.

Features to keep working:
- Auth/profiles/roles/admin/notifications/API keys (Wave + devdojo/auth + spatie/permission).
- Designs and Drivers: CRUD in `resources/themes/blank/pages/dashboard/{designs,drivers}/index.blade.php`
  (Volt components using Filament tables + forms), public pages `pages/designs/...`, `pages/drivers/...`.
- File uploads per design / per driver / per design component position (frequency, impedance, other, enclosure,
  electronics, card images, rich-text attachments).
- Snapshots: versioned ZIP (+ PDF for designs via dompdf, view `resources/views/pdf/Design.blade.php`).
- Frequency/impedance viewers (`app/Http/Livewire/*FrequencyResponseViewer.php`) — parse FRD/ZMA, complex sum.
- Reviews (digikraaft/laravel-review-rating), banners, Scout search, Chatify messaging.
- Flarum forum at `/forum`: login sync (`app/Listeners/UserLoggedIn.php`), auto-discussion per design/driver
  (`app/Observers/*`).

Known defects found during analysis (fix them):
- Paid design files live on the **public** disk → anyone with the URL can download them.
- Cart/purchase code is half-built: no migrations for carts/cart_items/design_purchases; checkout calls a
  nonexistent `charge()`.
- `2025_11_10_*` enum→string migrations are broken (missing `DB` import, `design` table typo, statements run inside
  the Blueprint closure). `Design.php` uses `Log` without importing it.
- `env()` used outside config (breaks under `config:cache`); observers use `auth()->user()` (breaks in
  seeders/queues); forum passwords stored in plain text.
- Several runtime files are gitignored (`config/chatify.php`, `config/lfm.php`, `app/Models/Ch*.php`) so a fresh
  clone does not boot.
- Scout defaults to Algolia (no credentials) — use the `database` driver.

## 1. Owner decisions

- **What is sold:** designs (digital), driver data packs (digital), new physical drivers (shops/manufacturers),
  used drivers (user-to-user).
- **Payments:** Stripe Connect **Express**; one checkout for a multi-seller cart using **separate charges and
  transfers**; platform commission.
- **Forum:** keep Flarum, run it in docker-compose, keep SSO + auto-discussions.
- **Data:** fresh start. Edit the original create-migrations freely (no data-preserving alter migrations needed).
  Provide factories + seeders that produce a rich demo/dev dataset.
- **Hosting:** Docker Compose on a VPS, Caddy with automatic HTTPS; owner points a domain's A record at it.

## 2. Conventions (all work)

- PHP 8.3. Laravel 12 if the upgrade is feasible (Laravel 11 is out of security support), Filament 3, Livewire 3, Pest.
- **Money** is integer cents in columns named `*_cents`. Single currency `config('marketplace.currency')`
  (default `usd`). Global helper `money(int $cents): string` in `app/helpers.php` (composer `autoload.files`) using
  `Illuminate\Support\Number::currency`. Filament money fields use `App\Filament\Forms\MoneyInput::make('price_cents')`
  (displays dollars, stores cents).
- **Disks:** `public` only for images shown on pages (card images, listing photos, rich-text attachments). All design
  and driver source files and all snapshot ZIP/PDFs go on the `private` disk (root `storage/app/private`). Never build
  public URLs to private files; serve them through controllers that authorize.
- **Storage layout:** `designs/{storage_key}/...` and `drivers/{storage_key}/...`; `storage_key` is a UUID column
  filled when the create form mounts (Hidden field default `Str::uuid()`), so renaming never breaks paths. Snapshots:
  `designs/{storage_key}/snapshots/{slug}-{version}.zip`.
- No `env()` outside `config/`. No `auth()` inside observers, jobs, models or services — pass the user explicitly.
- External services (Stripe, Flarum) are behind interfaces bound in a service provider, with fakes for tests.
  **No network in tests.** `config('forum.enabled')` defaults to false in testing.
- Slow work (snapshots, forum calls, mail) runs in queued jobs. `QUEUE_CONNECTION=database` locally, `redis` in docker.
- Enums: PHP backed enums in `app/Enums`, stored as strings, cast on models.
- Tests: Pest, under `tests/Feature/...`. `php artisan test` must pass before handing work back.
- Match the surrounding code style. Reuse existing Blade components (`x-app.container`, `x-app.heading`,
  `x-button`, `x-layouts.app`, `x-layouts.marketing`, …) and the existing Tailwind look incl. dark mode.
- Agents do **not** commit unless their brief says so; they report changed files and anything left undone.

## 3. Marketplace domain

### 3.1 Products — implement `App\Contracts\Purchasable`

| Model     | Kind                      | Price column                 | Delivered as                    |
|-----------|---------------------------|------------------------------|---------------------------------|
| `Design`  | digital                   | `designs.price_cents`        | design snapshots (all versions) |
| ~~`Driver`~~ | ~~digital driver data pack~~ (removed in R1, see §6: driver data is free) | — | driver snapshots are public downloads |
| `Listing` | physical, new or used     | `listings.price_cents`       | shipped by the seller           |

`Driver` keeps `msrp_cents` (reference retail price, informational) and `link` (external "buy from" link);
SDLabs sells new units of a driver as a **store listing** (§6).

```php
interface Purchasable
{
    public function purchasableTitle(): string;
    public function purchasablePriceCents(): int;
    public function getSeller(): ?User;          // Design->designer, Driver->creator, Listing->seller
    public function isDigital(): bool;
    public function requiresShipping(): bool;
    public function isAvailableForPurchase(): bool; // active + seller can be paid (or price 0) + deliverable/stock
}
```

Register a (non-enforcing) morph map: `design`, `driver`, `listing`. Do not map `user` (Wave/spatie store the class).

A paid item can only be made active if its seller `canReceivePayouts()` or `isPlatformSeller()`; a paid digital item
also needs at least one snapshot. Free items are always allowed.

### 3.2 Listings (physical)

`listings`: id, uuid, seller_id→users, driver_id→drivers nullable (catalog link), title, slug, condition
(`ListingCondition`: new, open_box, used_like_new, used_good, used_fair, for_parts), description (longText),
photos (json, public disk), price_cents, quantity (units available), units_per_lot (default 1; 2 = sold as a pair),
ships_from_country (ISO-3166 alpha-2), shipping_domestic_cents, shipping_international_cents (nullable = domestic
only), additional_item_shipping_cents (default 0), handling_days (default 3), returns_accepted (bool),
return_policy (text nullable), status (`ListingStatus`: draft, active, paused, sold_out, removed), timestamps,
soft deletes. "New" vs "used" is derived from condition (`new`/`open_box` = new).

### 3.3 Sellers

`seller_profiles`: id, user_id (unique), shop_name, slug (unique), bio, country (alpha-2), stripe_account_id
(unique nullable), charges_enabled, payouts_enabled, details_submitted (bools), commission_bps (nullable override),
timestamps.
- `User::canReceivePayouts()`: profile exists and charges_enabled && payouts_enabled.
- `User::isPlatformSeller()`: user has the `admin` role → revenue stays on the platform, no transfer.

### 3.4 Cart, addresses, shipping

- `carts` (id, user_id unique) and `cart_items` (cart_id, purchasable morph, quantity default 1, unit_price_cents
  snapshot; unique cart+purchasable). Guests are sent to login.
- Rules: digital qty is always 1; can't buy your own items; can't add a digital item you already have access to;
  a free digital item skips the cart and grants an entitlement immediately; listing qty ≤ available quantity.
- `addresses`: user_id, name, line1, line2, city, state, postal_code, country, phone, is_default. Required at checkout
  only when the cart has shippable items.
- Shipping per line: base = buyer country == `ships_from_country` ? domestic : international (null → not shippable,
  checkout blocked with a clear message); line shipping = base + (qty − 1) × additional_item_shipping_cents.
  Seller shipping = sum of its lines.
- `App\Services\Marketplace\CartService`: add, updateQuantity, remove, clear, `summary(?Address)` → lines grouped
  by seller with subtotal/shipping/fee/total and validation errors.

### 3.5 Orders

- `orders`: number (unique, human-readable e.g. `SDL-7KQ2M9`), user_id (buyer), status (`OrderStatus`: pending,
  paid, completed, cancelled, refunded, partially_refunded), currency, subtotal_cents, shipping_cents, tax_cents,
  total_cents, platform_fee_cents, shipping_address (json), stripe_checkout_session_id (unique),
  stripe_payment_intent_id, stripe_charge_id, paid_at, timestamps.
- `seller_orders`: order_id, seller_id, status (`SellerOrderStatus`: pending, paid, awaiting_shipment, shipped,
  delivered, completed, cancelled, refunded), subtotal_cents, shipping_cents, platform_fee_cents, payout_cents,
  stripe_transfer_id, transferred_at, carrier, tracking_number, tracking_url, shipped_at, delivered_at, timestamps.
- `order_items`: order_id, seller_order_id, purchasable morph, title (snapshot), is_digital, quantity,
  unit_price_cents, shipping_cents, line_total_cents.
- `stripe_events`: id (Stripe event id, string PK), type, processed_at — webhook de-duplication.

### 3.6 Entitlements (replace `DesignPurchase`)

`entitlements`: user_id, entitleable morph, order_item_id (nullable), source (`EntitlementSource`: purchase, free,
owner, admin_grant), granted_at, revoked_at, timestamps; unique(user, entitleable).
`User::hasAccessTo(Purchasable)`: owner, admin, an unrevoked entitlement, or (digital and price 0 → grant a `free`
entitlement on first download). Logged-in users only.

### 3.7 Fees — `config/marketplace.php`

currency; `fee_bps` (default 1000 = 10 %); `fee_fixed_cents` (0); `physical_payout_trigger` (`shipped`|`paid`,
default `shipped` — protects buyers of used gear); `auto_complete_days` (14); `stripe_tax` (bool, default false);
webhook secrets. Fee per seller order = round(subtotal × bps / 10000) + fixed (fee on items, not shipping;
seller `commission_bps` overrides). Payout = subtotal + shipping − fee. Platform sellers get no transfer.

### 3.8 Stripe (separate charges and transfers)

Interface `App\Services\Payments\PaymentGateway`, implementations `StripeGateway` (stripe-php) and
`FakePaymentGateway` (tests, records calls):
- `createConnectedAccount(SellerProfile): string` (Express; capabilities card_payments + transfers)
- `createOnboardingLink(string $accountId, string $returnUrl, string $refreshUrl): string`
- `createLoginLink(string $accountId): string` (Express dashboard)
- `retrieveAccountStatus(string $accountId): array` (charges_enabled, payouts_enabled, details_submitted)
- `createCheckoutSession(Order $order, array $lineItems, string $successUrl, string $cancelUrl): array{id,url}`
  — mode `payment`, `payment_intent_data.transfer_group` = order number, metadata.order_id, customer email,
  per-seller shipping as separate line items, `automatic_tax` if enabled.
- `createTransfer(int $amountCents, string $destination, string $transferGroup, ?string $sourceCharge, array $meta): string`
- `refund(string $paymentIntentId, ?int $amountCents): string`, `reverseTransfer(string $transferId, ?int $amountCents): string`
- `constructWebhookEvent(string $payload, string $signature, string $secret): object`
- (added in P2a-2) `findChargeId(string $paymentIntentId): ?string` — `checkout.session.completed` only carries the
  PaymentIntent, and `markPaid` / `source_transaction` need the charge; `refund()` and `reverseTransfer()` take an
  optional last `array $meta = []` (idempotency key + metadata).

Flow:
1. **Checkout** — `CheckoutService::start(User, ?Address)`: re-validate cart (availability, price, stock, shipping),
   create Order + SellerOrders + OrderItems (pending), create Checkout Session, store its id, redirect to Stripe.
2. **Success page** `/checkout/success?session_id=…` shows status; it never fulfils on its own.
3. **Webhooks** (CSRF-exempt, separate from Wave's subscription webhook):
   - `POST /stripe/marketplace/webhook` (secret `STRIPE_MARKETPLACE_WEBHOOK_SECRET`):
     `checkout.session.completed` (when `payment_status=paid`), `checkout.session.async_payment_succeeded`,
     `checkout.session.expired` (cancel order), `charge.refunded` (sync refund state).
   - `POST /stripe/connect/webhook` (secret `STRIPE_CONNECT_WEBHOOK_SECRET`): `account.updated` → seller flags.
4. **`OrderFulfillment::markPaid(Order, chargeId, paymentIntentId)`** — idempotent (row lock; no-op if paid): grant
   entitlements for digital items; decrement listing stock (→ sold_out at 0); digital-only seller orders → completed
   and transferred now; seller orders with physical items → awaiting_shipment, transferred now only if trigger is
   `paid`; remove ordered items from the buyer's cart; notify buyer (receipt) and sellers (new sale).
5. **Shipping** — seller enters carrier + tracking → shipped (+ transfer if still pending), buyer notified; buyer
   confirms receipt → delivered/completed; scheduled `marketplace:auto-complete` completes shipped orders after
   `auto_complete_days`.
6. **Refunds** — admin action (Filament): refund via gateway, reverse the transfer if one was made, revoke
   entitlements for refunded digital items, update statuses.

### 3.9 Downloads and snapshots

- Routes (auth): `GET /downloads/design-snapshots/{snapshot}` and `GET /downloads/driver-snapshots/{snapshot}`
  (keep route names `design-snapshots.download` / `driver-snapshots.download`) → `DownloadController`, authorized
  by a `download` policy ability on the parent Design/Driver via `User::hasAccessTo`, streamed from the private disk.
- Move snapshot logic out of the models into `App\Services\Snapshots\{Design,Driver}SnapshotBuilder`, run by queued
  jobs. Build the ZIP from the stored file-path JSON fields (not a directory walk) plus the PDF (designs; embed
  images from disk, not via URL), `manifest.json` (the stashed data) and `README.txt`. Snapshot rows get `version`,
  `file_size`, `download_path` (private disk). Notify the owner when ready.

### 3.10 Pages (Folio/Volt in `resources/themes/blank/pages`)

Public: `/marketplace` (listings browse: new/used, driver category, price, search), `/marketplace/listing/{uuid}`,
`/sellers/{slug}` (shop page), price/buy box on design and driver pages (generalise `AddToCartButton` to any
Purchasable), `/cart`, `/checkout` (address step + summary + pay), `/checkout/success`, `/checkout/cancel`.
Dashboard: `/dashboard/library` (entitlements + downloads), `/dashboard/orders` (buyer: status, tracking, confirm
receipt), `/dashboard/selling` (Stripe onboarding status, connect button, Express dashboard link, sales stats),
`/dashboard/selling/orders` (seller orders, mark shipped), `/dashboard/listings` (CRUD like the designs page),
addresses under settings. Sidebar links, header cart icon with count, "Contact seller" via Chatify.
Admin (Filament): Listings, Orders (+ seller orders relation manager, refund action), Seller profiles,
Entitlements (grant/revoke).

### 3.11 Demo data

Factories for every model (with useful states: free/paid, active/draft, paid/shipped orders…).
`DemoSeeder` (local/demo env only): admin `admin@sdlabs.test` / `password`, 3 designers, 2 shops with seller
profiles, 5 buyers; ~20 drivers across categories and ~10 designs with components; some free, some paid;
~15 listings new and used; orders in several states; reviews; banners. Generate **synthetic but physically plausible
FRD/ZMA files** (`App\Support\SyntheticMeasurement`: log-spaced 10 Hz–40 kHz; magnitude + phase from analytic
transfer functions — 2nd-order high-pass at fs, low-pass/breakup for woofers, high-pass for tweeters; impedance with
a resonance peak at fs and voice-coil inductance rise) written to the private disk so the viewers draw real curves.

## 4. Docker and hosting

- `docker/app/Dockerfile`: multi-stage (node → Vite build; composer `--no-dev`), runtime on a pinned
  `serversideup/php:8.3-fpm-nginx` tag. Compose services: `app` (web), `queue`, `scheduler`, `mariadb` (app DB + a
  `flarum` DB), `redis`, `flarum` (pinned `crazymax/flarum`), `caddy` (automatic HTTPS for `${APP_DOMAIN}`,
  `/forum` → flarum, everything else → app). Named volumes for db, redis, app storage, flarum data, caddy data.
- `compose.dev.yaml` override: bind-mounted code, Vite dev server, Mailpit, `stripe-cli` forwarding webhooks.
- `.env.example` documents every variable. `docs/DEPLOY.md`: VPS, DNS, install Docker, clone, `.env`,
  `docker compose up -d`, admin user, forum setup, Stripe setup (Connect settings, both webhook endpoints and their
  events), backups, updates.
- Images use their normal Docker Hub names in repo files (the build sandbox uses a registry mirror; don't hardcode it).

## 5. Phases and file ownership

| Phase | Owner | Scope |
|-------|-------|-------|
| P1 Foundation | agent | dependencies, Laravel 12 upgrade, bugs above, fresh-clone boot, tests/CI green |
| P2a Marketplace core | agent | migrations, models, enums, contracts, services, Stripe, webhooks, downloads, snapshots, upload disk/paths in dashboard forms, factories/seeders, tests |
| P2b Docker + forum | agent (parallel with P2a) | `docker/`, `compose*.yaml`, `.dockerignore`, Caddyfile, `config/forum.php`, `app/Services/Forum/*`, forum jobs, `app/Observers/*`, `app/Listeners/User*.php`, `forum:setup` command, `docs/DEPLOY.md` |
| P3 Marketplace UI | agent | pages, Livewire components, Filament resources, nav, notification views |
| P4 Review | lead | full tests, compose smoke test, browser screenshots, fixes |

Shared files (`.env.example`, `bootstrap/providers.php` / provider registration, `composer.json`): edit with small
targeted edits, re-read on conflict, never rewrite wholesale. P2a owns all models and migrations (incl. the
`users.forum_password` → `text` + `encrypted` cast change); P2b does not edit models.

## 6. Revision R1 (owner feedback after the first dev deploy)

Owner decisions: **only SDLabs sells new drivers**, from the driver page. **Users sell kits, used gear
and completed speakers** in the marketplace. **Paid driver data packs are removed** (driver measurement
snapshots are free public downloads again, as in SDLabs). **Seasonal themes are switched manually** by
an admin. **Demo forum content = threads + replies.** All new pages must look like the owner's own pages.

### R1.1 Store vs marketplace (backend)
- `listings.channel` (`ListingChannel`: STORE, MARKETPLACE) and `listings.category` (`ListingCategory`:
  DRIVER for store items; KIT, COMPLETED_SPEAKER, USED_GEAR for marketplace items), plus nullable
  `listings.design_id` (a kit / completed speaker built from a site design). Edit the original migration.
- STORE listings: created/edited by admins only, seller is a platform seller (admin), `driver_id`
  required, condition NEW, at most one active store listing per driver. They never appear in the
  marketplace browse. `Driver::storeListing()` (active one) for the driver page.
- MARKETPLACE listings: any user (Stripe Connect required to publish, as now). USED_GEAR may not be
  condition NEW; KIT / COMPLETED_SPEAKER any condition. Optional `driver_id` (used gear that is a
  catalog driver) and `design_id` (kits, completed speakers).
- Admins ship platform-seller orders: ShipmentService allows any admin to mark a platform seller's
  seller order shipped (Filament action), users still only their own.
- Remove driver data packs: drop `drivers.data_price_cents`; Driver no longer implements Purchasable
  and leaves the purchasable morph map / cart / buy box; driver snapshot downloads are public (no
  login) for active drivers, owner/admin for inactive ones. Designs stay the only paid digital item.

### R1.2 Forum demo content
- `ForumClient::createPost(string $discussion, string $content, User $author)` (discussion = the
  stored `{id}-{slug}`).
- `php artisan forum:seed-demo` (local/demo envs only): ensures tags, synchronously creates the missing
  thread for every public design/driver/listing (as its owner), then adds 2–4 replies per thread from
  other demo users (a bank of plausible hobbyist comments in DemoCatalog). Idempotent (skips threads
  that already have replies). DemoSeeder calls it at the end when the forum is enabled and reachable;
  otherwise prints the command to run after `forum:setup`.

### R1.3 Style alignment (UI)
Reference pages (owner-built): `pages/designs/index`, `pages/designs/design/[id]`, `pages/drivers/index`,
`pages/drivers/driver/[id]`, `pages/dashboard/designs/index`, `pages/dashboard/drivers/index`, and the
card containers in `components/marketing`. Every marketplace/commerce page and the buy box must use the
same layout skeleton, breadcrumbs, headings, cards, spacing, tab bars, Filament-table-in-Volt dashboards
and colours as those.

### R1.4 Seasonal themes (manual)
- Brand colours become CSS variables (`--brand-50…950`, `--accent-*`) mapped to a Tailwind `brand`
  palette; theme templates use `brand-*` instead of hard-coded purple.
- `config/seasons.php`: seasons (none, winter holidays, Halloween, spring, summer, …) each with variable
  overrides, an optional header garnish and an optional lightweight effect (e.g. CSS snowfall,
  disabled under prefers-reduced-motion).
- Admin picks the active season (Filament "Appearance" page, stored in Wave settings); the layout sets
  `data-season` on `<html>` and includes the season stylesheet. Filament admin is unaffected.
