<?php

namespace App\Services\Marketplace\Ledger;

use App\Enums\LedgerLink;
use App\Models\Order;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\StripeBalanceTransaction;
use Illuminate\Support\Collection;

/**
 * Ties mirrored balance transactions to the shop's records (docs/PLAN.md 11, R6.1). Pure matching on ids Stripe gives us:
 *
 *   platform  charge            -> orders.stripe_charge_id, else the payment intent, else the order marker in the charge's
 *                                 metadata (order_id + order_number, which Checkout puts there)
 *             refund            -> the order of its charge; its seller order when exactly one has that refund id
 *             dispute adjustment-> the order of the disputed charge
 *             transfer          -> seller_orders.stripe_transfer_id
 *             transfer reversal -> its transfer's seller order
 *   seller    payment (py_)     -> its source transfer -> seller order
 *   account   payment refund    -> the refunded payment's source transfer (through the mirrored payment) -> seller order
 *
 * Everything else (payouts, Stripe fees, top-ups ...) is not about orders ("other"). A charge or refund that matches no
 * order and carries no order marker is "other" too: the platform account is shared with Wave's subscription billing, so
 * those are not errors. Money that should have an owner but has none (a transfer no seller order claims ...) is "unlinked":
 * the reconciliation reports it, and every sync tries again (the site may only record its side a moment later).
 */
class LedgerLinker
{
    /**
     * Work out the links of $rows and save the ones that changed.
     *
     * @param  iterable<int, StripeBalanceTransaction>  $rows
     */
    public function link(iterable $rows): void
    {
        $rows = collect($rows)->values();

        if ($rows->isEmpty()) {
            return;
        }

        $context = $this->context($rows);

        foreach ($rows as $row) {
            [$status, $order, $sellerOrder, $sellerId] = $this->resolve($row, $context);

            $row->forceFill([
                'link_status' => $status,
                'order_id' => $order?->getKey(),
                'seller_order_id' => $sellerOrder?->getKey(),
                'seller_id' => $sellerId,
            ]);

            if ($row->isDirty()) {
                $row->save();
            }
        }
    }

    /** Try the rows of an account again that found no owner yet (newest first, a bounded number per run). */
    public function relinkUnlinked(?string $account, int $limit = 500): void
    {
        StripeBalanceTransaction::query()
            ->forAccount($account)
            ->where('link_status', LedgerLink::UNLINKED->value)
            ->orderByDesc('stripe_created_at')
            ->limit($limit)
            ->get()
            ->chunk(100)
            ->each(fn (Collection $chunk) => $this->link($chunk));
    }

    /**
     * Everything the rows point at, loaded in a few queries.
     *
     * @param  Collection<int, StripeBalanceTransaction>  $rows
     * @return array<string, mixed>
     */
    private function context(Collection $rows): array
    {
        $ids = fn (string $column): array => $rows->pluck($column)->filter()->unique()->values()->all();

        $metaNumbers = $rows->map(fn (StripeBalanceTransaction $row) => $row->source_metadata['order_number'] ?? null)->filter()->unique()->values()->all();
        $metaIds = $rows->map(fn (StripeBalanceTransaction $row) => $row->source_metadata['order_id'] ?? null)->filter()->unique()->values()->all();

        $orders = Order::query()
            ->where(fn ($q) => $q
                ->whereIn('stripe_charge_id', $ids('charge_id') ?: [''])
                ->orWhereIn('stripe_payment_intent_id', $ids('payment_intent_id') ?: [''])
                ->orWhereIn('number', $metaNumbers ?: [''])
                ->orWhereIn('id', $metaIds ?: [0]))
            ->get();

        $sellerOrders = SellerOrder::query()
            ->where(fn ($q) => $q
                ->whereIn('stripe_transfer_id', $ids('transfer_id') ?: [''])
                ->orWhereIn('stripe_refund_id', $ids('refund_id') ?: ['']))
            ->get();

        // A seller account's refund of a payment knows only the payment (py_...): the mirrored payment knows its transfer.
        $paymentTransfers = StripeBalanceTransaction::query()
            ->where('type', 'payment')
            ->whereIn('source_id', $rows->where('type', '!=', 'payment')->pluck('charge_id')->filter()->unique()->values()->all() ?: [''])
            ->pluck('transfer_id', 'source_id');

        $accounts = $rows->pluck('account')->merge($rows->pluck('counterparty'))->filter()->unique()->values()->all();

        return [
            'ordersByCharge' => $orders->whereNotNull('stripe_charge_id')->keyBy('stripe_charge_id'),
            'ordersByIntent' => $orders->whereNotNull('stripe_payment_intent_id')->keyBy('stripe_payment_intent_id'),
            'ordersByNumber' => $orders->keyBy('number'),
            'ordersById' => $orders->keyBy('id'),
            'sellerOrdersByTransfer' => $sellerOrders->whereNotNull('stripe_transfer_id')->keyBy('stripe_transfer_id'),
            'sellerOrdersByRefund' => $sellerOrders->whereNotNull('stripe_refund_id')->groupBy('stripe_refund_id'),
            'sellerOrdersById' => $sellerOrders->keyBy('id'),
            'paymentTransfers' => $paymentTransfers,
            'sellers' => SellerProfile::query()->whereIn('stripe_account_id', $accounts ?: [''])->pluck('user_id', 'stripe_account_id'),
        ];
    }

