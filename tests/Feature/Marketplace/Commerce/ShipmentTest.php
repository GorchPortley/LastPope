<?php

use App\Enums\OrderStatus;
use App\Enums\SellerOrderStatus;
use App\Exceptions\Marketplace\InvalidOrderStateException;
use App\Models\Order;
use App\Models\SellerOrder;
use App\Models\User;
use App\Notifications\OrderShipped;
use App\Notifications\PayoutSent;
use App\Services\Marketplace\ShipmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    config(['marketplace.fee_bps' => 1000, 'marketplace.fee_fixed_cents' => 0, 'marketplace.physical_payout_trigger' => 'shipped', 'marketplace.auto_complete_days' => 14]);
    $this->buyer = User::factory()->create();
    $this->seller = onboardedSeller();
    $this->shipments = app(ShipmentService::class);
});

/** A paid order with one physical seller order from $this->seller; returns [Order, SellerOrder]. */
function paidPhysicalOrder(object $test, int $cents = 10000): array
{
    $listing = sellableListing($test->seller, $cents, ['shipping_domestic_cents' => 1000, 'additional_item_shipping_cents' => 0, 'quantity' => 2]);
    $order = pendingOrder($test->buyer, [$listing], addressFor($test->buyer));
    payOrder($order);

    return [$order->fresh(), $order->sellerOrders()->first()];
}

it('lets the seller ship, releases the payout and tells the buyer', function () {
    Notification::fake();
    [$order, $sellerOrder] = paidPhysicalOrder($this);
    expect($sellerOrder->status)->toBe(SellerOrderStatus::AWAITING_SHIPMENT)->and($sellerOrder->isTransferred())->toBeFalse();

    $shipped = $this->shipments->markShipped($this->seller, $sellerOrder, 'UPS', '1Z999', 'https://ups.example/track/1Z999');

    expect($shipped->status)->toBe(SellerOrderStatus::SHIPPED)
        ->and($shipped->carrier)->toBe('UPS')
        ->and($shipped->tracking_number)->toBe('1Z999')
        ->and($shipped->tracking_url)->toBe('https://ups.example/track/1Z999')
        ->and($shipped->shipped_at)->not->toBeNull()
        ->and($shipped->isTransferred())->toBeTrue()
        // 10000 + 1000 shipping - 10 % of the items
        ->and(fakeGateway()->lastCall('createTransfer')['amountCents'])->toBe(10000 + 1000 - 1000)
        ->and($order->fresh()->status)->toBe(OrderStatus::PAID);

    Notification::assertSentTo($this->buyer, OrderShipped::class, 1);
    Notification::assertSentTo($this->seller, PayoutSent::class, 1);
});

it('holds the payout back on shipment when the trigger is "paid" because it was already made', function () {
    config(['marketplace.physical_payout_trigger' => 'paid']);
    [, $sellerOrder] = paidPhysicalOrder($this);
    expect($sellerOrder->fresh()->isTransferred())->toBeTrue();

    $this->shipments->markShipped($this->seller, $sellerOrder, 'DHL', 'JD0001');

    expect(fakeGateway()->callCount('createTransfer'))->toBe(1);
});

it('refuses anybody but the seller of that order: 403', function () {
    [, $sellerOrder] = paidPhysicalOrder($this);
    $otherSeller = onboardedSeller();

    foreach ([$otherSeller, $this->buyer, User::factory()->admin()->create()] as $intruder) {
        expect(fn () => $this->shipments->markShipped($intruder, $sellerOrder, 'UPS', '1Z'))
            ->toThrow(AuthorizationException::class);
    }

    $this->actingAs($otherSeller)
        ->post(route('selling.orders.ship', $sellerOrder), ['carrier' => 'UPS', 'tracking_number' => '1Z'])
        ->assertForbidden();
    $this->actingAs($this->buyer)
        ->post(route('selling.orders.ship', $sellerOrder), ['carrier' => 'UPS', 'tracking_number' => '1Z'])
        ->assertForbidden();

    expect($sellerOrder->fresh()->status)->toBe(SellerOrderStatus::AWAITING_SHIPMENT);
});

it('ships through the HTTP route for the real seller', function () {
    [, $sellerOrder] = paidPhysicalOrder($this);

    $this->actingAs($this->seller)
        ->post(route('selling.orders.ship', $sellerOrder), ['carrier' => 'USPS', 'tracking_number' => '9400', 'tracking_url' => ''])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($sellerOrder->fresh()->status)->toBe(SellerOrderStatus::SHIPPED);
});

