<?php

namespace App\Services\Marketplace;

use App\Enums\FulfillmentSource;
use App\Enums\SellerOrderStatus;
use App\Enums\SupplierOrderStatus;
use App\Exceptions\Marketplace\InvalidOrderStateException;
use App\Mail\PurchaseOrderMail;
use App\Models\Listing;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SellerOrder;
use App\Models\Shipment;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderItem;
use App\Models\User;
use App\Notifications\PurchaseOrdersToSend;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

/**
 * Purchase orders (POs) to suppliers for the dropshipped lines of paid store orders (docs/PLAN.md 8.3).
 *
 *   createForOrder()      OrderFulfillment::markPaid: one PO per supplier per seller order, idempotent (a unique key
 *                         on seller_order_id + supplier_id backs it up); returns only the POs created now
 *   processNew()          after the payment committed: auto-send POs of suppliers with auto_send_purchase_orders and tell
 *                         the admins how many POs still wait to be sent ("New store order: 2 purchase orders to send")
 *   send()                admin: e-mail the PO to the supplier's order_email (PENDING -> SENT, also re-sends)
 *   markPlacedManually()  admin: the order was placed in the supplier's web portal (PENDING -> SENT, with their reference)
 *   confirm()             admin: the supplier confirmed (SENT -> CONFIRMED)
 *   recordSupplierCosts() admin: what the supplier charges for shipping / per unit / per order (feeds the margin report)
 *   markShipped()         admin: the supplier shipped; creates a shipment for the PO's lines (the buyer is told)
 *   cancel()              admin: cancel the whole PO (after the supplier agreed)
 *   cancelLines() / handleRefund()   refunds: lines of a PENDING PO are cancelled, a PO that was already sent is flagged
 *                         PROBLEM with the note "cancel with supplier"
 *   flagProblem()         mark a PO as needing attention, with a note
 *   toCsv()               the PO as a CSV the admin can upload to the supplier's portal
 *
 * Every admin action authorizes through SupplierOrderPolicy (admins only). System paths (createForOrder, processNew,
 * cancelLines, handleRefund, sendAutomatically) do not: never call them from a request that a user controls.
 */
class PurchaseOrderService
{
    public function __construct(
        private readonly ShipmentService $shipments,
        private readonly ParcelPlanner $planner,
    ) {
    }

    // --- Creating ------------------------------------------------------------------------------------------------

    /**
     * Create the purchase orders of a paid order: one per supplier per seller order, for the lines checkout decided a
     * supplier ships. Call it inside the transaction that marks the order paid. Calling it again changes nothing. Seller
     * orders that were not fulfilled (unpaid, refunded, cancelled) get none.
     *
     * @return Collection<int, SupplierOrder> the purchase orders created by this call
     */
    public function createForOrder(Order $order): Collection
    {
        $created = collect();

        $sellerOrders = SellerOrder::query()
            ->where('order_id', $order->getKey())
            ->whereIn('status', [
                SellerOrderStatus::AWAITING_SHIPMENT->value,
                SellerOrderStatus::PARTIALLY_SHIPPED->value,
                SellerOrderStatus::SHIPPED->value,
                SellerOrderStatus::DELIVERED->value,
                SellerOrderStatus::COMPLETED->value,
            ])
            ->with('items')
            ->get();

        foreach ($sellerOrders as $sellerOrder) {
            $bySupplier = $sellerOrder->items
                ->filter(fn (OrderItem $item) => ! $item->is_digital && $item->fulfillment_source === FulfillmentSource::SUPPLIER && $item->supplier_id !== null)
                ->groupBy('supplier_id');

            foreach ($bySupplier as $supplierId => $items) {
                if (SupplierOrder::query()->where('seller_order_id', $sellerOrder->getKey())->where('supplier_id', $supplierId)->exists()) {
                    continue;
                }

                $supplier = Supplier::find($supplierId);
                if ($supplier === null) {
                    Log::error('A supplier line of a paid order has no supplier any more; no purchase order was created.', ['seller_order_id' => $sellerOrder->getKey(), 'supplier_id' => $supplierId]);

                    continue;
                }

                try {
                    $created->push($this->createPurchaseOrder($order, $sellerOrder, $supplier, $items));
                } catch (UniqueConstraintViolationException) {
                    // A concurrent call created it a moment ago.
                }
            }
        }

        return $created;
    }

