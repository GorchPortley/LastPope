<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Marketplace helpers
|--------------------------------------------------------------------------
*/

/** A user whose Stripe account can take charges and receive payouts. */
function onboardedSeller(): App\Models\User
{
    return App\Models\User::factory()->onboardedSeller()->create();
}

/** A paid, active design with a snapshot (and its ZIP on the private disk when $withFile), by $seller. */
function paidDesign(?App\Models\User $seller = null, int $cents = 1500, bool $withFile = true): App\Models\Design
{
    return App\Models\Design::factory()
        ->for($seller ?? onboardedSeller(), 'designer')
        ->paid($cents)
        ->withSnapshot('v1.0', $withFile)
        ->create();
}

/** Open a Folio/Volt dashboard page as $user and return its Livewire test component. */
function dashboardPage(Tests\TestCase $test, App\Models\User $user, string $url): Livewire\Features\SupportTesting\Testable
{
    $html = $test->actingAs($user)->get($url)->assertOk()->getContent();
    preg_match('/wire:snapshot="([^"]+)"/', $html, $matches);
    $snapshot = json_decode(html_entity_decode($matches[1]), true);

    return Livewire\Livewire::test($snapshot['memo']['name']);
}

/*
|--------------------------------------------------------------------------
| Commerce helpers (carts, checkout, Stripe webhooks)
|--------------------------------------------------------------------------
*/

/** The recording fake payment gateway the container uses in tests. */
function fakeGateway(): App\Services\Payments\FakePaymentGateway
{
    return app(App\Services\Payments\PaymentGateway::class);
}

/** An active listing with stock, sold by an onboarded seller (or $seller). */
function sellableListing(?App\Models\User $seller = null, int $cents = 5000, array $attributes = []): App\Models\Listing
{
    return App\Models\Listing::factory()
        ->forSeller($seller ?? onboardedSeller())
        ->create(array_merge(['price_cents' => $cents, 'quantity' => 5], $attributes));
}

/**
 * An active STORE listing (SDLabs sells a new catalogue driver): sold by $admin (a new admin by default),
 * for $driver (a new driver by default), 5-40 units unless the attributes say otherwise.
 */
function storeListing(array $attributes = [], ?App\Models\Driver $driver = null, ?App\Models\User $admin = null): App\Models\Listing
{
    return App\Models\Listing::factory()->inStore($driver, $admin)->create(array_merge(['price_cents' => 12900], $attributes));
}

function addressFor(App\Models\User $user, string $country = 'US', bool $default = true): App\Models\Address
{
    return App\Models\Address::factory()->for($user)->inCountry($country)->state(['is_default' => $default])->create();
}

/** Put purchasables ([Purchasable, qty] or Purchasable) into the user's cart through CartService. */
function fillCart(App\Models\User $user, array $items): void
{
    foreach ($items as $entry) {
        [$purchasable, $quantity] = is_array($entry) ? [$entry[0], $entry[1] ?? 1] : [$entry, 1];
        app(App\Services\Marketplace\CartService::class)->add($user, $purchasable, $quantity);
    }
}

/** Start a checkout for the user's current cart. */
function startCheckout(App\Models\User $buyer, ?App\Models\Address $address = null): App\Services\Marketplace\Data\CheckoutResult
{
    return app(App\Services\Marketplace\CheckoutService::class)->start($buyer, $address);
}

/** A pending order for $items ([Purchasable, qty] or Purchasable), created through the real cart + checkout. */
function pendingOrder(App\Models\User $buyer, array $items, ?App\Models\Address $address = null): App\Models\Order
{
    fillCart($buyer, $items);

    return startCheckout($buyer, $address)->order;
}

/** Pay an order the way the webhook does. Returns true when this call fulfilled it. */
function payOrder(App\Models\Order $order, string $paymentIntent = 'pi_test_1', string $charge = 'ch_test_1'): bool
{
    return app(App\Services\Marketplace\OrderFulfillment::class)->markPaid($order, $charge, $paymentIntent);
}

