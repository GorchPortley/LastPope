<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | The marketplace runs in a single currency. All amounts are stored as
    | integer cents in `*_cents` columns and formatted with the money() helper.
    |
    */

    'currency' => env('MARKETPLACE_CURRENCY', 'usd'),

    /*
    |--------------------------------------------------------------------------
    | Platform fee
    |--------------------------------------------------------------------------
    |
    | Fee per seller order = round(items subtotal * fee_bps / 10000) + fee_fixed_cents.
    | The fee is charged on items, not on shipping. A seller profile's
    | `commission_bps` overrides `fee_bps`. 1000 bps = 10 %.
    |
    */

    'fee_bps' => (int) env('MARKETPLACE_FEE_BPS', 1000),
    'fee_fixed_cents' => (int) env('MARKETPLACE_FEE_FIXED_CENTS', 0),

    /*
    |--------------------------------------------------------------------------
    | Payout and completion rules
    |--------------------------------------------------------------------------
    |
    | Digital items are paid out to the seller as soon as the payment succeeds. For physical items
    | physical_payout_trigger decides when the seller's share is transferred:
    |   "shipped_delay"  physical_payout_delay_days after the seller marked the parcel shipped (default).
    |                    Confirming receipt does not make it earlier: the buyer can report a problem
    |                    during that time, and an unresolved problem holds the payout back.
    |   "shipped"        as soon as the seller marks the parcel as shipped.
    |   "paid"           as soon as the payment succeeds.
    | Whatever the trigger, a seller order with an unresolved problem report is never paid out.
    | auto_complete_days: shipped orders complete by themselves after N days (not while a problem is open).
    |
    */

    'physical_payout_trigger' => env('MARKETPLACE_PHYSICAL_PAYOUT_TRIGGER', 'shipped_delay'),
    'physical_payout_delay_days' => (int) env('MARKETPLACE_PHYSICAL_PAYOUT_DELAY_DAYS', 14),
    'auto_complete_days' => (int) env('MARKETPLACE_AUTO_COMPLETE_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | Problem reports ("Report a problem")
    |--------------------------------------------------------------------------
    |
    | issue_window_days: a buyer can report a problem until this many days after the parcel was shipped
    |   (digital items: after the payment). An unshipped physical order can be reported at any time.
    | issue_escalation_days: buyer and seller get this long to agree on their own before either side
    |   may hand the case to the admins.
    | issue_max_photos / issue_photo_max_kb: photos that can be attached to one message.
    |
    */

    'issue_window_days' => (int) env('MARKETPLACE_ISSUE_WINDOW_DAYS', 14),
    'issue_escalation_days' => (int) env('MARKETPLACE_ISSUE_ESCALATION_DAYS', 3),
    'issue_max_photos' => 5,
    'issue_photo_max_kb' => 8192,

    'stripe_tax' => (bool) env('MARKETPLACE_STRIPE_TAX', false),

    /*
    |--------------------------------------------------------------------------
    | Stock reservation
    |--------------------------------------------------------------------------
    |
    | Starting a checkout holds the listing stock in the order for this many minutes (also the
    | Stripe Checkout Session's lifetime). Other buyers cannot take those units meanwhile.
    | Stripe accepts 30 minutes to 24 hours, so the value is clamped to 30..1440.
    |
    */

    'reservation_minutes' => (int) env('MARKETPLACE_RESERVATION_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | Stripe
    |--------------------------------------------------------------------------
    |
    | Platform keys (the same account Wave's subscription billing uses) and the
    | signing secrets of the two marketplace webhook endpoints:
    |   POST /stripe/marketplace/webhook -> marketplace_webhook_secret   (payments, refunds, bank disputes)
    |   POST /stripe/connect/webhook     -> connect_webhook_secret
    |
    */

    'stripe' => [
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
        'marketplace_webhook_secret' => env('STRIPE_MARKETPLACE_WEBHOOK_SECRET'),
        'connect_webhook_secret' => env('STRIPE_CONNECT_WEBHOOK_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    */

    // ISO-3166 alpha-2 country preselected for new listings and seller profiles.
    'default_country' => env('MARKETPLACE_DEFAULT_COUNTRY', 'US'),

    // Prefix of human-readable order numbers (SDL-7KQ2M9).
    'order_number_prefix' => env('MARKETPLACE_ORDER_PREFIX', 'SDL'),

    // Password of every DemoSeeder account. Set DEMO_PASSWORD before seeding a site the public can reach.
    'demo_password' => env('DEMO_PASSWORD') ?: 'password',

    // Disk that holds design/driver source files and snapshot ZIPs. Never public.
    'private_disk' => 'private',

];
