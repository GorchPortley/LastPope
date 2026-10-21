<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;

beforeEach(function () {
    config(['marketplace.fee_bps' => 1000, 'marketplace.stripe_tax' => false]);
    $this->buyer = User::factory()->create();
});

// --- POST /checkout -----------------------------------------------------------------------------

it('keeps guests out of checkout', function () {
    $this->post(route('checkout.start'))->assertRedirect(route('login'));
    $this->get(route('checkout.success', ['session_id' => 'cs_x']))->assertRedirect();
});

it('starts a payment for a digital cart without an address and redirects to Stripe', function () {
    fillCart($this->buyer, [paidDesign(cents: 1500)]);

    $response = $this->actingAs($this->buyer)->post(route('checkout.start'));

    $order = Order::where('user_id', $this->buyer->id)->sole();
    $response->assertRedirect('https://checkout.stripe.test/c/pay/'.$order->stripe_checkout_session_id);
    expect($order->status)->toBe(OrderStatus::PENDING)->and($order->total_cents)->toBe(1500);
});

it('starts a payment for physical items with one of the buyers addresses', function () {
    fillCart($this->buyer, [sellableListing()]);
    $address = addressFor($this->buyer, 'US');

    $this->actingAs($this->buyer)->post(route('checkout.start'), ['address_id' => $address->id])
        ->assertRedirectContains('https://checkout.stripe.test/');

    expect(Order::where('user_id', $this->buyer->id)->sole()->shipping_address['country'])->toBe('US');
});

it('sends the buyer back to pick an address when physical items have none', function () {
    fillCart($this->buyer, [sellableListing()]);

    $this->actingAs($this->buyer)->post(route('checkout.start'))
        ->assertRedirect(route('checkout'))
        ->assertSessionHas('error', 'Choose a shipping address to continue.');

    expect(Order::where('user_id', $this->buyer->id)->count())->toBe(0);
});

it('does not accept somebody elses address', function () {
    fillCart($this->buyer, [sellableListing()]);
    $foreign = addressFor(User::factory()->create());

    $this->actingAs($this->buyer)->post(route('checkout.start'), ['address_id' => $foreign->id])
        ->assertRedirect(route('checkout'))
        ->assertSessionHas('error');

    expect(Order::where('user_id', $this->buyer->id)->count())->toBe(0)
        ->and(fakeGateway()->callCount('createCheckoutSession'))->toBe(0);
});

it('sends the buyer to the cart with the reasons when items are unavailable', function () {
    $design = paidDesign();
    fillCart($this->buyer, [$design]);
    $design->update(['active' => false]);

    $this->actingAs($this->buyer)->post(route('checkout.start'))
        ->assertRedirect(route('cart'))
        ->assertSessionHas('error')
        ->assertSessionHas('checkout_issues', fn ($issues) => count($issues) === 1);
});

it('refuses to charge more than the total the buyer saw', function () {
    $design = paidDesign(cents: 1500);
    fillCart($this->buyer, [$design]);
    $design->update(['price_cents' => 1800]);

    $this->actingAs($this->buyer)->post(route('checkout.start'), ['expected_total_cents' => 1500])
        ->assertRedirect(route('cart'))
        ->assertSessionHas('error');

    $this->actingAs($this->buyer)->post(route('checkout.start'), ['expected_total_cents' => 1800])
        ->assertRedirectContains('https://checkout.stripe.test/');
});

it('answers JSON clients with the URL or a typed error', function () {
    $this->actingAs($this->buyer)->postJson(route('checkout.start'))
        ->assertStatus(422)
        ->assertJson(['error' => 'empty_cart', 'message' => 'Your cart is empty.']);

    fillCart($this->buyer, [paidDesign(cents: 1500)]);
    $response = $this->actingAs($this->buyer)->postJson(route('checkout.start'))->assertOk();

    expect($response->json('url'))->toStartWith('https://checkout.stripe.test/')
        ->and($response->json('order'))->toBe(Order::where('user_id', $this->buyer->id)->sole()->number);
});

// --- Success and cancel pages -------------------------------------------------------------------

it('shows the buyer their paid order on the success page', function () {
    $order = pendingOrder($this->buyer, [paidDesign(cents: 1500)]);
    payOrder($order);

    $this->actingAs($this->buyer)->get(route('checkout.success', ['session_id' => $order->stripe_checkout_session_id]))
        ->assertOk()
        ->assertSee($order->number)
        ->assertSee('Thank you for your order')
        ->assertSee('$15.00')
        ->assertDontSee('Confirming your payment');
});