/** A Stripe event payload (array) shaped like the real thing. */
function stripeEventPayload(string $type, array $object, array $extra = []): array
{
    return array_merge([
        'id' => 'evt_'.Illuminate\Support\Str::random(16),
        'object' => 'event',
        'api_version' => '2024-06-20',
        'created' => time(),
        'type' => $type,
        'livemode' => false,
        'pending_webhooks' => 1,
        'data' => ['object' => $object],
    ], $extra);
}

/** POST a signed (or deliberately mis-signed) Stripe event to a webhook route. */
function postStripeEvent(Tests\TestCase $test, string $routeName, array $event, ?string $secret, ?string $signature = null): Illuminate\Testing\TestResponse
{
    $payload = json_encode($event);
    $signature ??= Stripe\WebhookSignature::generateSignatureHeader($payload, (string) $secret);

    return $test->call('POST', route($routeName), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $signature,
    ], $payload);
}

/** The Checkout Session object Stripe sends in checkout.session.* events for $order. */
function checkoutSessionObject(App\Models\Order $order, string $paymentStatus = 'paid', string $paymentIntent = 'pi_test_1', array $extra = []): array
{
    return array_merge([
        'id' => $order->stripe_checkout_session_id,
        'object' => 'checkout.session',
        'payment_status' => $paymentStatus,
        'payment_intent' => $paymentIntent,
        'amount_total' => $order->total_cents,
        'currency' => $order->currency,
        'total_details' => ['amount_tax' => 0],
        'metadata' => ['order_id' => (string) $order->getKey(), 'order_number' => $order->number],
    ], $extra);
}

/**
 * Open a Folio page that contains `@volt('<name>')` as $user and return that Volt component's Livewire
 * test (unlike dashboardPage(), it skips the header components of the marketing layout).
 */
function voltPage(Tests\TestCase $test, App\Models\User $user, string $url, string $voltName): Livewire\Features\SupportTesting\Testable
{
    $html = $test->actingAs($user)->get($url)->assertOk()->getContent();
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

    foreach ($matches[1] as $encoded) {
        $snapshot = json_decode(html_entity_decode($encoded), true);
        $name = $snapshot['memo']['name'] ?? '';

        if (str_starts_with($name, 'volt-anonymous-fragment-')) {
            $meta = json_decode((string) base64_decode(substr($name, strlen('volt-anonymous-fragment-'))), true);

            if (($meta['name'] ?? null) === $voltName) {
                return Livewire\Livewire::test($name);
            }
        }
    }

    throw new RuntimeException("No Volt component [{$voltName}] on {$url}.");
}

/*
|--------------------------------------------------------------------------
| UI helpers (marketplace pages, dashboard, admin)
|--------------------------------------------------------------------------
*/

/**
 * A paid order with one physical listing from $seller (an onboarded one by default), shipped to a US address and
 * awaiting shipment. Returns the order, the listing, the seller and the seller's part of the order.
 *
 * @return array{order: App\Models\Order, listing: App\Models\Listing, seller: App\Models\User, sellerOrder: App\Models\SellerOrder}
 */
function paidListingOrder(App\Models\User $buyer, ?App\Models\User $seller = null, int $cents = 6000, array $listing = []): array
{
    $seller ??= onboardedSeller();
    $item = sellableListing($seller, $cents, array_merge(['quantity' => 3, 'shipping_domestic_cents' => 900], $listing));
    $order = pendingOrder($buyer, [$item], addressFor($buyer));
    payOrder($order, 'pi_'.Illuminate\Support\Str::random(10), 'ch_'.Illuminate\Support\Str::random(10));

    return [
        'order' => $order->fresh(),
        'listing' => $item,
        'seller' => $seller,
        'sellerOrder' => $order->sellerOrders()->where('seller_id', $seller->getKey())->firstOrFail(),
    ];
}

function adminUser(): App\Models\User
{
    return App\Models\User::factory()->admin()->create();
}