    /** @param  Collection<int, OrderItem>  $items */
    private function createPurchaseOrder(Order $order, SellerOrder $sellerOrder, Supplier $supplier, Collection $items): SupplierOrder
    {
        return DB::transaction(function () use ($order, $sellerOrder, $supplier, $items) {
            $listings = Listing::withTrashed()->whereIn('id', $items->where('purchasable_type', (new Listing)->getMorphClass())->pluck('purchasable_id'))->get()->keyBy('id');

            $po = SupplierOrder::create([
                'order_id' => $order->getKey(),
                'seller_order_id' => $sellerOrder->getKey(),
                'supplier_id' => $supplier->getKey(),
                'status' => SupplierOrderStatus::PENDING,
                'shipping_address' => $order->shipping_address,
                'dropship_fee_cents' => $supplier->dropship_fee_cents,
            ]);

            $total = 0;
            foreach ($items as $item) {
                $listing = $listings->get($item->purchasable_id);
                $unitCost = (int) ($item->unit_cost_cents ?? $listing?->cost_cents ?? 0);
                $total += $unitCost * $item->quantity;

                SupplierOrderItem::create([
                    'supplier_order_id' => $po->getKey(),
                    'order_item_id' => $item->getKey(),
                    'title' => $item->title,
                    'supplier_sku' => $listing?->supplier_sku,
                    'quantity' => $item->quantity,
                    'unit_cost_cents' => $unitCost,
                ]);
            }

            $po->forceFill(['items_cost_cents' => $total])->save();

            return $po->load('items');
        });
    }

