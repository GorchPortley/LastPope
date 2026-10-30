# To do

The owner's backlog. Nothing here is started unless it says so. Each item has the context gathered so far, so a new session can pick it up without the old chat.

Working rules for all of these are the same as in `docs/PLAN.md` §2 and §7: push to `dev` only (the owner moves things to `production`), add new migration files only (never edit existing ones), and no model identifiers in commits.

## Owner's list

### 1. Admin CRUD pages for drivers, users and designs, at parity with the database: DONE (2026-10-30)
`DesignResource`, `DriverResource` and `UserResource` now edit every column (secrets excepted: password can be replaced, two-factor reset), with relation managers (design: drivers, snapshots, access, listings; driver: designs using it, snapshots, listings; user: designs, drivers, listings, orders, library) and a "Transfer ownership" action (`app/Services/Catalogue/OwnershipTransfer.php`). Tests: `tests/Feature/Admin/CatalogueAdminTest.php`.

Still open from this item: `drivers.size` is an integer column, so 6.5" drivers can't be stored exactly (needs a new migration to make it decimal). Reviews (`HasReviewRating`) have no admin page yet.

### 2. Author role for the blog, without admin access
Guest writers need to write posts without access to the admin panel, which is admins-only via `wave/src/User.php::canAccessPanel`. Plan agreed in chat:
- A new `writer`/`author` role (seed it in a new seeder or migration).
- A dashboard page **My Posts** (Folio + Volt + Filament table, like `pages/dashboard/designs/index.blade.php`) using `TiptapEditor` for the body with uploads on the public disk. Show it in the sidebar only for that role.
- Writers see and edit only their own posts (`author_id` forced to the current user). They can save drafts and **submit for review** (`PENDING` status already exists on `posts.status`); only admins publish.
- Optional: notify admins on submit; switch the admin `PostResource` editor to Tiptap too, so there's one editor.
- **Required before any non-admin writes:** the post page prints the body raw (`{!! $post->body !!}` in `pages/blog/[.Wave.Category-slug]/[.Wave.Post-slug].blade.php`). Render it through `<x-safe-html-renderer>` (`app/View/Components/SafeHtmlRenderer.php`) instead.

### 3. Royalty tracking: carts that come from a design
Investigate attributing driver sales to the design that led to them, so designers can earn a royalty when their design converts into SDLabs store driver sales. Questions to answer first:
- How to capture the source: an "add all drivers to cart" button on the design page, a `source_design_id` on cart items and order items, or a referral cookie or window.
- How long attribution lasts, and what counts (same order only, or later orders too).
- Royalty rate and who pays it (out of the platform's store margin), when it's paid (after the payout delay, not while there's an open problem report), and how it's paid (a Stripe transfer to the designer's connected account, the same as design payouts).
- Reporting for the designer (Earnings page) and for the admin (margin report).

Relevant code: `app/Services/Marketplace/CartService.php`, `OrderFulfillment::markPaid`, `PayoutService`, `MarginReport`, and the design page `pages/designs/design/[id].blade.php`.

### 4. Harden and restyle the design and driver database and pages
Public browse pages (`pages/designs/index`, `pages/drivers/index`) and detail pages (`pages/designs/design/[id]`, `pages/drivers/driver/[id]`), plus the dashboard forms. Ideas collected so far:
- Validation on the dashboard forms: numeric ranges, required fields, file types for FRD/ZMA.
- Rename the misleading **"Private Information"** section on the design form. Its description and parts list are public; only the files are private.
- Sanitize user rich text everywhere it's printed.
- The **"Collect Data"** export (`pages/dashboard/drivers/index.blade.php`, the `spec_export` action) includes unpublished and draft designs; limit it to published ones. Give it a proper `->label()`, since `->name()` is used now.
- Consider showing the export to admins on `DriverResource` too.
- Style alignment with the marketplace pages (`components/marketplace/*`).

### 5. Edit front page, database headings and dashboard home text from the admin panel
The front page, the headings and intro text of the design and driver database pages, and the dashboard home contents are hard-coded in Blade. Make them editable by admins: a Filament settings page storing them in Wave settings (as the seasonal theme does, `app/Support/SeasonalTheme.php` and the Appearance page), with the current text as defaults.

### 6. Moderator roles
Let trusted people run the site day to day without full admin rights. For example, moderate reviews and forum content, handle problem reports (`OrderIssueResource`), review submitted blog posts, and approve or edit drivers. This needs either a second Filament panel or per-resource policies with a `moderator` role, while keeping finance (Stripe ledger, payouts, refunds), suppliers and settings admin-only.

### 7. Better data visualization on design and driver pages
Today there's a frequency-response viewer (`app/Http/Livewire/FrequencyResponseViewer.php`, `DriverFrequencyResponseViewer.php`). Ideas: impedance plots from ZMA files, phase, overlaying several drivers or designer measurements against factory data, T/S parameter comparisons (factory vs. each designer's measured specs, which are already collected per design driver), and box and alignment previews.

## Also noted in earlier chats

- **Image serving.** Done (2026-10-30):
  - Avatars (`app/Support/Avatars.php`).
  - Resized WebP thumbnails, made on first request (`app/Support/Thumbnails.php`), on cards, galleries and blog cards.
  - Lazy loading.
  - Browse-page query counts (`/designs` went from 67 to 10).

  Still open:
  - The admin panel's image columns show originals.
  - Blog post bodies, Tiptap images inside descriptions, and banners are not resized.
  - Thumbnails of replaced listing photos are never deleted. They're harmless; to clean up, delete `storage/app/public/thumbs`, and the thumbnails are made again on use.
  - Later, a CDN (Cloudflare in front of the domain) for the static files.

- **Driver CSV import (admins only).** Either Filament's `ImportAction` + an Importer (needs new migrations for `imports`, `failed_import_rows`, `job_batches`, `notifications` and database notifications on the panel), or a simple custom upload action. Decide: who owns imported drivers, update vs. skip duplicates (brand + model), and which spec columns. Measurement files can't come through a CSV.
- **Supplier payment tracking.** Suppliers and dropshippers are paid outside the site, by hand. Purchase orders track ordering and shipping only. A "paid on / amount / reference" field on purchase orders and an unpaid-bills list would help bookkeeping.
- **Dev auto-deploy didn't fire** (prod's does). Diagnostic steps were given in chat. Check the branch checked out in `/SDLabs`, `DEPLOY_BRANCH` in `docker/deploy/deploy.env`, the systemd timer, and `sudo docker/deploy/auto-deploy.sh --dry-run`.
- **GA4 setup by the owner:** Tag Assistant, DebugView, and marking `purchase` as a key event.
