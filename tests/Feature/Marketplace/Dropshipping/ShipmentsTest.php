<?php

use App\Enums\FulfillmentSource;
use App\Enums\OrderStatus;
use App\Enums\SellerOrderStatus;
use App\Exceptions\Marketplace\InvalidOrderStateException;
use App\Models\OrderItem;
use App\Models\SellerOrder;
use App\Models\Shipment;
use App\Models\User;
use App\Notifications\OrderShipped;
use App\Services\Marketplace\IssueService;
use App\Services\Marketplace\ParcelPlanner;
use App\Services\Marketplace\PayoutService;
use App\Services\Marketplace\PurchaseOrderService;
use App\Services\Marketplace\ShipmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/*
| R3.3: shipments are general. A marketplace seller's "Mark shipped" creates one for the whole seller order; a store order
| has one per fulfilment source. The seller order is SHIPPED when everything is in a shipment, shipped_at = the last one,
| so payouts, auto-complete and the problem window keep working.
*/

beforeEach(function () {
    config(['marketplace.fee_bps' => 1000, 'marketplace.fee_fixed_cents' => 0, 'marketplace.physical_payout_trigger' => 'shipped_delay', 'marketplace.physical_payout_delay_days' => 14, 'marketplace.auto_complete_days' => 14]);
    $this->buyer = User::factory()->create();
    $this->admin = adminUser();
    $this->seller = onboardedSeller();
    $this->shipments = app(ShipmentService::class);
});

it('creates one shipment for the whole seller order when a marketplace seller marks it shipped', function () {
    Notification::fake();
    $a = sellableListing($this->seller, 5000, ['quantity' => 5, 'shipping_domestic_cents' => 500, 'additional_item_shipping_cents' => 0]);
    $b = sellableListing($this->seller, 2000, ['quantity' => 5, 'shipping_domestic_cents' => 300, 'additional_item_shipping_cents' => 0]);
    $order = paidOrderOf($this->buyer, [[$a, 2], [$b, 1]]);
    $sellerOrder = $order->sellerOrders->first();

    $this->shipments->markShipped($this->seller, $sellerOrder, 'UPS', '1Z999', 'https://ups.example/1Z999');

    $sellerOrder = $sellerOrder->fresh();
    $shipment = $sellerOrder->shipments->sole();

    expect($sellerOrder->status)->toBe(SellerOrderStatus::SHIPPED)
        ->and($shipment->carrier)->toBe('UPS')
        ->and($shipment->tracking_number)->toBe('1Z999')
        ->and($shipment->tracking_url)->toBe('https://ups.example/1Z999')
        ->and($shipment->supplier_order_id)->toBeNull()
        ->and($shipment->items->sortBy('order_item_id')->pluck('quantity')->all())->toBe([2, 1])
        ->and($sellerOrder->shipped_at->equalTo($shipment->shipped_at))->toBeTrue()
        // the columns the seller pages read still say the same
        ->and([$sellerOrder->carrier, $sellerOrder->tracking_number, $sellerOrder->tracking_url])->toBe(['UPS', '1Z999', 'https://ups.example/1Z999']);

    Notification::assertSentToTimes($this->buyer, OrderShipped::class, 1);
    $notification = Notification::sent($this->buyer, OrderShipped::class)->first();
    expect([$notification->parcel, $notification->parcels])->toBe([1, 1])
        ->and($notification->toMail($this->buyer)->subject)->not->toContain('parcel');
});

it('exposes the parcels to the buyer pages: carrier, tracking, link and items, numbered', function () {
    Notification::fake();
    $listing = sellableListing($this->seller, 5000, ['quantity' => 5]);
    $order = paidOrderOf($this->buyer, [[$listing, 2]]);
    $this->shipments->markShipped($this->seller, $order->sellerOrders->first(), 'DHL', 'JD01', 'https://dhl.example/JD01');

    $summaries = $order->sellerOrders->first()->fresh()->shipmentSummaries();

    expect($summaries)->toHaveCount(1)
        ->and($summaries[0])->toMatchArray(['carrier' => 'DHL', 'tracking_number' => 'JD01', 'tracking_url' => 'https://dhl.example/JD01', 'parcel' => 1, 'parcels' => 1])
        ->and($summaries[0]['items'])->toBe([['order_item_id' => $order->items->first()->id, 'title' => $listing->title, 'quantity' => 2]])
        ->and($order->fresh()->shipments)->toHaveCount(1);
});

