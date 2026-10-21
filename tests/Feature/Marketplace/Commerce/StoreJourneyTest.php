<?php

use App\Enums\ListingStatus;
use App\Enums\OrderStatus;
use App\Enums\SellerOrderStatus;
use App\Http\Livewire\BuyBox;
use App\Models\Driver;
use App\Models\Order;
use App\Models\User;
use App\Services\Marketplace\CartService;
use Livewire\Livewire;

/*
| R1: a design (digital, paid to its designer), a new driver from the SDLabs store (admin shipped) and a
| kit from a member (seller shipped) in one cart, paid through the fake payment gateway and Stripe's webhook.
*/

it('buys a design, a store driver and a member kit in one checkout, and each seller side ships its own', function () {
    config([
        'marketplace.stripe.marketplace_webhook_secret' => 'whsec_store',
        'marketplace.physical_payout_trigger' => 'shipped',
    ]);

    $buyer = User::factory()->create();
    $designer = onboardedSeller();
    $member = onboardedSeller();
    $admin = adminUser();

    $design = paidDesign($designer, 3000);
    $driver = Driver::factory()->create(['brand' => 'Storeprobe', 'model' => 'J1']);
    $store = storeListing(['price_cents' => 8000, 'quantity' => 10, 'shipping_domestic_cents' => 900], $driver, $admin);
    $kit = sellableListing($member, 15000, ['quantity' => 2, 'shipping_domestic_cents' => 1500]);
    $address = addressFor($buyer);

    // 1. the driver page offers the store listing; the design page its buy box
    $this->actingAs($buyer)->get(route('driver', ['id' => $driver->id]))->assertOk()->assertSee('Sold by SDLabs')->assertSee('$80.00');

    Livewire::actingAs($buyer)->test(BuyBox::class, ['purchasable' => $store])->set('quantity', 2)->call('add');
    expect(app(CartService::class)->count($buyer))->toBe(2);

    $cart = app(CartService::class);
    expect($cart->add($buyer, $design)->ok())->toBeTrue()
        ->and($cart->add($buyer, $kit)->ok())->toBeTrue();

    // 2. checkout creates one order with three seller sides, the platform's included
    $this->actingAs($buyer)->post(route('checkout.start'), ['address_id' => $address->id])->assertRedirectContains('checkout.stripe.test');
    $order = Order::where('user_id', $buyer->id)->sole();
    expect($order->sellerOrders()->count())->toBe(3)
        ->and($order->subtotal_cents)->toBe(3000 + 2 * 8000 + 15000);

    // 3. Stripe confirms the payment
    postStripeEvent($this, 'stripe.marketplace.webhook', stripeEventPayload('checkout.session.completed', checkoutSessionObject($order, 'paid', 'pi_store')), 'whsec_store')->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::PAID)
        ->and($buyer->hasAccessTo($design, false))->toBeTrue()
        ->and($store->fresh()->quantity)->toBe(8)
        ->and($kit->fresh()->quantity)->toBe(1)
        ->and($driver->storeListing()?->is($store))->toBeTrue();

    $storeOrder = $order->sellerOrders()->where('seller_id', $admin->id)->sole();
    $kitOrder = $order->sellerOrders()->where('seller_id', $member->id)->sole();
    $designOrder = $order->sellerOrders()->where('seller_id', $designer->id)->sole();

    expect($storeOrder->status)->toBe(SellerOrderStatus::AWAITING_SHIPMENT)
        ->and($storeOrder->platform_fee_cents)->toBe(0)
        ->and($storeOrder->isTransferred())->toBeFalse()
        ->and($designOrder->status)->toBe(SellerOrderStatus::COMPLETED)->and($designOrder->isTransferred())->toBeTrue()
        ->and($kitOrder->isTransferred())->toBeFalse();

    // 4. nobody but the right people can ship
    $ship = fn (App\Models\SellerOrder $sellerOrder) => ['carrier' => 'UPS', 'tracking_number' => '1Z'.$sellerOrder->id];
    $this->actingAs($member)->post(route('selling.orders.ship', $storeOrder), $ship($storeOrder))->assertForbidden();
    $this->actingAs($buyer)->post(route('selling.orders.ship', $storeOrder), $ship($storeOrder))->assertForbidden();
    $this->actingAs($admin)->post(route('selling.orders.ship', $kitOrder), $ship($kitOrder))->assertForbidden();
    expect($storeOrder->fresh()->status)->toBe(SellerOrderStatus::AWAITING_SHIPMENT);

    // 5. an admin ships the store part, the member the kit; the buyer confirms both
    $this->actingAs(adminUser())->post(route('selling.orders.ship', $storeOrder), $ship($storeOrder))->assertSessionHas('success');
    $this->actingAs($member)->post(route('selling.orders.ship', $kitOrder), $ship($kitOrder))->assertSessionHas('success');

    expect($storeOrder->fresh()->status)->toBe(SellerOrderStatus::SHIPPED)
        ->and($kitOrder->fresh()->isTransferred())->toBeTrue();

    $this->actingAs($buyer)->post(route('orders.received', $storeOrder))->assertSessionHas('success');
    $this->actingAs($buyer)->post(route('orders.received', $kitOrder))->assertSessionHas('success');

    expect($order->fresh()->status)->toBe(OrderStatus::COMPLETED)
        ->and(fakeGateway()->callCount('createTransfer'))->toBe(2); // designer and member; the platform keeps its own revenue
});

it('sells the last unit of a store listing once and marks it sold out', function () {
    $driver = Driver::factory()->create();
    $store = storeListing(['quantity' => 1], $driver);
    $first = User::factory()->create();
    $second = User::factory()->create();

    $order = pendingOrder($first, [$store], addressFor($first));
    expect(app(CartService::class)->add($second, $store)->ok())->toBeFalse(); // held by the first checkout

    payOrder($order);

    expect($store->fresh()->status)->toBe(ListingStatus::SOLD_OUT)
        ->and($store->fresh()->quantity)->toBe(0)
        ->and($driver->storeListing())->toBeNull();
});