    /**
     * After the payment has been committed: e-mail the purchase orders of suppliers that want them automatically and tell
     * the admins about the ones that still wait for a person. A failing mailer never fails the payment.
     *
     * @param  Collection<int, SupplierOrder>  $created
     * @return int purchase orders left to send
     */
    public function processNew(Collection $created): int
    {
        $waiting = $created->reject(function (SupplierOrder $po) {
            return $po->supplier->auto_send_purchase_orders && $this->sendAutomatically($po);
        });

        if ($waiting->isNotEmpty()) {
            try {
                $order = $waiting->first()->order;
                $admins = User::role('admin')->get();
                foreach ($admins as $admin) {
                    $admin->notify(new PurchaseOrdersToSend($order, $waiting->count()));
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $waiting->count();
    }

    // --- Sending and following up --------------------------------------------------------------------------------

    /**
     * E-mail the purchase order to the supplier (queued). A pending PO becomes SENT; a sent one can be sent again.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws InvalidOrderStateException  no order e-mail, or the PO is cancelled / shipped
     */
    public function send(User $actor, SupplierOrder $po): SupplierOrder
    {
        Gate::forUser($actor)->authorize('update', $po);

        return $this->deliver($po);
    }

    /** Send without a person (suppliers with auto_send_purchase_orders). False when it could not be sent; the PO stays PENDING. */
    public function sendAutomatically(SupplierOrder $po): bool
    {
        try {
            $this->deliver($po);

            return true;
        } catch (InvalidOrderStateException $e) {
            Log::warning('A purchase order could not be sent automatically.', ['purchase_order' => $po->number, 'reason' => $e->getMessage()]);

            return false;
        }
    }

    /** @throws InvalidOrderStateException */
    private function deliver(SupplierOrder $po): SupplierOrder
    {
        $po->loadMissing('supplier');

        if (blank($po->supplier->order_email)) {
            throw new InvalidOrderStateException('This supplier has no order e-mail address. Add one, or mark the order as placed manually.');
        }

        $previous = null;

        DB::transaction(function () use ($po, &$previous) {
            $locked = SupplierOrder::query()->whereKey($po->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->canBeSent()) {
                throw new InvalidOrderStateException('This purchase order is '.strtolower($locked->status->label()).' and cannot be sent.');
            }

            $previous = [$locked->status, $locked->sent_at, $locked->placed_manually];

            $locked->forceFill([
                'status' => SupplierOrderStatus::SENT,
                'sent_at' => $locked->sent_at ?? now(),
                'placed_manually' => false,
            ])->save();
        });

        try {
            Mail::to($po->supplier->order_email)->send(new PurchaseOrderMail($po->fresh(['supplier', 'items', 'order'])));
        } catch (\Throwable $e) {
            report($e);
            $po->forceFill(['status' => $previous[0], 'sent_at' => $previous[1], 'placed_manually' => $previous[2]])->save();

            throw new InvalidOrderStateException('The purchase order could not be e-mailed. Try again, or mark it as placed manually.');
        }

        return $po->fresh();
    }

    /**
     * The order was placed in the supplier's web portal (or by phone): PENDING -> SENT, with the supplier's reference.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws \Illuminate\Validation\ValidationException  no reference
     * @throws InvalidOrderStateException  not pending
     */
    public function markPlacedManually(User $actor, SupplierOrder $po, string $reference): SupplierOrder
    {
        Gate::forUser($actor)->authorize('update', $po);

        $reference = $this->validatedReference($reference, required: true);

        return $this->transition($po, [SupplierOrderStatus::PENDING], function (SupplierOrder $locked) use ($reference) {
            $locked->forceFill([
                'status' => SupplierOrderStatus::SENT,
                'sent_at' => $locked->sent_at ?? now(),
                'placed_manually' => true,
                'supplier_reference' => $reference,
            ])->save();
        }, 'Only a purchase order that has not been sent can be marked as placed.');
    }

    /**
     * The supplier acknowledged the order (SENT -> CONFIRMED), optionally with their order number.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws InvalidOrderStateException  not sent
     */
    public function confirm(User $actor, SupplierOrder $po, ?string $reference = null): SupplierOrder
    {
        Gate::forUser($actor)->authorize('update', $po);

        $reference = $this->validatedReference($reference, required: false);

        return $this->transition($po, [SupplierOrderStatus::SENT, SupplierOrderStatus::PROBLEM], function (SupplierOrder $locked) use ($reference) {
            $locked->forceFill([
                'status' => SupplierOrderStatus::CONFIRMED,
                'confirmed_at' => $locked->confirmed_at ?? now(),
                'supplier_reference' => $reference ?? $locked->supplier_reference,
            ])->save();
        }, 'Only a sent purchase order can be confirmed.');
    }

    /**
     * Enter what the supplier charges: shipping for the parcel, the unit costs per line (keyed by purchase order line id)
     * and/or the dropship fee. Null leaves a value as it was. Line costs also replace the cost snapshot of the order
     * items, so the margin report uses the real numbers.
     *
     * @param  array<int, int>  $unitCostCents  supplier_order_items.id => cents
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function recordSupplierCosts(User $actor, SupplierOrder $po, ?int $shippingCostCents = null, array $unitCostCents = [], ?int $dropshipFeeCents = null): SupplierOrder
    {
        Gate::forUser($actor)->authorize('update', $po);

        foreach ([$shippingCostCents, $dropshipFeeCents, ...array_values($unitCostCents)] as $value) {
            if ($value !== null && $value < 0) {
                throw new InvalidOrderStateException('Costs cannot be negative.');
            }
        }

        DB::transaction(function () use ($po, $shippingCostCents, $unitCostCents, $dropshipFeeCents) {
            $locked = SupplierOrder::query()->whereKey($po->getKey())->lockForUpdate()->firstOrFail();

            foreach ($locked->items()->whereIn('id', array_keys($unitCostCents))->get() as $line) {
                $line->forceFill(['unit_cost_cents' => $unitCostCents[$line->getKey()]])->save();
                OrderItem::query()->whereKey($line->order_item_id)->update(['unit_cost_cents' => $line->unit_cost_cents]);
            }

            $locked->forceFill(array_filter([
                'shipping_cost_cents' => $shippingCostCents,
                'dropship_fee_cents' => $dropshipFeeCents,
            ], fn ($value) => $value !== null) + [
                'items_cost_cents' => (int) $locked->activeItems()->get()->sum(fn (SupplierOrderItem $line) => $line->lineCostCents()),
            ])->save();
        });

        return $po->fresh();
    }

    /**
     * The supplier shipped: a shipment with everything of this purchase order that is not in one yet (carrier,
     * tracking, link as the supplier gave them). The buyer is told ("parcel 2 of 2"); the seller order becomes SHIPPED
     * once every parcel is out.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws \Illuminate\Validation\ValidationException
     * @throws InvalidOrderStateException  not sent yet, cancelled, or nothing left to ship
     */
    public function markShipped(User $actor, SupplierOrder $po, string $carrier, string $trackingNumber, ?string $trackingUrl = null): Shipment
    {
        Gate::forUser($actor)->authorize('update', $po);

        $data = $this->shipments->validateTracking($carrier, $trackingNumber, $trackingUrl);
        $sellerOrder = null;

        $shipment = DB::transaction(function () use ($po, $data, &$sellerOrder) {
            /** @var SupplierOrder $locked */
            $locked = SupplierOrder::query()->whereKey($po->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === SupplierOrderStatus::PENDING) {
                throw new InvalidOrderStateException('Send the purchase order to the supplier (or mark it as placed) before you enter their tracking.');
            }

            if ($locked->status === SupplierOrderStatus::CANCELLED) {
                throw new InvalidOrderStateException('This purchase order was cancelled.');
            }

            $sellerOrder = $this->shipments->lock($locked->sellerOrder);

            if (! $sellerOrder->status->awaitsShipment()) {
                throw new InvalidOrderStateException('This order cannot be shipped right now.');
            }

            $remaining = $this->planner->remaining($sellerOrder->unsetRelation('items')->unsetRelation('shipments'));
            $quantities = [];
            foreach ($locked->activeItems()->get() as $line) {
                if (isset($remaining[$line->order_item_id])) {
                    $quantities[$line->order_item_id] = $remaining[$line->order_item_id];
                }
            }

            $shipment = $this->shipments->addShipment($sellerOrder, $quantities, $data, $locked);

            // This purchase order is done once none of its lines has units left to ship.
            $left = $this->planner->remaining($sellerOrder->unsetRelation('items')->unsetRelation('shipments'));
            $open = $locked->activeItems()->get()->contains(fn (SupplierOrderItem $line) => isset($left[$line->order_item_id]));

            if (! $open) {
                $locked->forceFill(['status' => SupplierOrderStatus::SHIPPED, 'shipped_at' => $shipment->shipped_at])->save();
            }

            return $shipment;
        });

        $this->shipments->afterShipment($sellerOrder->fresh(), $shipment);

        return $shipment;
    }

    /**
     * Cancel the whole purchase order after the supplier agreed: every line is cancelled and the PO is CANCELLED.
     * Shipped lines stay shipped. Does not refund the buyer.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws InvalidOrderStateException  already shipped or cancelled
     */
    public function cancel(User $actor, SupplierOrder $po, ?string $note = null): SupplierOrder
    {
        Gate::forUser($actor)->authorize('update', $po);

        return $this->transition($po, [SupplierOrderStatus::PENDING, SupplierOrderStatus::SENT, SupplierOrderStatus::CONFIRMED, SupplierOrderStatus::PROBLEM], function (SupplierOrder $locked) use ($note) {
            $locked->items()->whereNull('cancelled_at')->update(['cancelled_at' => now()]);
            $locked->forceFill([
                'status' => SupplierOrderStatus::CANCELLED,
                'cancelled_at' => now(),
                'items_cost_cents' => 0,
            ]);
            $this->appendNote($locked, $note ?: 'Cancelled.');
            $locked->save();
        }, 'This purchase order is shipped or cancelled already.');
    }

    // --- Refunds -------------------------------------------------------------------------------------------------

    /**
     * Lines of this purchase order will not be delivered to the buyer any more (refunded). A PENDING PO simply drops
     * them (all lines gone: CANCELLED). A PO the supplier already has is flagged PROBLEM with the note "cancel with
     * supplier" so an admin cancels it there; a shipped one only gets a note. Safe to call twice.
     *
     * @param  array<int, int>  $orderItemIds
     */
    public function cancelLines(SupplierOrder $po, array $orderItemIds, ?string $reason = null): SupplierOrder
    {
        DB::transaction(function () use ($po, $orderItemIds, $reason) {
            /** @var SupplierOrder $locked */
            $locked = SupplierOrder::query()->whereKey($po->getKey())->lockForUpdate()->firstOrFail();
            $lines = $locked->activeItems()->whereIn('order_item_id', $orderItemIds)->get();

            if ($lines->isEmpty() || $locked->status === SupplierOrderStatus::CANCELLED) {
                return;
            }

            $what = $lines->map(fn (SupplierOrderItem $line) => ($line->quantity > 1 ? $line->quantity.' x ' : '').$line->title.($line->supplier_sku ? ' ('.$line->supplier_sku.')' : ''))->implode('; ');
            $why = $reason ? ' '.rtrim($reason, '.').'.' : '';

            if ($locked->status === SupplierOrderStatus::PENDING) {
                $lines->each(fn (SupplierOrderItem $line) => $line->forceFill(['cancelled_at' => now()])->save());
                $locked->unsetRelation('items');
                $active = $locked->activeItems()->get();

                $locked->items_cost_cents = (int) $active->sum(fn (SupplierOrderItem $line) => $line->lineCostCents());
                if ($active->isEmpty()) {
                    $locked->status = SupplierOrderStatus::CANCELLED;
                    $locked->cancelled_at = now();
                }
                $this->appendNote($locked, 'Not sent: the buyer was refunded, so these lines were dropped: '.$what.'.'.$why);
                $locked->save();

                return;
            }

            if ($locked->status === SupplierOrderStatus::SHIPPED) {
                $this->appendNote($locked, 'Refunded after it shipped: '.$what.'.'.$why.' Arrange a return or keep the goods in stock.');
                $locked->save();

                return;
            }

            $locked->status = SupplierOrderStatus::PROBLEM;
            $this->appendNote($locked, 'Cancel with supplier: this purchase order was already sent, but the buyer was refunded for: '.$what.'.'.$why.' Ask the supplier to cancel these lines.');
            $locked->save();
        });

        return $po->fresh();
    }

    /**
     * A seller order was refunded in full: deal with its purchase orders (see cancelLines()).
     */
    public function handleRefund(SellerOrder $sellerOrder, ?string $reason = null): void
    {
        $orderItemIds = $sellerOrder->items()->pluck('id')->all();

        foreach ($sellerOrder->supplierOrders()->get() as $po) {
            $this->cancelLines($po, $orderItemIds, $reason);
        }
    }

    /**
     * Flag a purchase order that needs attention (a back-order, a wrong item, ...). Admin action when $actor is given.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function flagProblem(SupplierOrder $po, string $note, ?User $actor = null): SupplierOrder
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('update', $po);
        }

        DB::transaction(function () use ($po, $note) {
            $locked = SupplierOrder::query()->whereKey($po->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [SupplierOrderStatus::CANCELLED, SupplierOrderStatus::SHIPPED], true)) {
                $locked->status = SupplierOrderStatus::PROBLEM;
            }

            $this->appendNote($locked, $note);
            $locked->save();
        });

        return $po->fresh();
    }

    // --- Reading -------------------------------------------------------------------------------------------------

    /** Purchase orders waiting to be sent (for a navigation badge). */
    public function toSendCount(): int
    {
        return SupplierOrder::query()->toSend()->count();
    }

    /**
     * The purchase order as CSV, one row per active line with the ship-to repeated (for a supplier's portal or
     * spreadsheet): number, SKU, item, quantity, unit cost, line cost, recipient, address, phone. No buyer e-mail.
     */
    public function toCsv(SupplierOrder $po): string
    {
        $po->loadMissing(['supplier', 'activeItems']);
        $address = $po->shipping_address ?? [];

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['PO number', 'Supplier SKU', 'Item', 'Quantity', 'Unit cost', 'Line cost', 'Ship to name', 'Address line 1', 'Address line 2', 'City', 'State', 'Postal code', 'Country', 'Phone'], escape: '');

        foreach ($po->activeItems as $line) {
            fputcsv($handle, [
                $po->number,
                $line->supplier_sku,
                $line->title,
                $line->quantity,
                number_format($line->unit_cost_cents / 100, 2, '.', ''),
                number_format($line->lineCostCents() / 100, 2, '.', ''),
                $address['name'] ?? '',
                $address['line1'] ?? '',
                $address['line2'] ?? '',
                $address['city'] ?? '',
                $address['state'] ?? '',
                $address['postal_code'] ?? '',
                strtoupper((string) ($address['country'] ?? '')),
                $address['phone'] ?? '',
            ], escape: '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    // --- Internals -----------------------------------------------------------------------------------------------

    /**
     * Lock the purchase order, check its status, run $apply on the locked row.
     *
     * @param  array<int, SupplierOrderStatus>  $allowed
     * @param  \Closure(SupplierOrder): void  $apply
     *
     * @throws InvalidOrderStateException
     */
    private function transition(SupplierOrder $po, array $allowed, \Closure $apply, string $message): SupplierOrder
    {
        DB::transaction(function () use ($po, $allowed, $apply, $message) {
            $locked = SupplierOrder::query()->whereKey($po->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $allowed, true)) {
                throw new InvalidOrderStateException($message);
            }

            $apply($locked);
        });

        return $po->fresh();
    }

    private function appendNote(SupplierOrder $po, string $text): void
    {
        $line = '['.now()->format('Y-m-d H:i').'] '.trim($text);
        $po->notes = blank($po->notes) ? $line : $po->notes."\n".$line;
    }

    /** @throws \Illuminate\Validation\ValidationException */
    private function validatedReference(?string $reference, bool $required): ?string
    {
        $data = Validator::make(
            ['reference' => filled($reference) ? trim((string) $reference) : null],
            ['reference' => [$required ? 'required' : 'nullable', 'string', 'max:100']],
            ['reference.required' => 'Enter the supplier\'s order reference.'],
        )->validate();

        return $data['reference'] ?? null;
    }
}