it('validates carrier, tracking number and URL', function () {
    [, $sellerOrder] = paidPhysicalOrder($this);

    expect(fn () => $this->shipments->markShipped($this->seller, $sellerOrder, '', '123'))->toThrow(ValidationException::class);
    expect(fn () => $this->shipments->markShipped($this->seller, $sellerOrder, 'UPS', ''))->toThrow(ValidationException::class);
    expect(fn () => $this->shipments->markShipped($this->seller, $sellerOrder, 'UPS', '123', 'javascript:alert(1)'))->toThrow(ValidationException::class);

    expect($sellerOrder->fresh()->status)->toBe(SellerOrderStatus::AWAITING_SHIPMENT);
});

it('cannot ship what is unpaid, digital-only, refunded or finished', function () {
    $pending = pendingOrder($this->buyer, [sellableListing($this->seller)], addressFor($this->buyer));
    expect(fn () => $this->shipments->markShipped($this->seller, $pending->sellerOrders()->first(), 'UPS', '1Z'))
        ->toThrow(InvalidOrderStateException::class);

    $digitalBuyer = User::factory()->create();
    $digital = pendingOrder($digitalBuyer, [paidDesign($this->seller)]);
    payOrder($digital, 'pi_d', 'ch_d');
    expect(fn () => $this->shipments->markShipped($this->seller, $digital->sellerOrders()->first(), 'UPS', '1Z'))
        ->toThrow(InvalidOrderStateException::class);

    $refundBuyer = User::factory()->create();
    $refunded = pendingOrder($refundBuyer, [sellableListing($this->seller)], addressFor($refundBuyer));
    payOrder($refunded, 'pi_r', 'ch_r');
    app(App\Services\Marketplace\RefundService::class)->refundOrder(User::factory()->admin()->create(), $refunded->fresh());
    expect(fn () => $this->shipments->markShipped($this->seller, $refunded->sellerOrders()->first(), 'UPS', '1Z'))
        ->toThrow(InvalidOrderStateException::class);

    $doneBuyer = User::factory()->create();
    $done = pendingOrder($doneBuyer, [sellableListing($this->seller)], addressFor($doneBuyer));
    payOrder($done, 'pi_f', 'ch_f');
    $doneSellerOrder = $done->sellerOrders()->first();
    $this->shipments->markShipped($this->seller, $doneSellerOrder, 'UPS', '1');
    $this->shipments->confirmReceived($doneBuyer, $doneSellerOrder->fresh());
    expect(fn () => $this->shipments->markShipped($this->seller, $doneSellerOrder->fresh(), 'UPS', '2'))
        ->toThrow(InvalidOrderStateException::class);
});

it('lets the seller correct the tracking number without notifying the buyer again', function () {
    Notification::fake();
    [, $sellerOrder] = paidPhysicalOrder($this);

    $this->shipments->markShipped($this->seller, $sellerOrder, 'UPS', 'WRONG');
    $fixed = $this->shipments->markShipped($this->seller, $sellerOrder->fresh(), 'UPS', 'RIGHT');

    expect($fixed->tracking_number)->toBe('RIGHT')
        ->and(fakeGateway()->callCount('createTransfer'))->toBe(1);
    Notification::assertSentToTimes($this->buyer, OrderShipped::class, 1);
});

it('lets only the buyer confirm receipt, which completes seller order and order', function () {
    [$order, $sellerOrder] = paidPhysicalOrder($this);

    // not shipped yet
    expect(fn () => $this->shipments->confirmReceived($this->buyer, $sellerOrder))->toThrow(InvalidOrderStateException::class);

    $this->shipments->markShipped($this->seller, $sellerOrder, 'UPS', '1Z');

    expect(fn () => $this->shipments->confirmReceived($this->seller, $sellerOrder->fresh()))->toThrow(AuthorizationException::class);
    $this->actingAs(onboardedSeller())->post(route('orders.received', $sellerOrder))->assertForbidden();
    expect($sellerOrder->fresh()->status)->toBe(SellerOrderStatus::SHIPPED);

    $this->actingAs($this->buyer)->post(route('orders.received', $sellerOrder))->assertRedirect()->assertSessionHas('success');

    $sellerOrder->refresh();
    expect($sellerOrder->status)->toBe(SellerOrderStatus::COMPLETED)
        ->and($sellerOrder->delivered_at)->not->toBeNull()
        ->and($order->fresh()->status)->toBe(OrderStatus::COMPLETED);

    // and not twice
    expect(fn () => $this->shipments->confirmReceived($this->buyer, $sellerOrder))->toThrow(InvalidOrderStateException::class);
});