    /**
     * @param  array<string, mixed>  $c  context()
     * @return array{0: LedgerLink, 1: ?Order, 2: ?SellerOrder, 3: ?int}
     */
    private function resolve(StripeBalanceTransaction $row, array $c): array
    {
        $sellerId = $c['sellers'][$row->account ?? $row->counterparty ?? ''] ?? null;

        // The seller account's side of a transfer: payments and their refunds.
        if ($row->account !== null) {
            $transfer = match ($row->type) {
                'payment' => $row->transfer_id,
                'payment_refund', 'payment_failure_refund', 'payment_reversal' => $row->transfer_id ?? ($c['paymentTransfers'][$row->charge_id] ?? null),
                default => null,
            };

            if ($transfer === null) {
                return [$this->markedBySource($row) ? LedgerLink::UNLINKED : LedgerLink::OTHER, null, null, $sellerId];
            }

            return $this->viaTransfer($transfer, $c, $sellerId, $row);
        }

        return match ($row->type) {
            'charge' => $this->viaOrder($row, $c, $sellerId),
            'refund', 'refund_failure' => $this->viaOrder($row, $c, $sellerId, refund: true),
            'adjustment' => $this->viaOrder($row, $c, $sellerId, markerCounts: false),
            'transfer', 'transfer_cancel', 'transfer_failure', 'transfer_refund' => $row->transfer_id === null
                ? [LedgerLink::UNLINKED, null, null, $sellerId]
                : $this->viaTransfer($row->transfer_id, $c, $sellerId, $row),
            default => [LedgerLink::OTHER, null, null, $sellerId],
        };
    }

    /** @return array{0: LedgerLink, 1: ?Order, 2: ?SellerOrder, 3: ?int} */
    private function viaTransfer(string $transferId, array $c, ?int $sellerId, StripeBalanceTransaction $row): array
    {
        /** @var SellerOrder|null $sellerOrder */
        $sellerOrder = $c['sellerOrdersByTransfer'][$transferId] ?? null;

        if ($sellerOrder === null) {
            return [LedgerLink::UNLINKED, null, null, $sellerId];
        }

        $order = $c['ordersById'][$sellerOrder->order_id] ?? Order::find($sellerOrder->order_id);

        return [LedgerLink::LINKED, $order, $sellerOrder, $sellerId ?? $sellerOrder->seller_id];
    }

    /**
     * Charges, refunds and disputes of the platform account: the order of the charge. $markerCounts says whether an order
     * marker (Checkout's metadata) with no matching order makes it "unlinked" rather than "other"; a dispute carries none.
     *
     * @return array{0: LedgerLink, 1: ?Order, 2: ?SellerOrder, 3: ?int}
     */
    private function viaOrder(StripeBalanceTransaction $row, array $c, ?int $sellerId, bool $refund = false, bool $markerCounts = true): array
    {
        $order = ($row->charge_id !== null ? ($c['ordersByCharge'][$row->charge_id] ?? null) : null)
            ?? ($row->payment_intent_id !== null ? ($c['ordersByIntent'][$row->payment_intent_id] ?? null) : null)
            ?? $this->orderFromMarker($row, $c);

        if ($order === null) {
            return [$markerCounts && $this->markedBySource($row) ? LedgerLink::UNLINKED : LedgerLink::OTHER, null, null, $sellerId];
        }

        $sellerOrder = null;
        if ($refund && $row->refund_id !== null) {
            $matches = $c['sellerOrdersByRefund'][$row->refund_id] ?? collect();
            $sellerOrder = $matches->count() === 1 ? $matches->first() : null;
        }

        return [LedgerLink::LINKED, $order, $sellerOrder, $sellerOrder?->seller_id ?? $sellerId];
    }

    /** The order named in the object's metadata - its number, and its id when given, must both match. */
    private function orderFromMarker(StripeBalanceTransaction $row, array $c): ?Order
    {
        $meta = $row->source_metadata ?? [];
        $number = $meta['order_number'] ?? null;
        $id = $meta['order_id'] ?? null;

        $order = match (true) {
            filled($number) => $c['ordersByNumber'][$number] ?? null,
            filled($id) => $c['ordersById'][(int) $id] ?? null,
            default => null,
        };

        return $order !== null && (blank($id) || (int) $id === $order->getKey()) ? $order : null;
    }

    /** The object carries an order marker, so it looks like a marketplace payment even if the order is gone. */
    private function markedBySource(StripeBalanceTransaction $row): bool
    {
        $meta = $row->source_metadata ?? [];

        return filled($meta['order_id'] ?? null) || filled($meta['order_number'] ?? null) || filled($meta['seller_order_id'] ?? null);
    }
}
