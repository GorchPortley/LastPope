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
  `flarum` DB), `redis`, `flarum` (own image from `docker/flarum`, Flarum 2) with its `flarum-scheduler`, `reverb` (Laravel Reverb WebSocket server for Chatify's live chat,
  replaces pusher.com; `php artisan reverb:start`, internal port 8080), `caddy` (automatic HTTPS for `${APP_DOMAIN}`,
  `/forum` → flarum, `/ws/app/*` → reverb, everything else → app). Named volumes for db, redis, app storage, flarum assets and storage, caddy data.
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

Done in R1.3 (SDLabs originals are the style authority; light theme only, no `dark:` variants):
- Shared Blade components in `components/marketplace`: `page` (grey page + white max-w-7xl panel, breadcrumb and
  rule), `breadcrumb`, `section` (collapsible "File Overview" heading), `specs` (dl grid), `tabs` (design-page
  tab bar as links), `box` (card: rounded-lg border-2 border-gray-100 shadow-sm), `empty-state`, `gallery`
  (the owner's carousel look, Alpine); `components/marketing/listing-card` + `listing-card-container` follow
  design-card-container (the design / driver / listing containers take a `cols` prop for narrower places).
- Public pages (`/marketplace` with the banner + sidebar filter + `?type=` tabs, listing, shop, cart, checkout,
  success/cancel) use these; dashboards (library, orders, sales, listings) are Filament tables in Volt, order
  detail and selling use `x-filament::section`.
- Store listings have no page: `/marketplace/listing/{uuid}` redirects them (301) to the driver page, where the
  buy box ("Sold by SDLabs"), "Out of stock at SDLabs" (`Driver::latestStoreListing()`) or the MSRP box shows, plus
  "Used ones on the marketplace (n)". `Driver::activeStoreListing()` is the eager-loadable relation.
- Note: Filament buttons (`x-button tag="a"`) double-escape `&` in `href`, so link URLs with several query
  parameters need a plain `<a>`.

### R1.4 Seasonal themes (manual)
- Brand colours become CSS variables (`--brand-50…950`, `--accent-*`) mapped to a Tailwind `brand`
  palette; theme templates use `brand-*` instead of hard-coded purple.
- `config/seasons.php`: seasons (none, winter holidays, Halloween, spring, summer, …) each with variable
  overrides, an optional header garnish and an optional lightweight effect (e.g. CSS snowfall,
  disabled under prefers-reduced-motion).
- Admin picks the active season (Filament "Appearance" page, stored in Wave settings); the layout sets
  `data-season` on `<html>` and includes the season stylesheet. Filament admin is unaffected.

## 7. Revision R2: order problems and delayed physical payouts (owner decisions)

**Migration policy from now on:** the dev server holds data. Never edit existing migrations again;
every schema change is a NEW migration file (create/alter), so `php artisan migrate --force` applies
it on a running site.

### R2.1 Payout timing
- Physical seller orders are paid out **14 days after the seller marks them shipped**
  (`marketplace.physical_payout_trigger = shipped_delay`, `physical_payout_delay_days = 14`; keep the
  `paid` and `shipped` modes working). Confirming receipt marks the order delivered but does not pay
  out earlier. Digital items (designs) are still paid out at payment.
- No payout while the seller order has an unresolved issue. Due payouts are released by the scheduled
  job (hourly), which also retries failed transfers. Auto-complete (14 days after shipping) skips
  seller orders with an unresolved issue.

### R2.2 "Report a problem"
- `order_issues` (one unresolved issue per seller order): order, seller order, buyer, seller, reason
  (`IssueReason`: NOT_SHIPPED, NOT_RECEIVED, NOT_AS_DESCRIBED, DAMAGED, WRONG_ITEM, DOWNLOAD_PROBLEM,
  OTHER), status (`IssueStatus`: OPEN, OFFER_PENDING, AWAITING_RETURN, ESCALATED, RESOLVED), resolution
  (`IssueResolution`: FULL_REFUND, PARTIAL_REFUND, RETURN_REFUND, NO_ACTION, WITHDRAWN, CHARGEBACK_WON,
  CHARGEBACK_LOST), offer type/amount, refunded_cents, return carrier/tracking, stripe_dispute_id,
  escalated_at, resolved_at, resolved_by. `order_issue_messages`: issue, author (null = system), body,
  attachments (photos, private disk, authorised route), internal (admin-only note).
- Window: buyer can report from payment until 14 days after shipping (`issue_window_days`); an
  unshipped physical order can be reported any time while awaiting shipment; digital items until
  14 days after payment.
- Flow: buyer reports (reason, description, up to 5 photos) → seller is notified and answers in the
  thread and/or makes an offer: full refund, partial refund (amount), or return-for-refund. Buyer
  accepts → refund runs (RefundService, system actor); return: buyer adds return tracking → seller
  confirms receipt → full refund. Buyer can withdraw. Either side can escalate after 3 days without
  agreement (`issue_escalation_days`), or at once if the other side is unresponsive past that; admins
  decide in Filament: full refund, partial refund (amount), no action. SDLabs store orders go straight
  to ESCALATED (the platform is the seller).
- Money: a refund before the payout reduces the payout by the refunded amount (full refund cancels it;
  the platform fee is kept on partial refunds, waived on full ones); after the payout, the refund
  reverses that amount of the transfer (existing behaviour).
- Stripe bank disputes: handle `charge.dispute.created` / `charge.dispute.closed` on the marketplace
  webhook: open (or attach to) an ESCALATED issue with the dispute id for every seller order of the
  order, hold their payouts, notify admins; on close record won/lost; admin action "Recover from
  seller" reverses the seller's transfer when a lost dispute warrants it. Add both events to DEPLOY.md.
- Everyone involved gets mail + database notifications for: issue opened, new message, offer made,
  offer accepted/declined, escalated, resolved. Buyer and seller order pages get "Contact seller" /
  "Contact buyer" (Chatify) and the issue panel; a shared issue page `/dashboard/issues/{issue}`
  (buyer, seller, admins only). Filament: OrderIssueResource (queue with status filters, navigation
  badge = escalated count, thread with public reply / internal note, decision actions).

### R2 implementation notes (where things live)
- Config: `config/marketplace.php` (`physical_payout_trigger` = `shipped_delay` default, `physical_payout_delay_days`,
  `issue_window_days`, `issue_escalation_days`, `issue_max_photos`, `issue_photo_max_kb`); env names in `.env.example`.
- Migrations (new files only): `2026_10_22_000001_create_order_issues_tables`, `2026_10_22_000002_add_reversal_columns_to_seller_orders`
  (`reversed_cents`, `reversal_pending_cents`). `order_issues.open_seller_order_id` is unique and NULL once resolved: the
  database enforces "one unresolved issue per seller order".
- Payouts: `PayoutService::isDue/dueAt/holdReason/outlook`; the hourly `marketplace:auto-complete` completes parcels (not
  with an unresolved issue) and releases due payouts. A lost bank dispute blocks the payout until an admin recovers it.
- Money: `RefundService` handles partial amounts per seller order (`refundSellerOrderPartially`, system path
  `refundForIssue`, `recoverPayout`). Seller order `payout_cents` shrinks by a partial refund made before the payout; after
  the payout `reversed_cents` grows (`reversal_pending_cents` when Stripe refuses). `refunded_cents` is cumulative.
- Issues: `IssueService` (all rules; `can()` mirrors them for the UI), `OrderIssuePolicy`, models `OrderIssue` /
  `OrderIssueMessage`, enums `IssueReason` / `IssueStatus` / `IssueResolution`, notifications `Issue*` + `DisputeOpened`.
- Webhook: `charge.dispute.created` / `charge.dispute.closed` in `MarketplaceWebhookHandler` -> `IssueService::recordDispute`.
- UI: Livewire `OrderIssuePanel` (thread + actions) used on the order page and on the shared page
  `/dashboard/issues/{orderIssue}` (Folio), list `/dashboard/issues`, photos at `dashboard.issues.attachment` (private disk).
  Filament: `OrderIssueResource` (queue with tabs, case page with decisions), `IssuesRelationManager` on orders.

## 8. Revision R3: dropshipping for the SDLabs store (owner decisions)

The SDLabs store (STORE listings, platform seller) sells a **mix of own inventory and dropshipped
products**. Marketplace (user) listings are unchanged. Migration policy §7 applies (new migrations only).

### R3.1 Suppliers and fulfilment modes
- `suppliers`: name, contact name/email/phone, `order_email` (where purchase orders go), website,
  account number, ships_from_country, lead_time_min_days / lead_time_max_days, dropship_fee_cents (per
  supplier order), blind_dropship (bool, default true: no supplier branding/prices in the parcel),
  auto_send_purchase_orders (bool, default false), shipping rules for what WE charge the buyer for that
  supplier's parcel: domestic_cents, international_cents (nullable = domestic only),
  additional_item_cents, free_shipping_over_cents (nullable), notes, active.
  Optional stock feed: feed_url (CSV over HTTP) or an uploaded CSV, column names for sku / quantity /
  cost, feed_last_synced_at, feed_last_error.
- Store listings get `fulfillment` (`FulfillmentMode`: STOCK, DROPSHIP, STOCK_THEN_DROPSHIP), nullable
  supplier_id, supplier_sku, `cost_cents` (what one unit costs us: supplier price for dropship, purchase
  cost for stock), `supplier_stock` (nullable int from the feed) and `supplier_stock_synced_at`.
  * STOCK: as today (own quantity, own shipping fields, handling_days).
  * DROPSHIP: no own quantity; available while the supplier is active and supplier_stock is null
    (unknown → assume available) or > 0; max orderable = supplier_stock when known.
  * STOCK_THEN_DROPSHIP: sells own stock first; when own quantity cannot cover a line it is fulfilled
    by the supplier (a line is never split: the whole line is stock or dropship, decided at checkout and
    stored on the order item as `fulfillment_source` = stock|supplier + supplier_id).
- Stock reservation (orders.reserved_until) keeps working for own stock; supplier lines are checked
  against supplier_stock when known and never decrement own quantity.

### R3.2 Shipping and buyer-facing info
- Within the platform seller order, shipping is computed per **fulfilment group**: own-stock lines use
  the listing rules as today; each supplier's lines form one parcel priced with the supplier rules
  (first item domestic/international by buyer country, + additional_item_cents per further unit, free
  over free_shipping_over_cents). The cart/checkout show one shipping line per group, e.g. "Shipping
  (ships from SDLabs)" / "Shipping (ships separately, 5–8 business days)". Supplier names are never
  shown to buyers.
- Buy box / listing / driver page: own stock → "In stock, ships in N days"; dropship → "Ships in X–Y
  business days"; hybrid shows whichever applies to the chosen quantity.

### R3.3 Purchase orders and shipments
- `supplier_orders` (purchase orders): number (e.g. PO-7KQ2M9), order, seller order, supplier, status
  (`SupplierOrderStatus`: PENDING, SENT, CONFIRMED, SHIPPED, CANCELLED, PROBLEM), shipping address
  snapshot, supplier_reference (their order number), items (`supplier_order_items`: order item,
  supplier_sku, quantity, unit_cost_cents), items_cost_cents, shipping_cost_cents (what the supplier
  charges us, entered when known), dropship_fee_cents, sent_at, confirmed_at, cancelled_at, notes.
- Created automatically by OrderFulfillment::markPaid — one per supplier per paid order (idempotent).
  Auto-send when the supplier has auto_send_purchase_orders, otherwise admins send it.
  Sending = queued mail to order_email with PO number, lines (SKU, qty, unit cost), ship-to address,
  "blind dropship" instructions when set, and a reply-to of the store email; plus CSV download and
  "Mark as placed manually" (for supplier web portals) with supplier_reference.
- `shipments` (general, used for every seller order from now on): seller order, optional supplier
  order, carrier, tracking_number, tracking_url, shipped_at, items (pivot with quantities). The existing
  marketplace seller "Mark shipped" flow creates one shipment for the whole seller order (unchanged UX).
  Store orders can have several (own-stock parcel shipped by an admin + one per purchase order when the
  admin enters the supplier's tracking). A seller order becomes SHIPPED when all its items are in
  shipments; shipped_at = the last shipment's time, so payout timing (R2.1), auto-complete and the
  problem window keep their meaning. The buyer gets OrderShipped per shipment ("2 of 2 parcels shipped").
- Refund/cancel: refunding store lines whose PO is still PENDING cancels those PO lines; if already SENT,
  the PO is flagged PROBLEM with a note "cancel with supplier" for the admin.

### R3.4 Stock feed, margins
- `marketplace:sync-supplier-stock` (scheduled hourly; also an admin "Sync now" button): reads each
  supplier's feed, matches rows to listings by supplier_sku, updates supplier_stock (and cost_cents when
  a cost column is mapped), records errors on the supplier; never deletes listings.
- Margin report (admin): per paid order and totals for a date range — revenue (items + shipping
  charged), costs (unit costs × qty, supplier shipping, dropship fees), estimated Stripe fee
  (`marketplace.stripe_fee_bps` default 290 + `stripe_fee_fixed_cents` default 30), refunds, margin and
  margin %; CSV export. Store listing form shows the unit margin at the entered price and warns below
  `marketplace.min_margin_bps` (default 1500).

### R3 implementation notes (backend, part 1; the admin screens and buyer pages are part 2)
- Migrations (new files only, `2026_10_23_00000x`): `create_suppliers_table`, `add_dropshipping_columns_to_listings`
  (`fulfillment` default `stock`, `supplier_id`, `supplier_sku`, `cost_cents`, `supplier_stock`, `supplier_stock_synced_at`),
  `add_fulfillment_columns_to_order_items` (`fulfillment_source` stock|supplier, `supplier_id`, `unit_cost_cents` snapshot),
  `create_supplier_orders_tables` (`supplier_orders` unique on seller order + supplier, `supplier_order_items`),
  `create_shipments_tables` (`shipments`, `shipment_items`), `backfill_shipments_from_seller_orders` (one shipment per
  shipped/delivered/completed seller order with a carrier or tracking, carrying all its physical items). Seller orders get a new
  status `partially_shipped` (a string column: no schema change); `seller_orders.carrier/tracking_*` mirror the latest shipment.
- Models/enums: `Supplier`, `SupplierOrder`, `SupplierOrderItem`, `Shipment`, `ShipmentItem`; `FulfillmentMode`,
  `FulfillmentSource`, `SupplierOrderStatus`. Policies `SupplierPolicy`, `SupplierOrderPolicy` (admins only), `ShipmentPolicy`.
- Availability: `Listing::availableQuantity()` is mode-aware (stock: own minus held; dropship: supplier stock, or
  `dropship_unknown_stock_max` when unknown; hybrid: the larger). `Listing::fulfillmentFor($qty, $buyer)` returns a
  `FulfillmentQuote` (source, available, maxQuantity, lead time, supplier [internal]); `shippingTextFor()`, `inStock()`,
  `supplierUsable()`, `ownAvailableQuantity()`, `unitMarginCents()/marginBps()/lowMarginWarning()`; scopes `forSale()` (mode-aware) and `dropshipped()`.
  Only lines from own stock hold stock (orders.reserved_until). The source is stored on `order_items` at checkout and a line is never split.
- Shipping: `ShippingCalculator::quote()` prices a supplier's lines as one parcel; `SellerGroup::$shippingGroups` / `CartSummary::shippingGroups()`
  (`ShippingGroup`: label() "Ships from SDLabs" / "Ships separately (5–8 business days)", shippingLabel(), shippingCents). Checkout sends one
  Stripe shipping line per parcel for the platform seller and keeps one per marketplace seller.
- Purchase orders: `PurchaseOrderService` (createForOrder, processNew, send, markPlacedManually, confirm, recordSupplierCosts, markShipped, cancel,
  cancelLines, handleRefund, flagProblem, toCsv, toSendCount), `PurchaseOrderMail` (queued), `PurchaseOrdersToSend` (admins). Created by
  `OrderFulfillment::markPaid`; refunds go through `RefundService::applyRefund` -> `handleRefund`.
- Shipments: `ShipmentService` (markShipped = one shipment with everything we ship ourselves; shipItems; updateShipment; recordShipment;
  addShipment/afterShipment for the PO flow), `ParcelPlanner` (parcels(), remaining(), expectedParcelCount()), `OrderShipped` per shipment
  ("parcel k of n"), `SellerOrder::shipmentSummaries()`, `Order::shipments`.
- Feed + margin: `SupplierFeedSync` + `marketplace:sync-supplier-stock` (hourly), `MarginReport` (rows(), forRange(), toCsv(), unitMargin(),
  lowMarginWarning()). Config keys in `config/marketplace.php` (`store_name`, `store_email`, `purchase_order_prefix`, `dropship_unknown_stock_max`,
  `supplier_feed_timeout_seconds`, `supplier_feed_max_kb`, `stripe_fee_bps`, `stripe_fee_fixed_cents`, `min_margin_bps`).

### R3 implementation notes (admin screens and buyer pages, part 2)
- Filament, navigation group **Store** (admins only through `SupplierPolicy` / `SupplierOrderPolicy`): `SupplierResource` (every supplier field,
  feed URL or CSV upload on the private disk, "Sync stock now" via `SupplierFeedSync`, "Download feed template" via
  `SupplierFeedSync::templateCsv()`), `SupplierOrderResource` (slug `purchase-orders`: queue with status tabs and a "to send" navigation
  badge, page with every `PurchaseOrderService` action, see `SupplierOrderResource/PurchaseOrderActions` which also serves the queue rows
  and `OrderResource`'s `PurchaseOrdersRelationManager`), page `Margins` (`MarginReport`, presets this month / last month / last 30 days /
  custom, CSV), widget `StoreFulfilment` on the dashboard (POs to send, POs with a problem, store parcels to ship, suppliers with feed errors).
- `ListingResource`: for store listings a "Fulfilment and cost" section (mode radio, supplier, supplier SKU, cost, read-only supplier stock and
  last sync) and a live "Margin per unit" next to the price (warning colour below `min_margin_bps`); a pure dropship listing hides the own
  quantity, handling time and own shipping; `ListingResource::normalizeFulfilment()` clears what the mode does not use (supplier for own stock,
  quantity 0 for dropship, always own stock for marketplace listings). The model's `channelRuleViolations()` stays the second line of defence.
- "Ship SDLabs parcel" (`OrderResource/ShipStoreParcelAction`, on the order page and the seller order relation manager) ships the own-stock
  units with `ShipmentService::shipItems()` (`ownRemainingQuantities()` is the public wrapper of what is left); supplier lines are listed in
  the form and shipped from their purchase order. "Mark shipped" stays for marketplace sellers' orders and for correcting tracking.
- Buyer pages: the buy box says when the chosen quantity ships (`fulfillmentFor()`; a store listing shows "In stock, ships in N days" /
  "Ships in X–Y business days", marketplace listings keep "N available"); its quantity input is live (`wire:model.live`), `quantity` may be
  empty while typing (`quantity()` is the number). Cart and checkout draw one block per parcel (`ShippingGroup::label()`, components
  `marketplace/parcel-heading`, `cart-line`, `checkout-line`). The buyer's order page lists parcels ("Parcel 1 of 3", items, status, carrier +
  tracking link, expected window for unshipped supplier parcels: `Parcel::expectedText()` counts business days from the payment date);
  `OrderReceipt` lists the parcels when there are several, `OrderShipped` adds "Still to come". No supplier name, SKU, cost or PO number reaches
  a buyer (tests assert the demo suppliers' names are absent from every buyer page and mail).

## 9. Revision R4: personalised design PDF and branded e-mails (owner requests)

### R4.1 Personalised design PDF
- The stored snapshot ZIP keeps the **generic** PDF (no name; owner previews, fallbacks). Every design snapshot download
  (`DownloadController::designSnapshot`, after the `download` policy) goes through `Services\Snapshots\PersonalisedSnapshotDownload`: it
  renders the PDF again for the downloader (`DesignPdf::render($snapshot->stashed_data, PdfLicense)`) and **streams a new ZIP** with
  `maennchen/zipstream-php` (now a direct requirement, pinned to the installed 3.2.2): every other entry is copied entry by entry from the stored
  ZIP (ZipArchive stream -> ZipStream, same names, order, compression kind and timestamps, no temp file, only the PDF is in memory), the PDF entry
  is the personalised one. No PDF entry, a non-local disk or a render failure -> the stored ZIP is streamed unchanged.
- The PDF is rendered from the snapshot's own manifest (`DesignSnapshotBuilder::content()` also stores `tag`), not from the live design, so a
  version stays what it was. The footer on every page: "Licensed to {name} (@{username}) · downloaded {date} · {Order SDL-…|Free download|
  Designer copy|Admin copy|Complimentary copy|Purchased copy} · {app url}" + "For personal use — please don't redistribute." + "Page x of y". No e-mail
  address, everything escaped (`PdfLicense`). dompdf runs with PHP, JavaScript and remote access off; images come from disk as data URIs.
