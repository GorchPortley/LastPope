<?php

use App\Enums\SellerOrderStatus;
use App\Enums\SupplierOrderStatus;
use App\Exceptions\Marketplace\InvalidOrderStateException;
use App\Mail\PurchaseOrderMail;
use App\Models\Address;
use App\Models\Order;
use App\Models\SellerOrder;
use App\Models\SupplierOrder;
use App\Models\User;
use App\Notifications\OrderShipped;
use App\Notifications\PurchaseOrdersToSend;
use App\Services\Marketplace\PurchaseOrderService;
use App\Services\Marketplace\ShipmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
| R3.3: purchase orders to suppliers - created when a store order is paid, sent by mail or placed by hand, confirmed,
| costed and shipped (which creates shipments and tells the buyer, parcel by parcel).
*/

beforeEach(function () {
    config(['marketplace.store_name' => 'SDLabs', 'marketplace.store_email' => 'store@sdlabs.test', 'marketplace.fee_bps' => 1000]);
    $this->buyer = User::factory()->create(['email' => 'private-buyer@example.org']);
    $this->admin = adminUser();
    $this->pos = app(PurchaseOrderService::class);

    $this->acme = supplier([
        'name' => 'Acme Wholesale', 'contact_name' => 'Pat Acme', 'order_email' => 'orders@acme.example.test', 'account_number' => 'ACME-77',
        'dropship_fee_cents' => 250, 'blind_dropship' => true, 'lead_time_min_days' => 5, 'lead_time_max_days' => 8,
    ]);
    $this->bolt = supplier(['name' => 'Bolt Portal', 'order_email' => null]);
});

/** Own stock + two Acme lines + a Bolt line, paid. Mail and notifications are faked. */
function threeParcelOrder(object $test): Order
{
    $stock = storeListing(['price_cents' => 8000, 'quantity' => 5, 'shipping_domestic_cents' => 900, 'additional_item_shipping_cents' => 0], null, $test->admin);
    $d1 = dropshipListing($test->acme, ['price_cents' => 10000, 'cost_cents' => 6000, 'supplier_sku' => 'ACM-100', 'supplier_stock' => 10], $test->admin);
    $d2 = dropshipListing($test->acme, ['price_cents' => 5000, 'cost_cents' => 3000, 'supplier_sku' => 'ACM-200', 'supplier_stock' => 10], $test->admin);
    $d3 = dropshipListing($test->bolt, ['price_cents' => 3000, 'cost_cents' => 1800, 'supplier_sku' => 'BLT-300'], $test->admin);

    $test->listings = compact('stock', 'd1', 'd2', 'd3');

    return paidOrderOf($test->buyer, [[$stock, 1], [$d1, 2], [$d2, 1], [$d3, 1]]);
}

it('creates one purchase order per supplier when a store order is paid, with the lines, costs and ship-to', function () {
    Mail::fake();
    Notification::fake();
    $order = threeParcelOrder($this);

    $acmePo = purchaseOrderOf($order, $this->acme);
    $boltPo = purchaseOrderOf($order, $this->bolt);

    expect(SupplierOrder::where('order_id', $order->id)->count())->toBe(2)
        ->and($acmePo->number)->toMatch('/^PO-[A-Z2-9]{6}$/')
        ->and($acmePo->status)->toBe(SupplierOrderStatus::PENDING)
        ->and($acmePo->seller_order_id)->toBe($order->sellerOrders->first()->id)
        ->and($acmePo->items_cost_cents)->toBe(2 * 6000 + 3000)
        ->and($acmePo->dropship_fee_cents)->toBe(250)
        ->and($acmePo->shipping_cost_cents)->toBeNull()
        ->and($acmePo->shipping_address)->toMatchArray($order->shipping_address)
        ->and($acmePo->items)->toHaveCount(2)
        ->and($acmePo->items->pluck('supplier_sku')->all())->toBe(['ACM-100', 'ACM-200'])
        ->and($acmePo->items->pluck('quantity')->all())->toBe([2, 1])
        ->and($acmePo->items->pluck('unit_cost_cents')->all())->toBe([6000, 3000])
        ->and($boltPo->items)->toHaveCount(1)
        ->and($boltPo->items_cost_cents)->toBe(1800);

    // own stock stays out of it
    expect($acmePo->items->pluck('order_item_id')->all())->not->toContain($order->items->firstWhere('purchasable_id', $this->listings['stock']->id)->id);
});

