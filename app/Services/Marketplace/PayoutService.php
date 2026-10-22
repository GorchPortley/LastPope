<?php

namespace App\Services\Marketplace;

use App\Enums\IssueResolution;
use App\Enums\SellerOrderStatus;
use App\Models\Order;
use App\Models\SellerOrder;
use App\Notifications\PayoutSent;
use App\Services\Payments\Exceptions\PaymentGatewayException;
use App\Services\Payments\PaymentGateway;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Moves a seller's share to their connected account (docs/PLAN.md 3.8, separate charges and transfers).
 *
 * A seller order is DUE when the buyer paid, it has no unresolved problem report and
 *  - it contains only digital items (due on payment), or
 *  - it has physical items and, by config('marketplace.physical_payout_trigger'):
 *      shipped_delay  the parcel was marked shipped at least physical_payout_delay_days ago (default),
 *      shipped        the parcel was marked shipped,
 *      paid           the payment succeeded.
 * Confirming receipt never makes a payout earlier. Platform sellers (admins), refunded/cancelled orders and
 * seller orders that lost a bank dispute are never paid out. Safe to call twice: the row is locked, a
 * transfer id is stored, and Stripe gets an idempotency key.
 */
class PayoutService
{
    public function __construct(private readonly PaymentGateway $gateway)
    {
    }

    public function isDue(SellerOrder $sellerOrder): bool
    {
        $sellerOrder->loadMissing(['order', 'seller', 'items']);

        if ($sellerOrder->isTransferred() || $sellerOrder->payout_cents <= 0) {
            return false;
        }

        // Not PAID: that marks a paid seller order whose goods could not be delivered (awaiting its automatic refund).
        if (! in_array($sellerOrder->status, [
            SellerOrderStatus::AWAITING_SHIPMENT,
            SellerOrderStatus::SHIPPED,
            SellerOrderStatus::DELIVERED,
            SellerOrderStatus::COMPLETED,
        ], true)) {
            return false;
        }

        if (! $sellerOrder->order?->isPaid() || $sellerOrder->seller === null || $sellerOrder->seller->isPlatformSeller()) {
            return false;
        }

        if ($this->holdReason($sellerOrder) !== null) {
            return false;
        }

        $dueAt = $this->dueAt($sellerOrder);

        return $dueAt !== null && $dueAt->lte(now());
    }

    /**
     * When the payout becomes due if nothing holds it back: at payment for digital items, and for physical
     * ones by the payout trigger. Null while the moment is not known yet (not shipped, not paid).
     */
    public function dueAt(SellerOrder $sellerOrder): ?CarbonInterface
    {
        $sellerOrder->loadMissing(['order', 'items']);

        $paidAt = $sellerOrder->order?->paid_at;
        if ($paidAt === null) {
            return null;
        }

        if (! $sellerOrder->hasPhysicalItems()) {
            return $paidAt;
        }

        $shipped = in_array($sellerOrder->status, [SellerOrderStatus::SHIPPED, SellerOrderStatus::DELIVERED, SellerOrderStatus::COMPLETED], true)
            ? $sellerOrder->shipped_at
            : null;

        return match ($this->trigger()) {
            'paid' => $paidAt,
            'shipped' => $shipped,
            default => $shipped?->copy()->addDays($this->delayDays()),
        };
    }

    /**
     * Why the payout must wait even when it is due: "issue" (an unresolved problem report, also a bank
     * dispute) or "dispute_lost" (the buyer won a chargeback: an admin decides about the money, see
     * IssueService::recoverFromSeller()). Null = no hold.
     *
     * @return 'issue'|'dispute_lost'|null
     */
    public function holdReason(SellerOrder $sellerOrder): ?string
    {
        if ($sellerOrder->issues()->unresolved()->exists()) {
            return 'issue';
        }

        if ($sellerOrder->issues()->where('resolution', IssueResolution::CHARGEBACK_LOST->value)->whereNull('recovered_at')->exists()) {
            return 'dispute_lost';
        }

        return null;
    }

    /** "shipped_delay" (default), "shipped" or "paid". Anything else counts as the default. */
    public function trigger(): string
    {
        $trigger = (string) config('marketplace.physical_payout_trigger', 'shipped_delay');

        return in_array($trigger, ['shipped_delay', 'shipped', 'paid'], true) ? $trigger : 'shipped_delay';
    }

    public function delayDays(): int
    {
        return max(0, (int) config('marketplace.physical_payout_delay_days', 14));
    }

    /** When a seller is paid for physical items, as a phrase: "14 days after you mark the parcel as shipped". */
    public function timingPhrase(): string
    {
        return match ($this->trigger()) {
            'paid' => 'right after the buyer pays',
            'shipped' => 'as soon as you mark the parcel as shipped',
            default => $this->delayDays().' '.str('day')->plural($this->delayDays()).' after you mark the parcel as shipped',
        };
    }