- Cache: `{snapshots dir}/personalised/{snapshot id}/u{user id}-{snapshot updated_at}-{footer fingerprint}.pdf` on the private disk (a changed name or
  a new day renders a fresh PDF and replaces the user's older one). `PersonalisedSnapshotDownload::forget()` runs when the snapshot is rebuilt
  (`DesignSnapshotBuilder`) or deleted (`DesignSnapshot::deleted`); deleting the design removes its whole folder.
- Look: `resources/views/pdf/Design.blade.php` in the default palette (`App\Support\BrandColors`, a PHP copy of the hex values of app.css;
  `BrandColorsTest` keeps them in sync), the site logo as SVG (`resources/pdf/sdlabs-logo-white.svg`), DejaVu Sans (the site itself uses the
  system font stack, there is no web font to embed). No Thiele/Small table and no "Design plans" kicker (owner feedback). Photos: card images and
  the `<img>` uploads in the summary/description are read from the public disk (`PdfImages`: `/storage/...` or any-host `.../storage/...` URLs,
  WebP converted, EXIF rotation applied, capped to the page); an image that cannot be read is left out, never fetched. **Frequency response
  figure**: `DesignSnapshotBuilder::content()` keeps `frequency_response` (the lines of the design's FRD files and their complex sum, 1/48 octave
  points; `App\Support\FrequencyResponse`, the same parsing and summation as the page's `FrequencyResponseViewer`); `ResponseChart` draws it with GD
  (2x supersampled PNG, log axis 20 Hz - 20 kHz, dB range fitted) as a data URI. No FRD files, no section.

### R4.2 Branded e-mails
- Mail theme `sdlabs` (`config('mail.markdown.theme')`, `resources/views/vendor/mail/html/themes/sdlabs.css`): brand-950 header band with the
  logo mark (`public/images/email/logo.png`, a 2x PNG of the site logo's ring because mail clients block SVG) and "SDLabs.cc" as text next to it, amber rule, brand-700 buttons, light-tinted table header, footer with the site links; dark-mode and phone rules live in
  `html/layout.blade.php`. `<x-mail::message variant="business">` (the supplier purchase order) drops the tagline and the marketing links.
  Every mail the app sends is markdown mail or a notification through `notifications::email`, so all of them use it (Wave and Laravel's verify and
  reset mails included). Flarum's own forum mails are sent by Flarum with its own templates.
- Admin > Settings > **Email previews** (`Filament\Pages\EmailPreviews`, admins only): all mails rendered in sandboxed iframes (desktop / phone
  width, plain-text version) from made-up data. `Support\EmailPreviews\SampleData` creates the shop with the real order code (OrderBuilder,
  ShipmentService, plain Eloquent, no factories: the production image has no Faker) inside a transaction that is always rolled back, with
  notifications, mail and queues faked; `EmailPreviews::definitions()` lists the mails (add new ones there: a test checks every mail-capable
  notification is covered).

## 10. Revision R5: Flarum 2.0 (owner decisions)

### R5.1 Upgrade (one agent)
- Replace the crazymax/flarum 1.8 image with our own image in docker/flarum: same pinned
  serversideup/php 8.3 fpm-nginx base as the app, Flarum 2.0.x installed with Composer at BUILD time
  from a committed docker/flarum/composer.json + composer.lock (bundled default extensions plus
  flarum/tags, flarum/embed, fof/upload, fof/polls, fof/reactions, fof/best-answer, fof/byobu — all
  stable 2.x, exact versions). No runtime Composer installs, no installer patching.
- Served at https://DOMAIN/forum (sub-path). First start: unattended install (admin from FLARUM_ADMIN_*,
  url from APP_DOMAIN, DB from FLARUM_DB_*), then on every start: config generated from env, migrate,
  enable the configured extensions, store FLARUM_API_KEY, copy MAIL_* settings, clear cache. Persist
  what must survive (config if needed, storage, public assets incl. avatars and fof/upload files).
  Flarum's scheduler runs (schedule:run every minute). An existing 1.8 database is upgraded in place
  with `php flarum migrate` (Flarum's official path); `docker/flarum/reset.sh` wipes the forum (volume
  + database) for a clean start — the owner will reset the dev forum.
- Laravel side: FlarumClient and everything using it (SSO remember-cookie login, user creation, tags,
  discussions, posts, reply counts, embed URLs, forum:setup / --backfill / forum:seed-demo) verified
  against a real Flarum 2.0 and adapted. Remove the unused maicol07/flarum-sso-plugin package.
- The embed page (flarum/embed 2.x) must show the discussion without Flarum's top bar; the
  composer and fof/upload work inside the iframe.

### R5.1 implementation notes (where things live; done)
- `docker/flarum/`: `Dockerfile` (php-base, vendor via `composer install`, runtime), `composer.json` + `composer.lock` (Flarum
  core 2.0.2, exact extension versions, `platform.php` 8.3.0), `skeleton/` (launcher files of flarum/flarum 2.0.0),
  `nginx/http.conf.template` (replaces the base image's site template: `/forum/assets/` as files, everything else under `/forum` to
  `public/index.php`; Caddy forwards `/forum*` unchanged), `entrypoint.d/60-flarum.sh` -> `lib/start.php` (wait for DB, install or
  `migrate`, API key, enable extensions once each, assets, caches; `config.php` is written from the env at every start), `bin/flarum-scheduler`
  (`schedule:run` every minute, the `flarum-scheduler` service), `reset.sh`.
- Extensions: tags, embed, fof/upload, polls, reactions, best-answer, byobu plus Flarum's defaults; **fof/byobu stays installed and enabled**
  (R5.2 builds on it). Flarum 2 needs `database.driver = mariadb` in config.php on MariaDB (`mysql` sends `CAST(.. AS JSON)`).
- Laravel side: `FlarumClient` uses `isPrimary` for tags, reads the version from `/api/system-info/system`, re-checks a stored
  `users.forum_user_id` before trusting it; `forum:unlink` forgets `forum_slug` / `forum_user_id` after a reset. The API key is not
  throttled by Flarum 2, so `forum:seed-demo` takes about a minute. Removed: maicol07/flarum-sso-plugin.

### R5.2 Discussion visibility with Byobu (follow-up agent)
| Design state           | Discussion visible to                                   |
|------------------------|---------------------------------------------------------|
| hidden (not active)    | designer + admins (thread created when the design is)   |
| active, free           | everyone (public, Designs tag)                          |
| active, paid           | designer + buyers (unrevoked entitlements) + admins     |
Buyers are added when an entitlement is granted and removed when it is revoked (refunds). Changes of
active status or price move the thread between these states. Drivers and listings stay public.

### R5.2 implementation notes (where things live; done)
- `App\Services\Forum\DesignDiscussionAudience`: the one place that says who may see a design's discussion, from the database:
  `isPublic()` (active and free), `members()` (the designer, the site's admins = role `admin`, and, while active and paid, the users with an
  unrevoked entitlement) and `includes($design, $user)` (the same rules for one viewer). The job and the Discussion tab both use it.
- `App\Jobs\SyncForumVisibility` (queued, `afterCommit`, `ShouldBeUniqueUntilProcessing` per design plus `WithoutOverlapping`, so a change
  that arrives while a run is in progress queues another run and the last run always reads the newest data; 3 tries with backoff
  60/300/900 s like CreateForumDiscussion). Stateless: it recomputes the audience and calls `ForumClient::makeDiscussionPublic()` or
  `makeDiscussionPrivate($slug, $members)`. `SyncForumVisibility::queueFor()` skips designs without a thread and never throws.
  Triggers: `DesignObserver` (active or price_cents changed), `EntitlementObserver` (a new, revoked, restored or deleted entitlement of a
  design whose audience depends on buyers, so every path counts: `User::grantEntitlement` / `revokeEntitlement`, refunds, Filament),
  `CreateForumDiscussion` (after it opened a design thread) and `php artisan forum:sync-visibility` (all designs, run inline, prints
  public/private/failed; the re-sync after a forum reset or restore, or when admins changed).
- A design gets its thread when it is created, active or not (`CreateForumDiscussion::wantsThread()`, formerly `isPublic()`); drivers and
  listings are unchanged. `forum:setup --backfill` and `forum:seed-demo` include hidden designs; `forum:seed-demo` also syncs the
  visibility before it posts, and only lets demo users who can see a private design thread reply (the buyers and the demo admin;
  DemoSeeder now gives the admin's two paid designs a complimentary buyer so they have somebody to talk to; hidden designs get no replies).
- `FlarumClient` (verified against Flarum 2.0.2 + fof/byobu 2.0.0, the real image):
  - Private = recipients. `PATCH /discussions/{id}` with `relationships.recipientUsers` / `recipientGroups` sets the list exactly (added and
    removed ones are diffed by Byobu; the same list again is a no-op, so the call is idempotent). The Admin group (id 1) is always sent.
  - Byobu shows a private discussion **only to recipients, forum admins included** (an admin who is not a recipient gets 404, also when
    patching), so the Admin group stays a recipient. The site's app admins are added as user recipients, so they pass the embed.
  - Members cannot create private discussions (403: `startPrivateDiscussionWith*` is granted to nobody by default) and the tag is dropped
    when a discussion is created with recipients. So a thread is created public in its tag as the designer, then restricted by the admin
    identity, in the same CreateForumDiscussion run (the queued sync is only the retry). The tag stays on the discussion through private
    and public again. The price: a public moment between those two requests.
  - Public again: removing all recipients **hides the discussion** (soft delete) instead. What works is `attributes.public = <discussion id>`
    plus empty `recipientUsers` / `recipientGroups` in the same PATCH (without the relationships Byobu answers 200 and ignores it), and only
    while the setting `fof-byobu.makePublic` is on, which is off on a fresh forum: the client reads `canMakePublic` and switches it on with
    `POST /settings` the first time. Both methods read the discussion back afterwards because Byobu answers 200 to requests it ignores.
  - Byobu adds an event post ("forumadmin added recipients ...") on every change of the list, which would list every purchase and refund
    in a design's thread. `docker/flarum/skeleton/extend.php` binds Byobu's `CreatePostWhenRecipientsChanged` (subscribed by class name,
    so the container binding wins) to a subclass that skips recipient-change and made-public posts when the actor is a forum admin;
    members' own private discussions keep them. The skipped post is not only cosmetic: Byobu stores recipients after the discussion is
    saved, and the post's creation re-saves the discussion (Post::created), which is when Flarum recomputes `is_private`. The subclass
    therefore re-saves a fresh copy of the discussion itself (without it the first "make private" left the thread public). The Docker CI smoke test checks a discussion still has one post after such changes.
    Recipients still see each other's names when they post;
    each new recipient gets an alert and an e-mail "added you to a private discussion", and the first restriction would even tell the
    designer about their own thread. `createUser()` therefore switches `notify_byobuPrivateDiscussionAdded_{alert,email}` off for accounts the
    site creates (a member can only edit their own preferences, so it acts as that account). Replies in a private discussion still notify
    all recipients (Byobu default; left alone).
  - `makeDiscussionPrivate()` resolves every member with `ensureUser()` and throws when one has no account, because the list replaces the
    current one and a partial list would remove a buyer.
  - Embed: `/forum/embed/{slug}` with the SSO `flarum_remember` cookie is 200 for a recipient and 404 for a non-recipient or guest.
- Discussion tab (`design-description.blade.php`, `DesignDescription::canSeeDiscussion`): the iframe for those `includes()` accepts, otherwise
  "The discussion for this design is open to buyers." plus "Buy this design to join it." (logged in) or "Log in to buy this design and
  join it." (guest), in the style of the Files tab message (`data-discussion-locked`).
- Tests: `tests/Feature/Forum/ForumVisibilityTest.php` (state table, recompute, triggers, tab, `forum:sync-visibility`), client payloads in
  `FlarumClientTest`, `FakeForumClient::$visibility` (posting to a private thread as a non-member fails like Flarum). Known gaps: granting
  or removing the admin role does not queue a sync by itself (the next change of a design does, or run `forum:sync-visibility`), and
  deleting a design leaves its thread as it was.

## 11. Revision R6: Stripe ledger, balances and reconciliation (owner decisions)

Owner request: "a 1:1 link with Stripe that shows all transactional info so that balances are always accurate and up to
date". Until now every money figure on the site (admin stats, seller dashboard totals, the Margins report's Stripe fee,
which is an estimate) came from the site's own records. Decisions: pages for **admins and sellers**, a **daily
reconciliation with an admin alert**, and **actual Stripe fees** instead of the estimate.

### R6.1 Mirror (Stripe is the source of truth for money that moved)
- New tables (new migration files only):
  - `stripe_balance_transactions`: Stripe's ledger, 1:1. Primary key `txn_...`; `account` (null = platform, else `acct_...`);
    type, reporting_category, amount / fee / net (cents), currency, available_on, created (Stripe time), source id and type,
    description, fee details, livemode; plus links found by matching: order, seller order, seller (user).
  - `stripe_payouts`: payouts to a bank (platform and connected accounts): `po_...`, account, amount, currency, status,
    arrival date, failure message, livemode.
  - `stripe_balances`: latest balance per account and currency (available, pending, in transit), refreshed time.
  - a per-account sync cursor; `orders.stripe_fee_cents` (nullable, actual fee of the charge).
- Sync job per account (platform + every connected seller account, Stripe-Account header): balance transactions
  (incremental by `created` with an overlap window, idempotent upserts), payouts, balance. Pending/available follows
  `available_on`. Scheduled every 10 minutes; webhooks only *trigger* a sync of the account concerned (platform:
  balance.available, payout.*, transfer.*, charge.refunded, charge.dispute.*; connect: payout.*, balance.available).
  Backfill command for the whole history. Wave subscription charges share the platform account: unlinked charges
  without order metadata are "other", never errors.
- Linking: charge `ch_` -> orders.stripe_charge_id; refund -> its charge's order (and seller_orders.stripe_refund_id);
  transfer `tr_` -> seller_orders.stripe_transfer_id; transfer reversal -> its transfer; dispute adjustments -> charge;
  a connected account's `payment` (py_) -> its source transfer -> seller order.

### R6.2 Pages
- Admin, Filament group "Finance": **Stripe ledger** (balance cards with last sync time and "Sync now"; transactions table
  with filters, order links, an "open in Stripe" link, CSV export; seller balances table: Stripe balance per seller,
  next/last payout, plus money the site still holds for them) and **Reconciliation** (open differences, resolve with a note).
  Dashboard widget: open differences count.
- Seller: **Earnings** page in the selling dashboard: Stripe balance (available, pending, on the way to the bank), payouts
  to their bank with status and arrival date, transfers received linked to their orders, and payouts SDLabs still holds
  (existing PayoutService outlook). Existing selling-dashboard totals use the ledger for money that was paid out.

### R6.3 Reconciliation (daily, plus on demand)
Compares site records with the ledger and stores each difference once (deduplicated, auto-resolved when a later run finds
it fixed); a mail to admins lists new ones. Checks: every paid order has its charge for the order total; Stripe's
refunded total per charge equals what the site recorded; every transferred seller order has its transfer (amount, account)
and its reversals; every platform transfer belongs to a seller order; charges with order metadata whose order is not paid
on the site (missed webhook); failed seller payouts; disputes without a case.

### R6.4 Actual fees
`orders.stripe_fee_cents` from the charge's balance transaction. MarginReport and stats use it; the estimate stays only
until Stripe reports the fee and is marked "(est.)".

### R6 implementation notes (where things live; done)
- **Migrations (new files only, `2026_10_25_00000x`)**: `create_stripe_ledger_tables`, `add_stripe_fee_to_orders`, `create_stripe_discrepancies_table`.
  - `stripe_balance_transactions` (PK `txn_...`, models `StripeBalanceTransaction`): `account` (NULL = platform), `livemode`, `type`, `reporting_category`,
    `amount_cents` / `fee_cents` / `net_cents` (signed), `currency`, `stripe_created_at`, `available_on` (pending while in the future), `description`,
    `fee_details`; the object behind it: `source_id`, `source_type`, and the ids found in it (`charge_id`, `payment_intent_id`, `transfer_id`,
    `reversal_id`, `refund_id`, `dispute_id`, `payout_id`, `counterparty` = the account a transfer went to, `transfer_group`, `source_metadata` = only
    the keys this site sets: order_id, order_number, seller_order_id(s), because the platform account is shared with Wave's billing); the links:
    `link_status` (`LedgerLink`: linked / unlinked / other), `order_id`, `seller_order_id`, `seller_id` (nullOnDelete).
  - `stripe_payouts` (`StripePayout`: status, arrival date, `failure_code` / `failure_message` with `failureExplanation()` in plain words,
    `destination_label` "BANK ••••6789"), `stripe_balances` (`StripeBalance`: per `account_key` ('platform' | acct_), mode and currency:
    available, pending, `in_transit` = sum of the payouts on their way, `connect_reserved`, `refreshed_at`), `stripe_sync_cursors`
    (`StripeSyncCursor`: `synced_through` = start of the last complete pass, `resume_*` for a pass cut at the page limit, last error, failure
    count), `orders.stripe_fee_cents` (nullable), `stripe_discrepancies` (`StripeDiscrepancy`, `DiscrepancyKind`; unique kind + key).
  - Everything carries `livemode`, taken from the key (`App\Support\StripeMode`: `sk_live_` / `rk_live_`; a balance transaction has no such field).
    Pages and the reconciliation only look at the current mode; cursors and balances exist once per mode, so switching from test to live starts clean.
- **Gateway**: `PaymentGateway::listBalanceTransactions(?account, ?createdSince, ?startingAfter, limit)` (`expand[]=data.source`),
  `listPayouts(...)` (`expand[]=data.destination`), `retrieveBalance(?account)`; `StripeGateway` runs them through `call()` with the
  `stripe_account` request option for a connected account and returns Stripe's objects as arrays. `PaymentGatewayException::isPermanent()` tells a
  refusal (unknown account, no permission, bad key) from a transient failure. `FakePaymentGateway` (trait `Concerns\SimulatesStripeLedger`) keeps a
  ledger in memory in the shape of the real objects: `createTransfer` books the transfer on the platform and the `payment` (py_, `source_transfer`) on
  the seller, `reverseTransfer` the `transfer_refund` + `payment_refund`, `refund` the `refund` (when the payment intent is a charge recorded in it);
  test helpers `recordCharge`, `recordOrderCharge`, `recordSubscriptionCharge` (Wave), `recordRefund`, `recordDispute` / `closeDispute`,
  `recordPayout` / `failPayout`, `alterLedgerTransaction`, `forgetLedgerTransaction`; the balance is computed from the transactions.
- **Sync**: `Services\Marketplace\Ledger\LedgerSync::syncAccount(account, since, maxPages)`: balance transactions newest first from `synced_through`
  minus the overlap (`marketplace.ledger.overlap_minutes`, 60), upserted by id in pages of 100 (links are not part of the update), payouts of the last
  `payout_lookback_days` (45) again, then the balance; a failure is written to the cursor and rethrown. The cursor moves only when a pass reaches its
  end; a pass cut at `maxPages` is continued by the next run (`resume_*`). `Jobs\SyncStripeLedger` (one per account: `ShouldBeUniqueUntilProcessing` +
  `WithoutOverlapping`, backoff 30/120/300/900 s, queues itself again when a pass was cut, does not retry what Stripe refused for good, `queueFor()`
  never throws). `stripe:ledger-sync {--account=} {--all} {--since=} {--queue}`; scheduled `--all --queue` every 10 minutes (`withoutOverlapping`),
  an account with 5 failures in a row is queued only every 6 hours. Made-up demo accounts (`acct_demo...`) are skipped.
  Webhooks only queue the sync (`LedgerEvents`): platform endpoint `balance.available`, `payout.*`, `transfer.*` (also the seller account the transfer
  went to), `charge.refunded`, `charge.dispute.*`; Connect endpoint `payout.*`, `balance.available` of `event.account` (unknown accounts ignored). The
  order handlers run first, the sync is queued after them.
- **Linking** (`LedgerLinker`, by ids only): charge -> `orders.stripe_charge_id`, else the payment intent, else the order marker in the charge's metadata
  (number and id must both match); refund -> the order of its charge, and the seller order when exactly one has that `stripe_refund_id`; dispute
  adjustment -> the order of the charge; transfer / transfer reversal -> `seller_orders.stripe_transfer_id` (the reversal through its transfer); on a seller
  account `payment` -> its `source_transfer` -> seller order, `payment_refund` -> the refunded payment's transfer (through the mirrored payment). No match:
  `unlinked` if money should have an owner (transfers, payments with an order marker), else `other` (payouts, fees, Wave charges and their refunds and
  disputes). Unlinked rows are tried again by every sync. The fee of a platform charge goes to `orders.stripe_fee_cents` (the page's charges, plus orders
  paid in the last week that still have none). Limit: `seller_orders.stripe_refund_id` holds only the latest refund, so an earlier partial refund links to
  the order but not to its seller order.
- **Reconciliation** (`Reconciler`, `stripe:reconcile {--no-sync} {--no-mail}` daily 04:17, "Check now" on the page): seven checks / thirteen
  `DiscrepancyKind`s as listed in R6.3 (charge missing / amount, refund total, transfer missing / amount / account / not received by the seller /
  reversal total, orphan transfer, payment of an unpaid or unknown order, failed payout, dispute without case). **Tax rule**: Stripe's refunded total
  for a charge matches when it equals the sum of `seller_orders.refunded_cents` (items + shipping), or, when every seller order is fully refunded, that
  sum plus `orders.tax_cents`, because refunding a whole untouched order refunds the whole payment (`RefundService`). Guards: events younger than
  `reconcile_grace_minutes` (30) or newer than the account's `synced_through` are left alone, orders paid before the ledger's first transaction, orders of
  the other Stripe mode (`cs_test_` / `cs_live_` session id) and the demo data's (`ch_demo_`) are not checked, and a check that could not run (ledger never
  synced) closes nothing. A difference is one row per kind + key; found again it only updates; no longer found it closes (`auto`); closed by an admin
  (`manual`, note required) it stays closed while expected / actual stay the same and opens again, as new, when they change. Admins get one mail
  (`Notifications\StripeDifferencesFound`, also a database notification, listed in the e-mail previews) with the differences nobody was told about
  (`notified_at`), the first 15 in a table.
- **Pages**: Filament group **Finance**: page `StripeLedger` (balance cards per currency, last sync / errors, "Sync now" = platform inline with a page
  limit + the sellers queued, transactions table with type / account / date / link filters, search, "Open in Stripe" (`StripeBalanceTransaction::stripeUrl()`,
  `/test/` in test mode), CSV of the filtered rows with formula-safe cells), footer widget `SellerBalances` (Stripe balance, next / last payout and the
  money the site holds per seller), resource `StripeDiscrepancyResource` (slug `reconciliation`: tabs open / fixed or closed / all, close with note, open
  again, "Check now", navigation badge), widget `FinanceStats` on the dashboard (open differences, balance, last read). Seller: Folio page
  `dashboard/selling/earnings` (route `selling.earnings`, sidebar link "Earnings", linked from the selling dashboard) with `Services\Marketplace\Ledger\SellerEarnings`
  (balance refreshed when older than `balance_max_age_minutes` (5) plus a queued sync, payouts with failures explained, transfers received as a Filament
  table, `PayoutService::outlook()` list via the shared component `marketplace/upcoming-payouts`); "Paid out" on the selling dashboard is the net
  sum of the seller's `payment` / `payment_refund` lines once their account was read (the site's records, flagged, before that).
- **Fees**: `MarginReport::stripeFee()` uses `orders.stripe_fee_cents` (the store's share by items + shipping when other sellers are in the order),
  else the estimate; `MarginRow::$estimatedFees` / `stripeFeeIsEstimate()`; "(est.)" on the Margins cards, header and cells, the CSV header and its new
  column "Stripe fee estimated"; `MarketplaceStats` has a "Stripe fees, 30 days" stat (est. only for orders without a fee).
- **Verified against Stripe's own definitions**: request parameters with `stripe/stripe-mock` (the real `StripeGateway` against it:
  `tests/Feature/Marketplace/Ledger/StripeMockTest.php`, runs with `STRIPE_MOCK_URL=http://localhost:12111`), and every object the fake serves against the
  OpenAPI spec (`FakeLedgerShapeTest` with `STRIPE_OPENAPI_SPEC=.../spec3.json`, `tests/Support/OpenApiShape.php`); both are skipped without the env var.
  Not verifiable here: the live Stripe API (no keys), the deep links into the Stripe dashboard (paths are the well-known ones, not tried signed in),
  and a real Connect account's balance transactions.
- **Tests**: `tests/Feature/Marketplace/Ledger/*` (sync, linking, reconciliation, gateway + fake, job / command / webhooks, admin pages, seller Earnings, actual fees,
  migrations), existing margin tests updated for the "(est.)" column.


## 12. Revision R7: GA4 analytics (owner decisions)

Today one GA4 tag (G-D6528Z9ZYR) is pasted into the app and marketing layouts, sends page views only, and runs on dev and
production alike. Decisions: purchases and refunds **from the server** (Measurement Protocol), a **consent banner with
Consent Mode v2**, and **no GA4 on dev** unless an ID is set there.

- Config: `GA4_MEASUREMENT_ID` and `GA4_API_SECRET` in `.env` (config/analytics.php). Empty ID = no tag at all. One partial
  replaces the two pasted snippets and serves every layout (app, marketing, auth pages where sensible). Production `.env`
  gets G-D6528Z9ZYR; `.env.example` documents both keys.
- Consent Mode v2: default denied (ad_storage, ad_user_data, ad_personalization, analytics_storage) before the tag; a small
  banner in the site's style (accept / reject, remembered in a cookie, re-openable from the footer "Cookie settings")
  updates consent. No GA cookies before consent.
- Browser events (gtag, GA4 recommended names and ecommerce item shape: item_id, item_name, item_category (design / driver
  / kit / used gear / completed speaker), item_brand (seller or SDLabs), price, quantity, currency): view_item_list,
  select_item, view_item, add_to_cart, remove_from_cart, view_cart, begin_checkout, add_shipping_info, search, sign_up,
  login, plus custom: file_download (design PDF/ZIP, snapshots), forum_open (discussion tab), listing_created,
  design_published, seller_onboarding_started/completed, review_submitted, report_problem. Livewire actions dispatch a
  browser event that one small script forwards to gtag, so server code decides what is sent.
- user_id: an HMAC of the user id (never the raw id or e-mail), set on gtag config for signed-in users.
- Server events (Measurement Protocol, queued job, never blocks checkout): purchase on payment (webhook markPaid), refund
  (full and partial) from RefundService; transaction_id = order number; value, tax, shipping, items; client_id from the
  `_ga` cookie captured at checkout (stored on the order) so the purchase joins the buyer's session; consent respected
  (no client id / skip when the buyer denied analytics). Idempotent per order/refund.
- Dev: no ID -> no tag, no MP calls. Docs: how to create the API secret, mark purchase as key event, DebugView.

### R7 implementation notes (where things live; done)

- **Config:** `config/analytics.php` (`GA4_MEASUREMENT_ID`, `GA4_API_SECRET`, consent cookie name `sdl_consent`). `Ga4::enabled()`
  is the one switch: empty ID = no tag, no banner, no footer link, no flashed events, no server calls.
- **Tag and forwarder:** `resources/themes/blank/partials/analytics.blade.php` (included first in `<head>` of both layouts,
  replacing the pasted snippets): Consent Mode v2 defaults denied, restores a stored "granted" before the tag, `user_id` on
  config, and `window.sdlGa4.track()`. It forwards (a) `<x-analytics.events :events>` rendered by plain pages and what
  `Ga4::flash()` queued in the session, (b) the window event `ga4` (`$this->dispatch('ga4', ...Ga4::payload(name, params))`
  from Livewire, `$dispatch('ga4', {name, params})` from Alpine), (c) clicks / submits on `data-ga4-click` / `data-ga4-submit`
  (sent with `transport_type: beacon`, so they survive leaving the page), (d) `search` is debounced to one event per search.
- **Banner:** `partials/consent-banner.blade.php` (before `</body>` in both layouts; Filament buttons, fixed bottom bar,
  stacks on phones). Choice in the first-party cookie `sdl_consent` (granted / denied, one year). Footer "Cookie settings"
  (`data-cookie-settings`) reopens it; only the marketing footer has the link (the dashboard layout has no footer).
  `sdl_consent` and `_ga` are in `EncryptCookies::$except` so checkout can read them.
- **Helper:** `App\Support\Analytics\Ga4`: `item()` (Design / Driver / Listing; a store driver listing uses `driver-{id}` so
  view_item and add_to_cart match), `cartLine()`, `cartItem()`, `orderItem()`, `ecommerce()` (currency from
  `marketplace.currency`, value, items), `itemList()`, `selectItem()`, `download()`, `userId()` (HMAC-SHA256 with `APP_KEY`),
  `flash()` / `pullFlashed()`, `clientId()` / `checkoutAttributes()`.
- **Server side:** migration `2026_10_28_000001` adds `orders.ga_client_id` + `orders.analytics_consent` (NULL = before R7 =
  treated as no consent) and the `analytics_events` table (unique key per order / refund, `sent_at` when GA4 accepted it).
  `CheckoutController::store` stores the `_ga` client id and the consent choice (client id only when consent was granted).
  `Ga4Reporter` (`purchase()` from `OrderFulfillment::markPaid`, `refund()` from `RefundService::process` and
  `syncFromCharge`) builds the Measurement Protocol body and queues `SendGa4Event` (5 tries, back-off, `Http` client, logs
  on final failure); the reporter catches everything, so checkout and the webhooks never see a GA4 problem. Purchase key
  `purchase:{order number}`; refund key `refund:{Stripe refund id}` (dashboard refunds: order id + total refunded so far).
  `value` is the items subtotal, with `tax` and `shipping` apart; a refund lists the items of fully refunded seller orders.
- **Events and where they fire:** view_item_list / select_item in the three card containers (designs, drivers, listings;
  `listName` prop); view_item on the design, driver and listing pages; add_to_cart in `BuyBox::add` (not for free
  designs); add_to_cart / remove_from_cart in the cart page (quantity changes send the difference); view_cart; begin_checkout
  and add_shipping_info (pay-form submit, physical carts only) on the checkout page; search in `TopSearchBar::updatedSearch`
  and for `?q=` on the marketplace; login / sign_up through `TrackAuthEvents` (flashed, sent by the next full page; social
  login counts as login); file_download on every snapshot download link (buy box, design Files tab, library, driver page);
  forum_open on the design Discussion tab; listing_created, design_published (create, publish toggle, edit that publishes),
  review_submitted (`DesignDescription::submitReview`), report_problem (orders page action), seller_onboarding_started
  (the Connect Stripe buttons) and seller_onboarding_completed (flashed by `ConnectController::returned`).
- **Not covered:** the devdojo auth pages (login / register) use the package's own layouts, so they send no page view; a
  social sign-up that creates the account is not a `sign_up`; the Filament admin panel has no tag; events inside a Livewire
  component that re-renders (pagination of the design / driver lists) are sent for the first page only.
- **Tests:** `tests/Feature/Analytics/Ga4PagesTest.php` (tag, consent defaults, items, browser events) and
  `Ga4ServerEventsTest.php` (purchase / refund payload, idempotency, consent / ID / secret skips, failure handling).
