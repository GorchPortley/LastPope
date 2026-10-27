<?php

namespace App\Support;

/**
 * Whether the Stripe keys of this installation are live or test keys. The ledger mirror stores `livemode` on everything it
 * keeps and the pages and the reconciliation only look at the current mode, so switching from test to live keys never mixes
 * the two. (Balance transactions carry no livemode field of their own, which is why it comes from the key.)
 */
final class StripeMode
{
    public static function isLive(): bool
    {
        $key = (string) config('marketplace.stripe.secret_key');

        return str_starts_with($key, 'sk_live_') || str_starts_with($key, 'rk_live_');
    }

    /**
     * The demo data (DemoSeeder) carries made-up Stripe ids: seller accounts `acct_demo...`, charges `ch_demo_...`. They exist only
     * in our database, so the ledger sync leaves such accounts alone and the reconciliation such orders.
     */
    public static function isDemoAccount(?string $account): bool
    {
        return $account !== null && str_starts_with($account, 'acct_demo');
    }

    /** A link into the Stripe dashboard (test mode lives under /test/). $path has no leading slash: "payments/ch_...". */
    public static function dashboardUrl(string $path, ?bool $live = null): string
    {
        return 'https://dashboard.stripe.com/'.(($live ?? self::isLive()) ? '' : 'test/').ltrim($path, '/');
    }
}