    /** The sentence sellers read about their payout (dashboard, ship dialog, e-mails). */
    public function timingNote(): string
    {
        return 'Digital items are paid out right after the payment, physical items '.$this->timingPhrase()
            .'. If the buyer reports a problem the payout waits until it is resolved.';
    }

    /**
     * Where a seller order's payout stands, for the dashboards:
     *   none        nothing to pay out (platform seller, refunded, no payout)
     *   paid_out    transferred already
     *   held        a problem report or a lost dispute holds it back
     *   scheduled   will be released at $at (the hourly job)
     *   after_ship  physical order, released after shipping (no date yet)
     *
     * @return array{state: 'none'|'paid_out'|'held'|'scheduled'|'after_ship', at: ?CarbonInterface, reason: ?string}
     */
    public function outlook(SellerOrder $sellerOrder): array
    {
        $sellerOrder->loadMissing(['order', 'seller', 'items']);

        if ($sellerOrder->isTransferred()) {
            return ['state' => 'paid_out', 'at' => $sellerOrder->transferred_at, 'reason' => null];
        }

        if ($sellerOrder->payout_cents <= 0 || $sellerOrder->seller?->isPlatformSeller()
            || ! in_array($sellerOrder->status, [SellerOrderStatus::AWAITING_SHIPMENT, SellerOrderStatus::SHIPPED, SellerOrderStatus::DELIVERED, SellerOrderStatus::COMPLETED], true)) {
            return ['state' => 'none', 'at' => null, 'reason' => null];
        }

        $reason = $this->holdReason($sellerOrder);
        $dueAt = $this->dueAt($sellerOrder);

        return match (true) {
            $reason !== null => ['state' => 'held', 'at' => $dueAt, 'reason' => $reason],
            $dueAt === null => ['state' => 'after_ship', 'at' => null, 'reason' => null],
            default => ['state' => 'scheduled', 'at' => $dueAt, 'reason' => null],
        };
    }

    /**
     * Transfer the payout if it is due and not made yet. Returns the transfer id when a transfer was
     * created now, null otherwise (not due, already paid, or Stripe failed: it is retried by
     * releaseDue(), which the hourly marketplace:auto-complete command runs). Never throws for Stripe errors.
     */
    public function transferIfDue(SellerOrder $sellerOrder): ?string
    {
        $transferId = null;

        try {
            $transferId = DB::transaction(function () use ($sellerOrder) {
                /** @var SellerOrder|null $locked */
                $locked = SellerOrder::query()->whereKey($sellerOrder->getKey())->lockForUpdate()->first();

                if ($locked === null || ! $this->isDue($locked)) {
                    return null;
                }

                $account = $locked->seller->sellerProfile?->stripe_account_id;
                if (blank($account)) {
                    Log::warning('Payout skipped: seller has no connected account.', ['seller_order_id' => $locked->getKey()]);

                    return null;
                }

                $order = $locked->order;
                $id = $this->gateway->createTransfer(
                    $locked->payout_cents,
                    $account,
                    $order->number,
                    $order->stripe_charge_id,
                    [
                        'order_id' => $order->getKey(),
                        'order_number' => $order->number,
                        'seller_order_id' => $locked->getKey(),
                    ],
                );

                $locked->forceFill(['stripe_transfer_id' => $id, 'transferred_at' => now()])->save();

                return $id;
            });
        } catch (PaymentGatewayException $e) {
            report($e);
            Log::error('Payout transfer failed; it will be retried.', ['seller_order_id' => $sellerOrder->getKey()]);

            return null;
        }

        if ($transferId !== null) {
            $sellerOrder->refresh();
            $this->notifySeller($sellerOrder);
        }

        return $transferId;
    }

    /** Pay out every due seller order of one order. @return int transfers made */
    public function releaseDueFor(Order $order): int
    {
        $made = 0;

        foreach ($order->sellerOrders()->get() as $sellerOrder) {
            if ($this->transferIfDue($sellerOrder) !== null) {
                $made++;
            }
        }

        return $made;
    }

    /** Release every payout that is due now, retrying failed transfers too (scheduled hourly). @return int transfers made */
    public function releaseDue(): int
    {
        $made = 0;

        SellerOrder::query()
            ->whereNull('transferred_at')
            ->where('payout_cents', '>', 0)
            ->whereDoesntHave('issues', fn ($issues) => $issues->unresolved())
            ->whereIn('status', [
                SellerOrderStatus::AWAITING_SHIPMENT->value,
                SellerOrderStatus::SHIPPED->value,
                SellerOrderStatus::DELIVERED->value,
                SellerOrderStatus::COMPLETED->value,
            ])
            ->orderBy('id')
            ->chunkById(100, function ($sellerOrders) use (&$made) {
                foreach ($sellerOrders as $sellerOrder) {
                    if ($this->transferIfDue($sellerOrder) !== null) {
                        $made++;
                    }
                }
            });

        return $made;
    }

    private function notifySeller(SellerOrder $sellerOrder): void
    {
        try {
            $sellerOrder->seller?->notify(new PayoutSent($sellerOrder));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
