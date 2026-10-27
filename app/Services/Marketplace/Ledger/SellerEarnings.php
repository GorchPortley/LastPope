<?php

namespace App\Services\Marketplace\Ledger;

use App\Enums\SellerOrderStatus;
use App\Jobs\SyncStripeLedger;
use App\Models\SellerOrder;
use App\Models\StripeBalance;
use App\Models\StripeBalanceTransaction;
use App\Models\StripePayout;
use App\Models\StripeSyncCursor;
use App\Models\User;
use App\Services\Marketplace\PayoutService;
use App\Support\StripeMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What a seller sees about their money (docs/PLAN.md 11, R6.2), always for that one seller's own Stripe account:
 * the balance (available, pending, on its way to the bank), the payouts to their bank, the transfers SDLabs made to them,
 * and what SDLabs still holds for them (PayoutService::outlook). Used by the Earnings page and the selling dashboard.
 */
class SellerEarnings
{
    /** Seller orders that count as sold: paid, not refunded or cancelled. */
    public const SOLD = [
        SellerOrderStatus::PAID, SellerOrderStatus::AWAITING_SHIPMENT, SellerOrderStatus::PARTIALLY_SHIPPED,
        SellerOrderStatus::SHIPPED, SellerOrderStatus::DELIVERED, SellerOrderStatus::COMPLETED,
    ];

    /** Seller orders whose payout is still to come (or held back). */
    private const WAITING = [
        SellerOrderStatus::AWAITING_SHIPMENT, SellerOrderStatus::PARTIALLY_SHIPPED, SellerOrderStatus::SHIPPED,
        SellerOrderStatus::DELIVERED, SellerOrderStatus::COMPLETED,
    ];

    public function __construct(
        private readonly PayoutService $payouts,
        private readonly LedgerSync $sync,
    ) {
    }

    /** The seller's connected account, null when they have none (or only a made-up demo one). */
    public function account(User $seller): ?string
    {
        $account = $seller->sellerProfile?->stripe_account_id;

        return filled($account) && ! StripeMode::isDemoAccount($account) ? (string) $account : null;
    }

    /** The account has been read from Stripe at least once. */
    public function isMirrored(User $seller): bool
    {
        $account = $this->account($seller);

        return $account !== null && StripeSyncCursor::query()->where(['account_key' => $account, 'livemode' => StripeMode::isLive()])->whereNotNull('synced_through')->exists();
    }

    /**
     * The balance per currency, the marketplace currency first.
     *
     * @return Collection<int, StripeBalance>
     */
    public function balances(User $seller): Collection
    {
        $account = $this->account($seller);

        if ($account === null) {
            return collect();
        }

        $main = strtolower((string) config('marketplace.currency', 'usd'));

        return StripeBalance::query()->forAccount($account)->currentMode()->get()
            ->sortBy(fn (StripeBalance $balance) => ($balance->currency === $main ? '0' : '1').$balance->currency)->values();
    }

    /**
     * Ask Stripe for the balance again when the one we have is older than marketplace.ledger.balance_max_age_minutes (or there
     * is none), and queue a full sync so payouts and transfers catch up. Never throws: the page then shows what it has.
     */
    public function refreshIfStale(User $seller): void
    {
        $account = $this->account($seller);

        if ($account === null || ! $this->sync->enabled()) {
            return;
        }

        $latest = StripeBalance::query()->forAccount($account)->currentMode()->max('refreshed_at');
        $maxAge = max(1, (int) config('marketplace.ledger.balance_max_age_minutes', 5));

        if ($latest !== null && now()->subMinutes($maxAge)->lt(\Illuminate\Support\Carbon::parse($latest))) {
            return;
        }

        $this->sync->refreshBalance($account);
        SyncStripeLedger::queueFor($account);
    }

    /**
     * The newest payouts to the seller's bank.
     *
     * @return Collection<int, StripePayout>
     */
    public function payouts(User $seller, int $limit = 12): Collection
    {
        $account = $this->account($seller);

        if ($account === null) {
            return collect();
        }

        return StripePayout::query()->forAccount($account)->currentMode()->orderByDesc('stripe_created_at')->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * The transfers SDLabs made to the seller's account, and the money taken back from them: the seller's own ledger lines of
     * type payment / payment_refund. An empty result for a seller without an account, never another seller's lines.
     *
     * @return Builder<StripeBalanceTransaction>
     */
    public function transfers(User $seller): Builder
    {
        $account = $this->account($seller);

        return StripeBalanceTransaction::query()
            ->currentMode()
            ->where('account', $account ?? '')
            ->whereIn('type', ['payment', 'payment_refund'])
            ->with(['order', 'sellerOrder']);
    }

    /**
     * What SDLabs has transferred to the seller, net of what it took back. From Stripe's ledger once the seller's account has been
     * read; until then from the site's own records ("confirmed" false).
     *
     * @return array{cents: int, confirmed: bool}
     */
    public function paidOut(User $seller): array
    {
        if ($this->isMirrored($seller)) {
            return ['cents' => (int) $this->transfers($seller)->sum('amount_cents'), 'confirmed' => true];
        }

        $records = SellerOrder::query()
            ->where('seller_id', $seller->getKey())
            ->whereNotNull('transferred_at')
            ->whereIn('status', array_map(fn (SellerOrderStatus $status) => $status->value, self::SOLD))
            ->sum('payout_cents');

        return ['cents' => (int) $records, 'confirmed' => false];
    }

    /**
     * The seller orders whose payout has not been made yet, each with PayoutService::outlook(): held back by a problem report,
     * scheduled for a date, or waiting for the shipment. Held first, then by date.
     *
     * @return Collection<int, array{sellerOrder: SellerOrder, outlook: array{state: string, at: ?\Carbon\CarbonInterface, reason: ?string}}>
     */
    public function upcoming(User $seller, int $limit = 25): Collection
    {
        return $this->waiting($seller)
            ->with(['order', 'items', 'seller', 'activeIssue'])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (SellerOrder $sellerOrder) => ['sellerOrder' => $sellerOrder, 'outlook' => $this->payouts->outlook($sellerOrder)])
            ->reject(fn (array $row) => $row['outlook']['state'] === 'none')
            ->sortBy(fn (array $row) => match ($row['outlook']['state']) {
                'held' => '0',
                'scheduled' => '1'.$row['outlook']['at']->format('YmdHis'),
                default => '2',
            })
            ->values();
    }

    /** What SDLabs still holds for the seller: the payouts not made yet (all of them, not only the listed ones), in cents. */
    public function holdingCents(User $seller): int
    {
        if ($seller->isPlatformSeller()) {
            return 0;
        }

        return (int) $this->waiting($seller)->sum('payout_cents');
    }

    /** @return Builder<SellerOrder> */
    private function waiting(User $seller): Builder
    {
        return SellerOrder::query()
            ->where('seller_id', $seller->getKey())
            ->whereNull('transferred_at')
            ->where('payout_cents', '>', 0)
            ->whereIn('status', array_map(fn (SellerOrderStatus $status) => $status->value, self::WAITING));
    }
}