it('corrects the tracking of the shipped parcel without a new parcel or a new e-mail, also on an order from before shipments', function () {
    Notification::fake();
    $order = paidOrderOf($this->buyer, [[sellableListing($this->seller, 5000, ['quantity' => 5]), 1]]);
    $sellerOrder = $order->sellerOrders->first();

    $this->shipments->markShipped($this->seller, $sellerOrder, 'UPS', 'WRONG');
    $this->shipments->markShipped($this->seller, $sellerOrder->fresh(), 'UPS', 'RIGHT');

    expect($sellerOrder->fresh()->shipments)->toHaveCount(1)
        ->and($sellerOrder->fresh()->shipments->first()->tracking_number)->toBe('RIGHT')
        ->and($sellerOrder->fresh()->tracking_number)->toBe('RIGHT');
    Notification::assertSentToTimes($this->buyer, OrderShipped::class, 1);

    // an order shipped before shipments existed (tracking only on the seller order) gets its shipment on the first correction
    $legacy = paidOrderOf(User::factory()->create(), [[sellableListing($this->seller, 5000, ['quantity' => 5]), 1]])->sellerOrders->first();
    $legacy->forceFill(['status' => SellerOrderStatus::SHIPPED, 'carrier' => 'USPS', 'tracking_number' => 'OLD', 'shipped_at' => now()->subDays(3)])->save();

    $this->shipments->markShipped($this->seller, $legacy->fresh(), 'USPS', 'FIXED');
    $legacy = $legacy->fresh();
    expect($legacy->shipments)->toHaveCount(1)
        ->and($legacy->shipments->first()->tracking_number)->toBe('FIXED')
        ->and($legacy->shipments->first()->items->sum('quantity'))->toBe(1)
        ->and($legacy->shipped_at->isSameDay(now()->subDays(3)))->toBeTrue() // the original shipping time stays
        ->and($legacy->shipments->first()->shipped_at->equalTo($legacy->shipped_at))->toBeTrue();
});

it('lets the seller ship a seller order in several parcels, shipped only when the last one is out, paid out after that', function () {
    Notification::fake();
    config(['marketplace.physical_payout_trigger' => 'shipped']);
    $listing = sellableListing($this->seller, 5000, ['quantity' => 5, 'shipping_domestic_cents' => 500, 'additional_item_shipping_cents' => 0]);
    $other = sellableListing($this->seller, 2000, ['quantity' => 5, 'shipping_domestic_cents' => 300]);
    $order = paidOrderOf($this->buyer, [[$listing, 3], [$other, 1]]);
    $sellerOrder = $order->sellerOrders->first();
    $first = $order->items->firstWhere('purchasable_id', $listing->id);
    $second = $order->items->firstWhere('purchasable_id', $other->id);

    $this->shipments->shipItems($this->seller, $sellerOrder, [$first->id => 2], 'UPS', '1ZA');
    $partial = $sellerOrder->fresh();
    expect($partial->status)->toBe(SellerOrderStatus::PARTIALLY_SHIPPED)
        ->and($partial->shipped_at)->toBeNull()
        ->and($partial->isTransferred())->toBeFalse()
        ->and(app(PayoutService::class)->dueAt($partial))->toBeNull()
        ->and(app(ParcelPlanner::class)->remaining($partial))->toBe([$first->id => 1, $second->id => 1]);

    $this->travel(1)->day();
    $this->shipments->shipItems($this->seller, $partial, null, 'UPS', '1ZB');
    $done = $sellerOrder->fresh();

    expect($done->status)->toBe(SellerOrderStatus::SHIPPED)
        ->and($done->shipments)->toHaveCount(2)
        ->and($done->shipped_at->equalTo($done->shipments->last()->shipped_at))->toBeTrue()
        ->and($done->isTransferred())->toBeTrue() // the "shipped" trigger: out the moment everything is shipped
        ->and($done->tracking_number)->toBe('1ZB');
    Notification::assertSentToTimes($this->buyer, OrderShipped::class, 2);

    // asking for more than is left, or for somebody else's item, is refused
    expect(fn () => $this->shipments->shipItems($this->seller, $done, [$first->id => 1], 'UPS', '1ZC'))->toThrow(InvalidOrderStateException::class);
});

it('refuses parcels that do not match the order', function () {
    Notification::fake();
    $order = paidOrderOf($this->buyer, [[sellableListing($this->seller, 5000, ['quantity' => 5]), 2]]);
    $sellerOrder = $order->sellerOrders->first();
    $item = $order->items->first();
    $foreign = paidOrderOf(User::factory()->create(), [[sellableListing($this->seller, 5000, ['quantity' => 5]), 1]])->items->first();

    expect(fn () => $this->shipments->shipItems($this->seller, $sellerOrder, [$item->id => 3], 'UPS', '1Z'))->toThrow(InvalidOrderStateException::class, 'only 2 unit')
        ->and(fn () => $this->shipments->shipItems($this->seller, $sellerOrder, [$foreign->id => 1], 'UPS', '1Z'))->toThrow(InvalidOrderStateException::class)
        ->and(fn () => $this->shipments->shipItems($this->seller, $sellerOrder, [], 'UPS', '1Z'))->toThrow(InvalidOrderStateException::class);

    expect($sellerOrder->fresh()->status)->toBe(SellerOrderStatus::AWAITING_SHIPMENT)->and(Shipment::where('seller_order_id', $sellerOrder->id)->count())->toBe(0);
});

