<?php

use App\Enums\OrderStatus;
use App\Enums\SellerOrderStatus;
use App\Enums\SupplierOrderStatus;
use App\Models\User;
use App\Services\Marketplace\ParcelPlanner;
use App\Services\Marketplace\PurchaseOrderService;
use App\Services\Marketplace\RefundService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/*
| R3.3: refunding store lines whose purchase order is still PENDING drops those lines; a purchase order that was already
| sent (or confirmed) is flagged PROBLEM with "cancel with supplier" for the admin; a shipped one only gets a note.
*/

beforeEach(function () {
    Mail::fake();
    Notification::fake();
    config(['marketplace.fee_bps' => 1000]);
    $this->buyer = User::factory()->create();
    $this->admin = adminUser();
    $this->refunds = app(RefundService::class);
    $this->pos = app(PurchaseOrderService::class);
    $this->acme = supplier(['name' => 'Acme']);
    $this->bolt = supplier(['name' => 'Bolt', 'order_email' => 'orders@bolt.example.test']);
});

/** An own-stock item, one Acme item and one Bolt item, paid. */
function refundableStoreOrder(object $test)
{
    $order = paidOrderOf($test->buyer, [
        [storeListing(['price_cents' => 8000, 'quantity' => 5], null, $test->admin), 1],
        [dropshipListing($test->acme, ['price_cents' => 10000, 'cost_cents' => 6000, 'supplier_sku' => 'ACM-1', 'supplier_stock' => 9], $test->admin), 2],
        [dropshipListing($test->bolt, ['price_cents' => 5000, 'cost_cents' => 3000, 'supplier_sku' => 'BLT-1', 'supplier_stock' => 9], $test->admin), 1],
    ]);

    return $order;
}

it('drops the lines of purchase orders that were not sent when the seller order is refunded', function () {
    $order = refundableStoreOrder($this);
    $acmePo = purchaseOrderOf($order, $this->acme);
    $boltPo = purchaseOrderOf($order, $this->bolt);

    $this->refunds->refundSellerOrder($this->admin, $order->sellerOrders->first(), 'Customer changed their mind');

    foreach ([$acmePo, $boltPo] as $po) {
        $po->refresh();
        expect($po->status)->toBe(SupplierOrderStatus::CANCELLED)
            ->and($po->cancelled_at)->not->toBeNull()
            ->and($po->items()->whereNull('cancelled_at')->count())->toBe(0)
            ->and($po->items_cost_cents)->toBe(0)
            ->and($po->notes)->toContain('Not sent')->toContain('Customer changed their mind');
    }

    expect($order->fresh()->status)->toBe(OrderStatus::REFUNDED)
        ->and($order->sellerOrders->first()->fresh()->status)->toBe(SellerOrderStatus::REFUNDED);
});

it('flags a purchase order the supplier already has: cancel with supplier', function () {
    $order = refundableStoreOrder($this);
    $acmePo = purchaseOrderOf($order, $this->acme);
    $boltPo = purchaseOrderOf($order, $this->bolt);
    $this->pos->send($this->admin, $acmePo);
    $this->pos->markPlacedManually($this->admin, $boltPo, 'PORTAL-1');
    $this->pos->confirm($this->admin, $boltPo->fresh());

    $this->refunds->refundOrder($this->admin, $order->fresh(), 'Wrong item ordered');

    $acmePo->refresh();
    $boltPo->refresh();

    expect($acmePo->status)->toBe(SupplierOrderStatus::PROBLEM)
        ->and($acmePo->notes)->toContain('Cancel with supplier')->toContain('2 x')->toContain('ACM-1')->toContain('Wrong item ordered')
        // the lines stay: the supplier may still ship them until an admin has cancelled them there
        ->and($acmePo->items()->whereNull('cancelled_at')->count())->toBe(1)
        ->and($acmePo->items_cost_cents)->toBe(12000)
        ->and($boltPo->status)->toBe(SupplierOrderStatus::PROBLEM)
        ->and($boltPo->notes)->toContain('Cancel with supplier');

    // once the admin has had it cancelled at the supplier, they cancel it here
    $cancelled = $this->pos->cancel($this->admin, $acmePo, 'Cancelled by phone with Pat.');
    expect($cancelled->status)->toBe(SupplierOrderStatus::CANCELLED)->and($cancelled->notes)->toContain('Cancel with supplier')->toContain('by phone');
});