it('only shows the order of the logged-in buyer: others get a 404', function () {
    $order = pendingOrder($this->buyer, [paidDesign(cents: 1500)]);
    payOrder($order);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get(route('checkout.success', ['session_id' => $order->stripe_checkout_session_id]))->assertNotFound();
    $this->actingAs($this->buyer)->get(route('checkout.success', ['session_id' => 'cs_unknown']))->assertNotFound();
    $this->actingAs($this->buyer)->get(route('checkout.success'))->assertNotFound();
});

it('never fulfils from the success page: a pending order stays pending and polls for its status', function () {
    $design = paidDesign(cents: 1500);
    $order = pendingOrder($this->buyer, [$design]);
    $gatewayCalls = count(fakeGateway()->calls);

    $this->actingAs($this->buyer)->get(route('checkout.success', ['session_id' => $order->stripe_checkout_session_id]))
        ->assertOk()
        ->assertSee('Confirming your payment')
        ->assertSee('wire:poll.3s="poll"', false)
        ->assertDontSee('http-equiv="refresh"', false);

    // after a while it stops polling and points to the orders page
    $status = Livewire\Livewire::actingAs($this->buyer)->test(App\Http\Livewire\CheckoutStatus::class, ['order' => $order]);
    $status->assertSee('This page updates by itself')->assertSeeHtml('wire:poll.3s="poll"');

    foreach (range(1, App\Http\Livewire\CheckoutStatus::MAX_POLLS) as $ignored) {
        $status->call('poll');
    }
    $status->assertSee('taking longer')->assertDontSeeHtml('wire:poll');

    expect($order->fresh()->status)->toBe(OrderStatus::PENDING)
        ->and($this->buyer->hasAccessTo($design, false))->toBeFalse()
        ->and(count(fakeGateway()->calls))->toBe($gatewayCalls);
});

it('switches the success page to the receipt by itself once the webhook has paid the order', function () {
    $order = pendingOrder($this->buyer, [paidDesign(cents: 1500)]);
    $status = Livewire\Livewire::actingAs($this->buyer)->test(App\Http\Livewire\CheckoutStatus::class, ['order' => $order]);
    $status->assertSee('Confirming your payment')->assertDontSee('Thank you for your order');

    payOrder($order);

    $status->call('poll')
        ->assertSee('Thank you for your order')
        ->assertSee($order->number)
        ->assertSee('$15.00')
        ->assertSee('Open my library')
        ->assertDontSeeHtml('wire:poll');
});

it('does not show the order of somebody else through the status component', function () {
    $order = pendingOrder($this->buyer, [paidDesign(cents: 1500)]);

    Livewire\Livewire::actingAs(User::factory()->create())->test(App\Http\Livewire\CheckoutStatus::class, ['order' => $order])
        ->assertNotFound();
});

it('says so on the success page when the order was cancelled', function () {
    $order = pendingOrder($this->buyer, [paidDesign(cents: 1500)]);
    app(App\Services\Marketplace\OrderFulfillment::class)->cancelPending($order);

    $this->actingAs($this->buyer)->get(route('checkout.success', ['session_id' => $order->stripe_checkout_session_id]))
        ->assertOk()->assertSee('was cancelled');
});

it('shows the cancel page', function () {
    $this->actingAs($this->buyer)->get(route('checkout.cancel', ['order' => 'SDL-ABC123']))
        ->assertOk()->assertSee('Payment cancelled')->assertSee('SDL-ABC123');
});

// --- Cart and checkout pages --------------------------------------------------------------------

it('shows a working Checkout button on a digital cart', function () {
    $design = paidDesign(cents: 1500);
    fillCart($this->buyer, [$design]);

    $this->actingAs($this->buyer)->get('/cart')
        ->assertOk()
        ->assertSee($design->name)
        ->assertSee('$15.00')
        ->assertSee('action="'.route('checkout.start').'"', false)
        ->assertSee('name="_token"', false)
        ->assertSee('Checkout');
});