it('keeps the order paid until every seller order is completed', function () {
    $second = onboardedSeller();
    $order = pendingOrder($this->buyer, [sellableListing($this->seller), sellableListing($second)], addressFor($this->buyer));
    payOrder($order);
    [$soOne, $soTwo] = $order->sellerOrders()->orderBy('id')->get()->all();
    $sellers = [$soOne->seller_id => $soOne, $soTwo->seller_id => $soTwo];

    $this->shipments->markShipped($this->seller, $sellers[$this->seller->id], 'UPS', '1');
    $this->shipments->confirmReceived($this->buyer, $sellers[$this->seller->id]->fresh());
    expect($order->fresh()->status)->toBe(OrderStatus::PAID);

    $this->shipments->markShipped($second, $sellers[$second->id], 'UPS', '2');
    $this->shipments->confirmReceived($this->buyer, $sellers[$second->id]->fresh());
    expect($order->fresh()->status)->toBe(OrderStatus::COMPLETED);
});

// --- Auto-complete ------------------------------------------------------------------------------

it('completes parcels shipped more than auto_complete_days ago, and only those', function () {
    [$oldOrder, $old] = paidPhysicalOrder($this);
    $this->shipments->markShipped($this->seller, $old, 'UPS', '1');
    $old->fresh()->forceFill(['shipped_at' => now()->subDays(15)])->save();

    $buyer2 = User::factory()->create();
    $recentListing = sellableListing($this->seller, 4000);
    $recentOrder = pendingOrder($buyer2, [$recentListing], addressFor($buyer2));
    payOrder($recentOrder, 'pi_r', 'ch_r');
    $recent = $recentOrder->sellerOrders()->first();
    $this->shipments->markShipped($this->seller, $recent, 'UPS', '2');
    $recent->fresh()->forceFill(['shipped_at' => now()->subDays(13)])->save();

    $buyer3 = User::factory()->create();
    $unshippedOrder = pendingOrder($buyer3, [sellableListing($this->seller)], addressFor($buyer3));
    payOrder($unshippedOrder, 'pi_u', 'ch_u');

    $this->artisan('marketplace:auto-complete')->assertSuccessful();

    expect($old->fresh()->status)->toBe(SellerOrderStatus::COMPLETED)
        ->and($oldOrder->fresh()->status)->toBe(OrderStatus::COMPLETED)
        ->and($recent->fresh()->status)->toBe(SellerOrderStatus::SHIPPED)
        ->and($unshippedOrder->sellerOrders()->first()->status)->toBe(SellerOrderStatus::AWAITING_SHIPMENT);
});

it('respects --days and --dry-run', function () {
    [, $sellerOrder] = paidPhysicalOrder($this);
    $this->shipments->markShipped($this->seller, $sellerOrder, 'UPS', '1');
    $sellerOrder->fresh()->forceFill(['shipped_at' => now()->subDays(3)])->save();

    $this->artisan('marketplace:auto-complete --dry-run --days=2')->expectsOutputToContain('1 shipped seller order')->assertSuccessful();
    expect($sellerOrder->fresh()->status)->toBe(SellerOrderStatus::SHIPPED);

    $this->artisan('marketplace:auto-complete --days=14')->assertSuccessful();
    expect($sellerOrder->fresh()->status)->toBe(SellerOrderStatus::SHIPPED);

    $this->artisan('marketplace:auto-complete --days=2')->assertSuccessful();
    expect($sellerOrder->fresh()->status)->toBe(SellerOrderStatus::COMPLETED);
});

it('pays the seller on auto-completion if the payout is somehow still pending', function () {
    [, $sellerOrder] = paidPhysicalOrder($this);
    // shipped (e.g. by an admin fix or a failed transfer) without the payout being made
    fakeGateway()->failOn('createTransfer');
    $this->shipments->markShipped($this->seller, $sellerOrder, 'UPS', '1');
    expect($sellerOrder->fresh()->isTransferred())->toBeFalse();
    $sellerOrder->fresh()->forceFill(['shipped_at' => now()->subDays(20)])->save();

    $this->artisan('marketplace:auto-complete')->assertSuccessful();

    expect($sellerOrder->fresh()->isTransferred())->toBeTrue()
        ->and($sellerOrder->fresh()->status)->toBe(SellerOrderStatus::COMPLETED);
});

it('is scheduled daily', function () {
    $events = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events());

    expect($events->contains(fn ($event) => str_contains($event->command, 'marketplace:auto-complete') && $event->expression === '0 0 * * *'))->toBeTrue();
});

// --- platform sellers (SDLabs' store): any admin ships ----------------------------------------------------

/** A paid order of one store listing (sold by $admin); returns [Order, SellerOrder]. */
function paidStoreOrder(object $test, ?User $admin = null): array
{
    $listing = storeListing(['price_cents' => 12900, 'shipping_domestic_cents' => 900, 'quantity' => 5], admin: $admin ?? adminUser());
    $order = pendingOrder($test->buyer, [$listing], addressFor($test->buyer));
    payOrder($order);

    return [$order->fresh(), $order->sellerOrders()->sole()];
}