it('lets an admin ship the own-stock items of a store order while the supplier items wait for their purchase orders', function () {
    Mail::fake();
    Notification::fake();
    $supplier = supplier();
    $stock = storeListing(['price_cents' => 8000, 'quantity' => 5], null, $this->admin);
    $dropped = dropshipListing($supplier, ['supplier_stock' => 4], $this->admin);
    $order = paidOrderOf($this->buyer, [[$stock, 1], [$dropped, 1]]);
    $sellerOrder = $order->sellerOrders->first();

    // markShipped (the old "Mark shipped" action) ships what is ours to ship
    $this->shipments->markShipped($this->admin, $sellerOrder, 'USPS', '9400OWN');

    $partial = $sellerOrder->fresh();
    expect($partial->status)->toBe(SellerOrderStatus::PARTIALLY_SHIPPED)
        ->and($partial->shipments)->toHaveCount(1)
        ->and($partial->shipments->first()->items->pluck('order_item_id')->all())->toBe([$order->items->firstWhere('purchasable_id', $stock->id)->id]);

    // and only the supplier's item is left: the old action says where it goes
    expect(fn () => $this->shipments->markShipped($this->admin, $partial, 'USPS', '9400MORE'))
        ->toThrow(InvalidOrderStateException::class, 'supplier');

    // the parcel plan tells the buyer pages what ships how, without the supplier
    $parcels = app(ParcelPlanner::class)->parcels($partial->fresh());
    expect($parcels->map->label()->all())->toBe(['Ships from '.config('marketplace.store_name'), 'Ships separately ('.$supplier->leadTimeText().')'])
        ->and($parcels->map->isShipped()->all())->toBe([true, false]);
});

it('keeps the payout clock, the problem window and auto-complete for the last parcel of a store order', function () {
    Mail::fake();
    Notification::fake();
    config(['marketplace.physical_payout_trigger' => 'shipped_delay']);
    $supplier = supplier();
    $order = paidOrderOf($this->buyer, [[storeListing(['price_cents' => 8000, 'quantity' => 5], null, $this->admin), 1], [dropshipListing($supplier, ['supplier_stock' => 4], $this->admin), 1]]);
    $sellerOrder = $order->sellerOrders->first();
    $po = purchaseOrderOf($order, $supplier);
    $issues = app(IssueService::class);

    $this->travelTo(now()->subDays(20));
    $this->shipments->shipItems($this->admin, $sellerOrder, null, 'USPS', '9400OWN');
    $this->travelBack();

    // 20 days after the first parcel: not shipped, so not completed, and the buyer can still report a problem without a deadline
    expect($this->shipments->autoCompleteDue())->toBe(0)
        ->and($sellerOrder->fresh()->status)->toBe(SellerOrderStatus::PARTIALLY_SHIPPED)
        ->and($issues->windowEndsAt($sellerOrder->fresh()))->toBeNull()
        ->and($issues->reportBlocker($sellerOrder->fresh()))->toBeNull();

    // the supplier's parcel ships 10 days ago: now the clocks run from that moment
    app(PurchaseOrderService::class)->markPlacedManually($this->admin, $po, 'REF-1');
    $this->travelTo(now()->subDays(10));
    app(PurchaseOrderService::class)->markShipped($this->admin, $po->fresh(), 'UPS', '1ZSUP');
    $this->travelBack();

    $shipped = $sellerOrder->fresh();
    expect($shipped->status)->toBe(SellerOrderStatus::SHIPPED)
        ->and($shipped->shipped_at->isSameDay(now()->subDays(10)))->toBeTrue()
        ->and($issues->windowEndsAt($shipped)->isSameDay(now()->addDays(4)))->toBeTrue()
        ->and($this->shipments->autoCompleteDue())->toBe(0); // 10 < 14 days

    $this->travel(5)->days();
    expect($this->shipments->autoCompleteDue())->toBe(1)
        ->and($sellerOrder->fresh()->status)->toBe(SellerOrderStatus::COMPLETED)
        ->and($order->fresh()->status)->toBe(OrderStatus::COMPLETED);
});

