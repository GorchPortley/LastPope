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
    | physical_payout_trigger: "shipped" transfers a seller's money once the
    | seller marks the parcel as shipped (protects buyers of used gear),
    | "paid" transfers as soon as the payment succeeds.
    | auto_complete_days: shipped orders complete by themselves after N days.
    |
    */

    'physical_payout_trigger' => env('MARKETPLACE_PHYSICAL_PAYOUT_TRIGGER', 'shipped'),
    'auto_complete_days' => (int) env('MARKETPLACE_AUTO_COMPLETE_DAYS', 14),

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
    |   POST /stripe/marketplace/webhook -> marketplace_webhook_secret
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