it('creates them once even when Stripe delivers the payment event twice', function () {
    Mail::fake();
    Notification::fake();
    config(['marketplace.stripe.marketplace_webhook_secret' => 'whsec_po']);

    fillCart($this->buyer, [[dropshipListing($this->acme, ['supplier_stock' => 5], $this->admin), 1]]);
    $order = startCheckout($this->buyer, addressFor($this->buyer))->order;

    $event = stripeEventPayload('checkout.session.completed', checkoutSessionObject($order, 'paid', 'pi_dup'));
    postStripeEvent($this, 'stripe.marketplace.webhook', $event, 'whsec_po')->assertOk();
    // a second, different event id for the same payment (e.g. async_payment_succeeded after completed)
    $again = stripeEventPayload('checkout.session.async_payment_succeeded', checkoutSessionObject($order, 'paid', 'pi_dup'));
    postStripeEvent($this, 'stripe.marketplace.webhook', $again, 'whsec_po')->assertOk();
    postStripeEvent($this, 'stripe.marketplace.webhook', $event, 'whsec_po')->assertOk();

    expect(SupplierOrder::where('order_id', $order->id)->count())->toBe(1);
    Notification::assertSentToTimes($this->admin, PurchaseOrdersToSend::class, 1);

    // calling the creator again changes nothing, and the database refuses a second one for the same seller order and supplier
    expect($this->pos->createForOrder($order->fresh()))->toHaveCount(0)
        ->and(SupplierOrder::where('order_id', $order->id)->count())->toBe(1);

    $po = SupplierOrder::where('order_id', $order->id)->first();
    expect(fn () => SupplierOrder::create(['order_id' => $order->id, 'seller_order_id' => $po->seller_order_id, 'supplier_id' => $po->supplier_id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('tells the admins how many purchase orders wait to be sent, and sends the automatic ones itself', function () {
    Mail::fake();
    Notification::fake();

    // Acme sends by itself, Bolt waits for a person
    $this->acme->update(['auto_send_purchase_orders' => true]);
    $this->bolt->update(['order_email' => 'orders@bolt.example.test']);
    $order = threeParcelOrder($this);

    $acmePo = purchaseOrderOf($order, $this->acme)->fresh();
    expect($acmePo->status)->toBe(SupplierOrderStatus::SENT)->and($acmePo->sent_at)->not->toBeNull()
        ->and(purchaseOrderOf($order, $this->bolt)->status)->toBe(SupplierOrderStatus::PENDING);
    Mail::assertQueued(PurchaseOrderMail::class, 1);
    Mail::assertQueued(PurchaseOrderMail::class, fn ($mail) => $mail->hasTo('orders@acme.example.test') && $mail->purchaseOrder->is($acmePo));

    Notification::assertSentTo($this->admin, PurchaseOrdersToSend::class, function (PurchaseOrdersToSend $notification) {
        return $notification->count === 1 && $notification->title() === 'New store order: 1 purchase order to send';
    });
    // not the buyer
    Notification::assertNotSentTo($this->buyer, PurchaseOrdersToSend::class);
});

it('words the admin notification and links it, in the database and by mail', function () {
    $order = threeParcelOrder($this); // no fake: the real notification goes to the admin's database

    $notification = $this->admin->notifications()->where('type', PurchaseOrdersToSend::class)->first();

    expect($notification)->not->toBeNull()
        ->and($notification->data['title'])->toBe('New store order: 2 purchase orders to send')
        ->and($notification->data['purchase_orders'])->toBe(2)
        ->and($notification->data['order_number'])->toBe($order->number)
        ->and($notification->data['url'])->toContain('/admin');

    $mail = (new PurchaseOrdersToSend($order, 2))->toMail($this->admin)->render();
    expect((string) $mail)->toContain('New store order: 2 purchase orders to send')->toContain($order->number);
});

it('does not bother the admins when every purchase order went out by itself', function () {
    Mail::fake();
    Notification::fake();
    $this->acme->update(['auto_send_purchase_orders' => true]);

    paidOrderOf($this->buyer, [[dropshipListing($this->acme, ['supplier_stock' => 5], $this->admin), 1]]);

    Notification::assertNotSentTo($this->admin, PurchaseOrdersToSend::class);
    Mail::assertQueued(PurchaseOrderMail::class, 1);
});

it('keeps a purchase order pending when the automatic mail cannot go out (no address), and tells the admins', function () {
    Mail::fake();
    Notification::fake();
    $this->bolt->update(['auto_send_purchase_orders' => true]); // but no order e-mail

    $order = paidOrderOf($this->buyer, [[dropshipListing($this->bolt, ['supplier_stock' => 5], $this->admin), 1]]);

    expect(purchaseOrderOf($order)->status)->toBe(SupplierOrderStatus::PENDING);
    Mail::assertNothingQueued();
    Notification::assertSentTo($this->admin, PurchaseOrdersToSend::class);
});

it('e-mails the supplier the lines, the ship-to and the blind-dropship instructions, and nothing about the buyer', function () {
    Mail::fake();
    Notification::fake();
    $order = threeParcelOrder($this);
    $po = purchaseOrderOf($order, $this->acme);
    $shipTo = $order->shipping_address;

    $this->pos->send($this->admin, $po);

    Mail::assertQueued(PurchaseOrderMail::class, function (PurchaseOrderMail $mail) {
        $envelope = $mail->envelope();

        return $mail->hasTo('orders@acme.example.test')
            && $envelope->subject === "Purchase order {$mail->purchaseOrder->number} from SDLabs"
            && $envelope->replyTo[0]->address === 'store@sdlabs.test';
    });

    $mailable = new PurchaseOrderMail($po->fresh());
    $html = $mailable->render();

    expect($html)
        ->toContain($po->number)
        ->toContain('Pat Acme')
        ->toContain('ACME-77')                       // our account number with them
        ->toContain('ACM-100')->toContain('ACM-200') // SKUs
        ->toContain('$60.00')->toContain('$30.00')   // unit costs
        ->toContain($shipTo['name'])->toContain($shipTo['line1'])->toContain($shipTo['postal_code'])
        ->toContain('Blind dropship')
        ->toContain('without')->toContain('invoice')
        ->toContain('store@sdlabs.test')
        // what the buyer paid, who they are and our other orders stay out of it
        ->not->toContain('$100.00')->not->toContain('$50.00')
        ->not->toContain('private-buyer@example.org')
        ->not->toContain($order->number)
        ->not->toContain('BLT-300');

    // the phone number is only in the ship-to block
    if (filled($shipTo['phone'] ?? null)) {
        expect(substr_count($html, $shipTo['phone']))->toBe(1);
    }

    // the same lines as an attachment
    $attachment = $mailable->attachments()[0];
    expect($attachment->as)->toBe($po->number.'.csv')->and($attachment->mime)->toBe('text/csv');
});

it('leaves the blind instructions out for suppliers that are not blind', function () {
    $this->acme->update(['blind_dropship' => false]);
    Mail::fake();
    Notification::fake();
    $order = paidOrderOf($this->buyer, [[dropshipListing($this->acme, ['supplier_stock' => 5], $this->admin), 1]]);

    expect((new PurchaseOrderMail(purchaseOrderOf($order)))->render())->not->toContain('Blind dropship');
});

it('exports the purchase order as CSV with the ship-to and no buyer e-mail', function () {
    Mail::fake();
    Notification::fake();
    $order = threeParcelOrder($this);
    $po = purchaseOrderOf($order, $this->acme);

    $csv = $this->pos->toCsv($po);
    $rows = array_map('str_getcsv', array_filter(explode("\n", $csv)));
    $address = $order->shipping_address;

    expect($rows[0])->toContain('Supplier SKU', 'Quantity', 'Unit cost')
        ->and($rows)->toHaveCount(3) // header + 2 lines
        ->and($rows[1][0])->toBe($po->number)
        ->and($rows[1][1])->toBe('ACM-100')
        ->and($rows[1][3])->toBe('2')
        ->and($rows[1][4])->toBe('60.00')
        ->and($rows[1][5])->toBe('120.00')
        ->and($rows[1][6])->toBe($address['name'])
        ->and($csv)->not->toContain('private-buyer@example.org');
});

it('sends a purchase order, lets it be sent again, and refuses what cannot be sent', function () {
    Mail::fake();
    Notification::fake();
    $order = threeParcelOrder($this);
    $po = purchaseOrderOf($order, $this->acme);

    $sent = $this->pos->send($this->admin, $po);
    expect($sent->status)->toBe(SupplierOrderStatus::SENT)->and($sent->sent_at)->not->toBeNull()->and($sent->placed_manually)->toBeFalse();

    $firstSentAt = $sent->sent_at;
    $this->travel(2)->hours();
    $again = $this->pos->send($this->admin, $sent);
    expect($again->status)->toBe(SupplierOrderStatus::SENT)->and($again->sent_at->equalTo($firstSentAt))->toBeTrue();
    Mail::assertQueued(PurchaseOrderMail::class, 2);

    // no order e-mail: place it by hand instead
    expect(fn () => $this->pos->send($this->admin, purchaseOrderOf($order, $this->bolt)))
        ->toThrow(InvalidOrderStateException::class, 'no order e-mail');

    // cancelled and shipped ones are not sent
    $cancelled = SupplierOrder::factory()->cancelled()->create();
    expect(fn () => $this->pos->send($this->admin, $cancelled))->toThrow(InvalidOrderStateException::class);
});

it('marks an order placed in the supplier portal, with their reference', function () {
    Mail::fake();
    Notification::fake();
    $order = threeParcelOrder($this);
    $po = purchaseOrderOf($order, $this->bolt);

    expect(fn () => $this->pos->markPlacedManually($this->admin, $po, '  '))->toThrow(ValidationException::class);

    $placed = $this->pos->markPlacedManually($this->admin, $po, 'PORTAL-5521');
    expect($placed->status)->toBe(SupplierOrderStatus::SENT)
        ->and($placed->placed_manually)->toBeTrue()
        ->and($placed->supplier_reference)->toBe('PORTAL-5521')
        ->and($placed->sent_at)->not->toBeNull();
    Mail::assertNothingQueued();

    // only once, and only from pending
    expect(fn () => $this->pos->markPlacedManually($this->admin, $placed, 'X'))->toThrow(InvalidOrderStateException::class);
});

it('confirms a sent purchase order, optionally with the supplier\'s order number', function () {
    Mail::fake();
    Notification::fake();
    $po = purchaseOrderOf(threeParcelOrder($this), $this->acme);

    expect(fn () => $this->pos->confirm($this->admin, $po))->toThrow(InvalidOrderStateException::class); // not sent yet

    $this->pos->send($this->admin, $po);
    $confirmed = $this->pos->confirm($this->admin, $po->fresh(), 'ACME-SO-9001');

    expect($confirmed->status)->toBe(SupplierOrderStatus::CONFIRMED)
        ->and($confirmed->supplier_reference)->toBe('ACME-SO-9001')
        ->and($confirmed->confirmed_at)->not->toBeNull();
});

it('records what the supplier charges and keeps the cost snapshot of the order lines in step', function () {
    Mail::fake();
    Notification::fake();
    $order = threeParcelOrder($this);
    $po = purchaseOrderOf($order, $this->acme);
    [$first, $second] = $po->items->all();

    $updated = $this->pos->recordSupplierCosts($this->admin, $po, 1295, [$first->id => 6250], 400);

    expect($updated->shipping_cost_cents)->toBe(1295)
        ->and($updated->dropship_fee_cents)->toBe(400)
        ->and($updated->items_cost_cents)->toBe(2 * 6250 + 3000)
        ->and($updated->totalCostCents())->toBe(2 * 6250 + 3000 + 1295 + 400)
        ->and($first->fresh()->unit_cost_cents)->toBe(6250)
        ->and($first->orderItem->fresh()->unit_cost_cents)->toBe(6250)
        ->and($second->orderItem->fresh()->unit_cost_cents)->toBe(3000);

    expect(fn () => $this->pos->recordSupplierCosts($this->admin, $po, -1))->toThrow(InvalidOrderStateException::class);
});

it('creates a shipment when the supplier ships, and the seller order is shipped only when every parcel is out', function () {
    Mail::fake();
    Notification::fake();
    $order = threeParcelOrder($this);
    $sellerOrder = $order->sellerOrders->first();
    $acmePo = purchaseOrderOf($order, $this->acme);
    $boltPo = purchaseOrderOf($order, $this->bolt);
    $shipments = app(ShipmentService::class);

    // the supplier cannot ship what was never sent to them
    expect(fn () => $this->pos->markShipped($this->admin, $acmePo, 'UPS', '1ZACME'))->toThrow(InvalidOrderStateException::class);

    // parcel 1: the admin ships the own-stock item
    $shipments->shipItems($this->admin, $sellerOrder, null, 'USPS', '9400OWN');
    expect($sellerOrder->fresh()->status)->toBe(SellerOrderStatus::PARTIALLY_SHIPPED)->and($sellerOrder->fresh()->shipped_at)->toBeNull();

    // parcel 2: Acme
    $this->pos->send($this->admin, $acmePo);
    $this->travel(1)->day();
    $shipment = $this->pos->markShipped($this->admin, $acmePo->fresh(), 'UPS', '1ZACME', 'https://ups.example/1ZACME');
    expect($shipment->supplier_order_id)->toBe($acmePo->id)
        ->and($shipment->items)->toHaveCount(2)
        ->and($shipment->items->sum('quantity'))->toBe(3)
        ->and($acmePo->fresh()->status)->toBe(SupplierOrderStatus::SHIPPED)
        ->and($acmePo->fresh()->shipped_at->equalTo($shipment->shipped_at))->toBeTrue()
        ->and($sellerOrder->fresh()->status)->toBe(SellerOrderStatus::PARTIALLY_SHIPPED)
        ->and($sellerOrder->fresh()->shipped_at)->toBeNull()
        ->and($order->fresh()->status->value)->toBe('paid');

    // parcel 3: Bolt (placed in their portal first) - now everything is out
    $this->pos->markPlacedManually($this->admin, $boltPo, 'BOLT-1');
    $this->travel(2)->days();
    $last = $this->pos->markShipped($this->admin, $boltPo->fresh(), 'FedEx', 'FX123');

    $sellerOrder = $sellerOrder->fresh();
    expect($sellerOrder->status)->toBe(SellerOrderStatus::SHIPPED)
        ->and($sellerOrder->shipped_at->equalTo($last->shipped_at))->toBeTrue() // the last parcel's time
        ->and($sellerOrder->carrier)->toBe('FedEx')->and($sellerOrder->tracking_number)->toBe('FX123') // the legacy columns mirror the latest
        ->and($sellerOrder->shipments)->toHaveCount(3)
        ->and($boltPo->fresh()->status)->toBe(SupplierOrderStatus::SHIPPED);

    // nothing is left to ship
    expect(fn () => $this->pos->markShipped($this->admin, $boltPo->fresh(), 'FedEx', 'FX124'))->toThrow(InvalidOrderStateException::class);
});

it('tells the buyer about every parcel: parcel 1 of 3, 2 of 3, 3 of 3, without naming the supplier', function () {
    Mail::fake();
    Notification::fake();
    $order = threeParcelOrder($this);
    $sellerOrder = $order->sellerOrders->first();
    $acmePo = purchaseOrderOf($order, $this->acme);
    $boltPo = purchaseOrderOf($order, $this->bolt);

    app(ShipmentService::class)->shipItems($this->admin, $sellerOrder, null, 'USPS', '9400OWN');
    $this->pos->send($this->admin, $acmePo);
    $this->pos->markShipped($this->admin, $acmePo->fresh(), 'UPS', '1ZACME');
    $this->pos->markPlacedManually($this->admin, $boltPo, 'B-1');
    $this->pos->markShipped($this->admin, $boltPo->fresh(), 'FedEx', 'FX123');

    $sent = Notification::sent($this->buyer, OrderShipped::class);
    expect($sent)->toHaveCount(3)
        ->and($sent->map(fn ($n) => [$n->parcel, $n->parcels])->all())->toBe([[1, 3], [2, 3], [3, 3]]);

    $first = $sent[0]->toMail($this->buyer);
    $last = $sent[2]->toMail($this->buyer);
    expect($first->subject)->toContain('parcel 1 of 3')
        ->and(implode(' ', $first->introLines))->toContain('Parcel 1 of 3')->toContain('The rest of your order ships separately')
        ->and(implode(' ', $last->introLines))->toContain('last parcel');

    $text = $sent->map(fn ($n) => (string) $n->toMail($this->buyer)->render().json_encode($n->toArray($this->buyer)))->implode(' ');
    expect($text)->not->toContain('Acme')->not->toContain('Bolt')->not->toContain('ACM-')->not->toContain($acmePo->number);

    expect($sent[1]->toArray($this->buyer))->toMatchArray(['parcel' => 2, 'parcels' => 3]);
});

it('cancels a whole purchase order after the supplier agreed, without touching shipped lines', function () {
    Mail::fake();
    Notification::fake();
    $order = threeParcelOrder($this);
    $po = purchaseOrderOf($order, $this->acme);
    $this->pos->send($this->admin, $po);

    $cancelled = $this->pos->cancel($this->admin, $po->fresh(), 'Supplier cannot deliver.');

    expect($cancelled->status)->toBe(SupplierOrderStatus::CANCELLED)
        ->and($cancelled->cancelled_at)->not->toBeNull()
        ->and($cancelled->items()->whereNull('cancelled_at')->count())->toBe(0)
        ->and($cancelled->items_cost_cents)->toBe(0)
        ->and($cancelled->notes)->toContain('Supplier cannot deliver.');

    // its lines are no longer expected: the seller order does not wait for them
    $sellerOrder = $order->sellerOrders->first();
    $plan = app(App\Services\Marketplace\ParcelPlanner::class)->remaining($sellerOrder->fresh());
    expect(array_sum($plan))->toBe(1 + 1); // the own-stock unit and Bolt's unit

    expect(fn () => $this->pos->cancel($this->admin, $cancelled))->toThrow(InvalidOrderStateException::class);
});

it('lets only admins work on purchase orders', function () {
    $po = SupplierOrder::factory()->sent()->create();
    $member = onboardedSeller();

    foreach ([$this->buyer, $member] as $intruder) {
        expect(fn () => $this->pos->send($intruder, $po))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->pos->markPlacedManually($intruder, $po, 'X'))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->pos->confirm($intruder, $po))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->pos->recordSupplierCosts($intruder, $po, 100))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->pos->markShipped($intruder, $po, 'UPS', '1Z'))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->pos->cancel($intruder, $po))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->pos->flagProblem($po, 'x', $intruder))->toThrow(AuthorizationException::class);
    }

    expect($po->fresh()->status)->toBe(SupplierOrderStatus::SENT);
});

it('flags a purchase order as a problem with a dated note', function () {
    $po = SupplierOrder::factory()->confirmed()->create();

    $flagged = $this->pos->flagProblem($po, 'Back-ordered for two weeks.', $this->admin);

    expect($flagged->status)->toBe(SupplierOrderStatus::PROBLEM)
        ->and($flagged->notes)->toContain('Back-ordered for two weeks.')->toMatch('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}\] /');

    // a shipped purchase order only gets the note
    $shipped = SupplierOrder::factory()->shipped()->create();
    expect($this->pos->flagProblem($shipped, 'Wrong colour.')->status)->toBe(SupplierOrderStatus::SHIPPED);
});

it('counts the purchase orders waiting to be sent', function () {
    $before = $this->pos->toSendCount(); // the demo data has some
    SupplierOrder::factory()->count(2)->create();
    SupplierOrder::factory()->sent()->create();

    expect($this->pos->toSendCount())->toBe($before + 2);
});