it('pays a marketplace seller 14 days after the shipment, as before', function () {
    Notification::fake();
    $order = paidOrderOf($this->buyer, [[sellableListing($this->seller, 5000, ['quantity' => 5]), 1]]);
    $sellerOrder = $order->sellerOrders->first();

    $this->shipments->markShipped($this->seller, $sellerOrder, 'UPS', '1Z1');
    $shipment = $sellerOrder->fresh()->shipments->sole();
    expect(app(PayoutService::class)->dueAt($sellerOrder->fresh())->equalTo($shipment->shipped_at->copy()->addDays(14)))->toBeTrue();

    $this->travel(15)->days();
    expect(app(PayoutService::class)->releaseDue())->toBe(1);
    expect($sellerOrder->fresh()->isTransferred())->toBeTrue();
});

it('shows a parcel to its buyer and its seller, to admins, and to nobody else', function () {
    Notification::fake();
    $order = paidOrderOf($this->buyer, [[sellableListing($this->seller, 5000, ['quantity' => 5]), 1]]);
    $this->shipments->markShipped($this->seller, $order->sellerOrders->first(), 'UPS', '1Z1');
    $shipment = Shipment::where('seller_order_id', $order->sellerOrders->first()->id)->firstOrFail();
    $stranger = User::factory()->create();

    expect(Gate::forUser($this->buyer)->allows('view', $shipment))->toBeTrue()
        ->and(Gate::forUser($this->seller)->allows('view', $shipment))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('view', $shipment))->toBeTrue()
        ->and(Gate::forUser($stranger)->allows('view', $shipment))->toBeFalse()
        ->and(Gate::forUser($this->buyer)->allows('update', $shipment))->toBeFalse()
        ->and(Gate::forUser($this->seller)->allows('update', $shipment))->toBeTrue()
        // an admin does not edit a member's parcel
        ->and(Gate::forUser($this->admin)->allows('update', $shipment))->toBeFalse();

    expect(fn () => $this->shipments->updateShipment($this->buyer, $shipment, 'UPS', '1Z2'))->toThrow(AuthorizationException::class);

    $this->shipments->updateShipment($this->seller, $shipment, 'DHL', 'JD9', 'https://dhl.example/JD9');
    expect($shipment->fresh()->carrier)->toBe('DHL')->and($order->sellerOrders->first()->fresh()->tracking_number)->toBe('JD9');
});

it('lets only admins ship the parcels of a store order and not a member', function () {
    Mail::fake();
    Notification::fake();
    $order = paidOrderOf($this->buyer, [[storeListing(['quantity' => 5], null, $this->admin), 1]]);
    $sellerOrder = $order->sellerOrders->first();

    expect(fn () => $this->shipments->shipItems($this->seller, $sellerOrder, null, 'UPS', '1Z'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->shipments->shipItems($this->buyer, $sellerOrder, null, 'UPS', '1Z'))->toThrow(AuthorizationException::class);

    $shipment = $this->shipments->shipItems($this->admin, $sellerOrder, null, 'UPS', '1Z');
    expect($shipment->sellerOrder->status)->toBe(SellerOrderStatus::SHIPPED);
});

it('moves a stock-then-dropship line to the supplier at payment when the own stock is gone by then', function () {
    Mail::fake();
    Notification::fake();
    $supplier = supplier();
    $hybrid = hybridListing($supplier, 2, ['supplier_stock' => 10], $this->admin);
    $order = pendingOrder($this->buyer, [[$hybrid, 2]], addressFor($this->buyer));
    $item = OrderItem::where('order_id', $order->id)->sole();
    expect($item->fulfillment_source)->toBe(FulfillmentSource::STOCK);

    // the stock was corrected down while the buyer was paying
    $hybrid->update(['quantity' => 0]);
    payOrder($order, 'pi_late', 'ch_late');

    $item->refresh();
    expect($item->fulfillment_source)->toBe(FulfillmentSource::SUPPLIER)
        ->and($item->supplier_id)->toBe($supplier->id)
        ->and($order->sellerOrders()->first()->status)->toBe(SellerOrderStatus::AWAITING_SHIPMENT) // not refunded
        ->and(purchaseOrderOf($order, $supplier)->items)->toHaveCount(1)
        ->and(fakeGateway()->callCount('refund'))->toBe(0);

    // a plain stock listing in the same situation is still refunded automatically (unchanged)
    $plain = storeListing(['quantity' => 2], null, $this->admin);
    $buyer2 = User::factory()->create();
    $order2 = pendingOrder($buyer2, [[$plain, 2]], addressFor($buyer2));
    $plain->update(['quantity' => 1]);
    payOrder($order2, 'pi_late2', 'ch_late2');

    expect($order2->sellerOrders()->first()->status)->toBe(SellerOrderStatus::REFUNDED)
        ->and(fakeGateway()->callCount('refund'))->toBe(1);
});
