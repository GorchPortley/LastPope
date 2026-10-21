<?php

use App\Enums\OrderStatus;
use App\Enums\SellerOrderStatus;
use App\Models\Order;
use App\Models\User;

it('walks a multi-seller purchase from the cart to the finished order over HTTP', function () {
    config([
        'marketplace.stripe.marketplace_webhook_secret' => 'whsec_journey',
        'marketplace.physical_payout_trigger' => 'shipped',
    ]);

    $buyer = User::factory()->create();
    $designer = onboardedSeller();
    $shop = onboardedSeller();
    $design = paidDesign($designer, 3000);
    $woofer = sellableListing($shop, 9000, ['title' => 'Journey Woofer', 'quantity' => 1, 'shipping_domestic_cents' => 1200]);
    $address = addressFor($buyer);

    // 1. fill the cart
    $cart = app(App\Services\Marketplace\CartService::class);
    expect($cart->add($buyer, $design)->ok())->toBeTrue()
        ->and($cart->add($buyer, $woofer)->ok())->toBeTrue();

    // 2. pay on Stripe
    $this->actingAs($buyer)->post(route('checkout.start'), ['address_id' => $address->id])->assertRedirectContains('checkout.stripe.test');
    $order = Order::where('user_id', $buyer->id)->sole();
    expect($order->total_cents)->toBe(3000 + 9000 + 1200);

    // 3. the buyer comes back before the webhook: pending
    $this->actingAs($buyer)->get(route('checkout.success', ['session_id' => $order->stripe_checkout_session_id]))->assertSee('Confirming your payment');

    // 4. Stripe confirms
    $event = stripeEventPayload('checkout.session.completed', checkoutSessionObject($order, 'paid', 'pi_journey'));
    postStripeEvent($this, 'stripe.marketplace.webhook', $event, 'whsec_journey')->assertOk();

    $this->actingAs($buyer)->get(route('checkout.success', ['session_id' => $order->stripe_checkout_session_id]))->assertSee('Thank you for your order');
    expect($buyer->hasAccessTo($design, false))->toBeTrue()
        ->and($woofer->fresh()->quantity)->toBe(0)
        ->and($order->fresh()->status)->toBe(OrderStatus::PAID)
        ->and($order->sellerOrders()->where('seller_id', $designer->id)->first()->isTransferred())->toBeTrue()
        ->and($order->sellerOrders()->where('seller_id', $shop->id)->first()->isTransferred())->toBeFalse();

    // 5. the shop ships, the buyer confirms
    $shopOrder = $order->sellerOrders()->where('seller_id', $shop->id)->first();
    $this->actingAs($shop)->post(route('selling.orders.ship', $shopOrder), ['carrier' => 'UPS', 'tracking_number' => '1Z1'])->assertSessionHas('success');
    expect($shopOrder->fresh()->isTransferred())->toBeTrue();

    $this->actingAs($buyer)->post(route('orders.received', $shopOrder))->assertSessionHas('success');

    expect($order->fresh()->status)->toBe(OrderStatus::COMPLETED)
        ->and($order->sellerOrders()->pluck('status')->unique()->all())->toBe([SellerOrderStatus::COMPLETED])
        ->and(fakeGateway()->callCount('createTransfer'))->toBe(2)
        ->and($cart->count($buyer))->toBe(0);
});

it('simulates a payment for local development, but only with the fake gateway', function () {
    $buyer = User::factory()->create();
    $order = pendingOrder($buyer, [paidDesign(cents: 1000)]);

    $this->artisan('marketplace:simulate-payment', ['order' => $order->number])->expectsOutputToContain('paid and fulfilled')->assertSuccessful();
    expect($order->fresh()->status)->toBe(OrderStatus::COMPLETED);

    $this->artisan('marketplace:simulate-payment', ['order' => $order->number])->expectsOutputToContain('already paid')->assertSuccessful();
    $this->artisan('marketplace:simulate-payment', ['order' => 'SDL-NOPE00'])->assertFailed();

    $this->app->instance(App\Services\Payments\PaymentGateway::class, new App\Services\Payments\StripeGateway('sk_test_x'));
    $this->artisan('marketplace:simulate-payment', ['order' => $order->number])->assertFailed();
});