it('links physical carts to the address step and lists the seller groups', function () {
    $listing = sellableListing(attributes: ['title' => 'Group Woofer']);
    fillCart($this->buyer, [$listing, paidDesign()]);

    $this->actingAs($this->buyer)->get('/cart')
        ->assertOk()
        ->assertSee('Group Woofer')
        ->assertSee('Continue to shipping')
        ->assertSee(route('checkout'), false)
        ->assertDontSee('action="'.route('checkout.start').'"', false)
        ->assertSee('Sold by');
});

it('warns about unavailable and price-changed items on the cart page', function () {
    $design = paidDesign(cents: 2000);
    fillCart($this->buyer, [$design]);
    $design->update(['price_cents' => 2400]);

    $this->actingAs($this->buyer)->get('/cart')
        ->assertOk()->assertSee('The price changed from $20.00 to $24.00');

    $design->update(['active' => false]);
    $this->actingAs($this->buyer)->get('/cart')
        ->assertOk()->assertSee('is not available right now')->assertSee('Fix the items marked above');
});

it('updates and removes cart lines from the cart page', function () {
    $listing = sellableListing(attributes: ['quantity' => 4]);
    $result = app(App\Services\Marketplace\CartService::class)->add($this->buyer, $listing);
    $page = voltPage($this, $this->buyer, '/cart', 'cart');

    $page->call('updateQuantity', $result->item->id, 3)->assertSee('Your cart was updated.');
    expect($result->item->fresh()->quantity)->toBe(3);

    $page->call('updateQuantity', $result->item->id, 9)->assertSee('available');
    expect($result->item->fresh()->quantity)->toBe(3);

    $page->call('removeItem', $result->item->id)->assertSee('Your cart is empty');
    expect($result->item->fresh())->toBeNull();
});

it('lets the buyer add an address on the checkout page and pays with it', function () {
    $listing = sellableListing();
    fillCart($this->buyer, [$listing]);

    $this->actingAs($this->buyer)->get('/checkout')->assertOk()->assertSee('Shipping address')->assertSee('choose an address');

    $page = voltPage($this, $this->buyer, '/checkout', 'checkout');
    $page->set('form.name', 'Ada Builder')
        ->set('form.line1', '12 Speaker Street')
        ->set('form.city', 'Austin')
        ->set('form.state', 'TX')
        ->set('form.postal_code', '78701')
        ->set('form.country', 'US')
        ->call('saveAddress')
        ->assertHasNoErrors()
        ->assertSee('12 Speaker Street')
        ->assertSee('Pay ');

    $address = $this->buyer->addresses()->sole();
    expect($address->city)->toBe('Austin')->and($address->country)->toBe('US');

    $this->actingAs($this->buyer)->get('/checkout')
        ->assertOk()
        ->assertSee('action="'.route('checkout.start').'"', false)
        ->assertSee('name="address_id" value="'.$address->id.'"', false);
});

it('validates the address form', function () {
    fillCart($this->buyer, [sellableListing()]);
    $page = voltPage($this, $this->buyer, '/checkout', 'checkout');

    $page->set('form.name', '')->set('form.country', 'ZZ')->call('saveAddress')
        ->assertHasErrors(['form.name', 'form.line1', 'form.city', 'form.postal_code', 'form.country']);

    expect($this->buyer->addresses()->count())->toBe(0);
});

it('shows the shipping per seller and blocks pay when a seller does not ship there', function () {
    $domesticOnly = sellableListing(attributes: ['title' => 'Domestic Only', 'ships_from_country' => 'US', 'shipping_international_cents' => null]);
    fillCart($this->buyer, [$domesticOnly]);
    addressFor($this->buyer, 'DE');

    $this->actingAs($this->buyer)->get('/checkout')
        ->assertOk()
        ->assertSee('does not ship Domestic Only to DE')
        ->assertDontSee('action="'.route('checkout.start').'"', false);
});

it('needs no address for digital carts on the checkout page', function () {
    fillCart($this->buyer, [paidDesign(cents: 1500)]);

    $this->actingAs($this->buyer)->get('/checkout')
        ->assertOk()
        ->assertSee('no shipping address is needed')
        ->assertSee('action="'.route('checkout.start').'"', false)
        ->assertDontSee('name="address_id"', false);
});

it('says the cart is empty on the checkout page', function () {
    $this->actingAs($this->buyer)->get('/checkout')->assertOk()->assertSee('Your cart is empty');
});