it('lets any admin ship an order of a platform seller, and the platform seller too', function () {
    Notification::fake();
    $seller = adminUser();
    [$order, $sellerOrder] = paidStoreOrder($this, $seller);

    expect($sellerOrder->status)->toBe(SellerOrderStatus::AWAITING_SHIPMENT)
        ->and($sellerOrder->seller->is($seller))->toBeTrue()
        ->and($sellerOrder->payout_cents)->toBe(0); // platform revenue: no transfer

    $otherAdmin = adminUser();
    $shipped = $this->shipments->markShipped($otherAdmin, $sellerOrder, 'UPS', '1ZSDLABS1', 'https://ups.example/track/1ZSDLABS1');

    expect($shipped->status)->toBe(SellerOrderStatus::SHIPPED)
        ->and($shipped->tracking_number)->toBe('1ZSDLABS1')
        ->and($shipped->shipped_at)->not->toBeNull()
        ->and(fakeGateway()->callCount('createTransfer'))->toBe(0);

    Notification::assertSentTo($this->buyer, OrderShipped::class, 1);

    // the selling admin can update the tracking too
    expect($this->shipments->markShipped($seller, $shipped, 'UPS', '1ZFIXED')->tracking_number)->toBe('1ZFIXED');
});

it('also lets an admin ship over HTTP for a platform seller, but not a regular user', function () {
    [, $sellerOrder] = paidStoreOrder($this);

    foreach ([$this->buyer, onboardedSeller()] as $user) {
        $this->actingAs($user)
            ->post(route('selling.orders.ship', $sellerOrder), ['carrier' => 'UPS', 'tracking_number' => '1Z'])
            ->assertForbidden();
    }
    expect($sellerOrder->fresh()->status)->toBe(SellerOrderStatus::AWAITING_SHIPMENT);

    $this->actingAs(adminUser())
        ->post(route('selling.orders.ship', $sellerOrder), ['carrier' => 'UPS', 'tracking_number' => '1Z'])
        ->assertSessionHas('success');
    expect($sellerOrder->fresh()->status)->toBe(SellerOrderStatus::SHIPPED);
});

it('keeps regular users out of platform orders and admins out of regular sellers orders', function () {
    [, $storeOrder] = paidStoreOrder($this);
    [, $marketOrder] = paidPhysicalOrder($this);

    foreach ([onboardedSeller(), $this->seller, User::factory()->create()] as $user) {
        expect(fn () => $this->shipments->markShipped($user, $storeOrder, 'UPS', '1Z'))->toThrow(AuthorizationException::class);
    }

    // an admin is a platform seller, not a superuser over other members' sales
    expect(fn () => $this->shipments->markShipped(adminUser(), $marketOrder, 'UPS', '1Z'))->toThrow(AuthorizationException::class)
        ->and($storeOrder->fresh()->status)->toBe(SellerOrderStatus::AWAITING_SHIPMENT)
        ->and($marketOrder->fresh()->status)->toBe(SellerOrderStatus::AWAITING_SHIPMENT);
});

it('ships a platform order from the admin panel', function () {
    [$order, $sellerOrder] = paidStoreOrder($this);
    $admin = adminUser();
    $manager = Livewire\Livewire::actingAs($admin)->test(
        App\Filament\Resources\OrderResource\RelationManagers\SellerOrdersRelationManager::class,
        ['ownerRecord' => $order, 'pageClass' => App\Filament\Resources\OrderResource\Pages\ViewOrder::class],
    );

    $manager->assertTableActionVisible('markShipped', $sellerOrder)
        ->callTableAction('markShipped', $sellerOrder, data: ['carrier' => 'DHL', 'tracking_number' => 'JD0007', 'tracking_url' => 'https://dhl.example/JD0007'])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Marked as shipped');

    expect($sellerOrder->fresh())->status->toBe(SellerOrderStatus::SHIPPED)->carrier->toBe('DHL')->tracking_number->toBe('JD0007');

    // not offered for a regular seller's order
    [$marketOrderModel, $marketSellerOrder] = paidPhysicalOrder($this);
    Livewire\Livewire::actingAs($admin)->test(
        App\Filament\Resources\OrderResource\RelationManagers\SellerOrdersRelationManager::class,
        ['ownerRecord' => $marketOrderModel, 'pageClass' => App\Filament\Resources\OrderResource\Pages\ViewOrder::class],
    )->assertTableActionHidden('markShipped', $marketSellerOrder);
});