it('treats a pending and a sent purchase order of the same order each its own way', function () {
    $order = refundableStoreOrder($this);
    $this->pos->send($this->admin, purchaseOrderOf($order, $this->bolt));

    $this->refunds->refundSellerOrder($this->admin, $order->sellerOrders->first());

    expect(purchaseOrderOf($order, $this->acme)->status)->toBe(SupplierOrderStatus::CANCELLED)
        ->and(purchaseOrderOf($order, $this->bolt)->status)->toBe(SupplierOrderStatus::PROBLEM);
});

it('only adds a note to a purchase order that has shipped', function () {
    $order = refundableStoreOrder($this);
    $acmePo = purchaseOrderOf($order, $this->acme);
    $this->pos->send($this->admin, $acmePo);
    $this->pos->markShipped($this->admin, $acmePo->fresh(), 'UPS', '1ZACME');

    $this->refunds->refundSellerOrder($this->admin, $order->sellerOrders->first()->fresh(), 'Damaged in transit');

    $acmePo->refresh();
    expect($acmePo->status)->toBe(SupplierOrderStatus::SHIPPED)
        ->and($acmePo->notes)->toContain('Refunded after it shipped')->toContain('Damaged in transit');
});

it('leaves purchase orders alone when only part of the money goes back', function () {
    $order = refundableStoreOrder($this);
    $acmePo = purchaseOrderOf($order, $this->acme);
    $this->pos->send($this->admin, $acmePo);

    $this->refunds->refundSellerOrderPartially($this->admin, $order->sellerOrders->first(), 1500, 'Small scratch');

    expect($acmePo->fresh()->status)->toBe(SupplierOrderStatus::SENT)
        ->and($acmePo->fresh()->notes)->toBeNull()
        ->and(purchaseOrderOf($order, $this->bolt)->status)->toBe(SupplierOrderStatus::PENDING);
});

it('reacts to a full refund made in the Stripe dashboard and to one that settles a problem report', function () {
    $order = refundableStoreOrder($this);
    $this->pos->send($this->admin, purchaseOrderOf($order, $this->acme));

    $this->refunds->syncFromCharge($order->fresh(), $order->total_cents, $order->total_cents);

    expect(purchaseOrderOf($order, $this->acme)->status)->toBe(SupplierOrderStatus::PROBLEM)
        ->and(purchaseOrderOf($order, $this->bolt)->status)->toBe(SupplierOrderStatus::CANCELLED);

    $second = refundableStoreOrder($this);
    $this->refunds->refundForIssue($second->sellerOrders->first(), null, 'Settled with a full refund');
    expect(purchaseOrderOf($second, $this->acme)->status)->toBe(SupplierOrderStatus::CANCELLED);
});

it('cancels single lines of a pending purchase order and keeps the others', function () {
    $supplier = $this->acme;
    $order = paidOrderOf($this->buyer, [
        [dropshipListing($supplier, ['supplier_stock' => 9, 'cost_cents' => 6000], $this->admin), 2],
        [dropshipListing($supplier, ['supplier_stock' => 9, 'cost_cents' => 3000], $this->admin), 1],
    ]);
    $po = purchaseOrderOf($order);
    [$first, $second] = $po->items->all();

    $po = $this->pos->cancelLines($po, [$first->order_item_id], 'Out of stock at the supplier');

    expect($po->status)->toBe(SupplierOrderStatus::PENDING)
        ->and($po->items()->whereNull('cancelled_at')->pluck('id')->all())->toBe([$second->id])
        ->and($po->items_cost_cents)->toBe(3000)
        ->and($po->notes)->toContain('Out of stock at the supplier')
        // the seller order no longer waits for the cancelled line
        ->and(array_keys(app(ParcelPlanner::class)->remaining($order->sellerOrders->first()->fresh())))->toBe([$second->order_item_id]);

    // calling it again changes nothing
    $again = $this->pos->cancelLines($po, [$first->order_item_id]);
    expect($again->notes)->toBe($po->notes);
});
