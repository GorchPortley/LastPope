<?php

use App\Jobs\SendGa4Event;
use App\Models\AnalyticsEvent;
use App\Models\Order;
use App\Models\User;
use App\Services\Analytics\Ga4Reporter;
use App\Services\Marketplace\RefundService;
use App\Support\Analytics\Ga4;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/** Server-side purchase and refund through the GA4 Measurement Protocol (docs/PLAN.md 12). */
beforeEach(function () {
    config(['ga4.measurement_id' => 'G-TEST123', 'ga4.api_secret' => 'secret-xyz', 'marketplace.physical_payout_trigger' => 'shipped']);
    Http::fake(['www.google-analytics.com/*' => Http::response('', 204)]);
    Notification::fake();
    $this->buyer = User::factory()->create();
});

/** A pending order for a paid design, with what checkout stores from the buyer's cookies. */
function gaOrder(object $test, array $attributes = ['ga_client_id' => '1234.5678', 'analytics_consent' => true]): Order
{
    $order = pendingOrder($test->buyer, [paidDesign(onboardedSeller(), 3000)]);
    $order->forceFill($attributes)->save();

    return $order;
}

function mpCalls(): array
{
    return Http::recorded(fn ($request) => str_contains($request->url(), 'google-analytics.com/mp/collect'))->all();
}

it('sends the purchase when the order is paid, with the stored client id and a hashed user id', function () {
    $order = gaOrder($this);

    expect(payOrder($order))->toBeTrue();

    Http::assertSentCount(1);
    [$request] = mpCalls()[0];
    $body = $request->data();
    $event = $body['events'][0];

    expect($request->url())->toContain('measurement_id=G-TEST123')->toContain('api_secret=secret-xyz')
        ->and($body['client_id'])->toBe('1234.5678')
        ->and($body['user_id'])->toBe(Ga4::userId($this->buyer))
        ->and($body['user_id'])->not->toBe((string) $this->buyer->id)
        ->and($event['name'])->toBe('purchase')
        ->and($event['params']['transaction_id'])->toBe($order->number)
        ->and($event['params']['currency'])->toBe('USD')
        ->and($event['params']['value'])->toEqual(30)
        ->and($event['params']['items'][0])->toMatchArray(['item_category' => 'design', 'price' => 30.0, 'quantity' => 1])
        ->and(AnalyticsEvent::where('key', 'purchase:'.$order->number)->value('sent_at'))->not->toBeNull();
});

it('reports a purchase only once, however often the webhook or the job repeats', function () {
    $order = gaOrder($this);
    payOrder($order);

    payOrder($order->fresh());                          // redelivered webhook: already paid
    app(Ga4Reporter::class)->purchase($order->fresh()); // the job runs again
    SendGa4Event::dispatch('purchase:'.$order->number, ['client_id' => '1.2', 'events' => []]);

    Http::assertSentCount(1);
});

it('sends nothing without consent, without a client id, without an id or without the API secret', function (array $order, array $config) {
    config($config);
    $order = gaOrder($this, $order);

    expect(payOrder($order))->toBeTrue(); // the payment itself is never affected

    Http::assertNothingSent();
    expect(AnalyticsEvent::count())->toBe(0);
})->with([
    'consent denied' => [['ga_client_id' => '1.2', 'analytics_consent' => false], []],
    'consent unknown (order from before R7)' => [['ga_client_id' => '1.2', 'analytics_consent' => null], []],
    'no client id' => [['ga_client_id' => null, 'analytics_consent' => true], []],
    'no measurement id' => [['ga_client_id' => '1.2', 'analytics_consent' => true], ['ga4.measurement_id' => null]],
    'no API secret' => [['ga_client_id' => '1.2', 'analytics_consent' => true], ['ga4.api_secret' => null]],
]);

it('does not let a failing GA4 call break the payment, and sends again when the job is retried', function () {
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::fake(['www.google-analytics.com/*' => Http::response('', 500)]);
    $order = gaOrder($this);

    expect(payOrder($order))->toBeTrue()
        ->and($order->fresh()->paid_at)->not->toBeNull()
        ->and(AnalyticsEvent::where('key', 'purchase:'.$order->number)->value('sent_at'))->toBeNull();

    Http::swap(new Illuminate\Http\Client\Factory);
    Http::fake(['www.google-analytics.com/*' => Http::response('', 204)]);
    (new SendGa4Event('purchase:'.$order->number, ['client_id' => '1.2', 'events' => []]))->handle();

    expect(AnalyticsEvent::where('key', 'purchase:'.$order->number)->value('sent_at'))->not->toBeNull();
});

it('sends the refund with the refunded amount, once per Stripe refund', function () {
    $order = gaOrder($this);
    payOrder($order);
    $admin = User::factory()->admin()->create();

    $result = app(RefundService::class)->refundOrder($admin, $order->fresh(), 'Customer asked');

    $calls = collect(mpCalls())->map(fn ($call) => $call[0]->data()['events'][0])->keyBy('name');
    expect($calls->keys()->all())->toBe(['purchase', 'refund'])
        ->and($calls['refund']['params'])->toMatchArray(['transaction_id' => $order->number, 'value' => 30.0, 'currency' => 'USD'])
        ->and($calls['refund']['params']['items'])->toHaveCount(1);

    app(Ga4Reporter::class)->refund($order->fresh(), $result->amountCents, (string) $result->refundId);
    Http::assertSentCount(2);
});

it('stores the GA client id and the consent choice on the order at checkout', function () {
    $design = paidDesign(onboardedSeller(), 3000);
    fillCart($this->buyer, [$design]);

    $this->actingAs($this->buyer)
        ->withUnencryptedCookies(['_ga' => 'GA1.1.1234.5678', 'sdl_consent' => 'granted'])
        ->post(route('checkout.start'))
        ->assertRedirect();

    $order = Order::latest('id')->first();
    expect($order->ga_client_id)->toBe('1234.5678')->and($order->analytics_consent)->toBeTrue();

    $this->actingAs($this->buyer)
        ->withUnencryptedCookies(['_ga' => 'GA1.1.1234.5678', 'sdl_consent' => 'denied'])
        ->post(route('checkout.start'));

    $order = Order::latest('id')->first();
    expect($order->ga_client_id)->toBeNull()->and($order->analytics_consent)->toBeFalse();
});

it('renders the pages that send events without errors', function () {
    config(['ga4.measurement_id' => 'G-TEST123']);
    $seller = onboardedSeller();
    $driverListing = storeListing();
    $listing = sellableListing($seller, 4000);
    fillCart($this->buyer, [paidDesign($seller, 2000)]);

    $this->get(route('driver', ['id' => $driverListing->driver_id]))->assertOk()->assertSee("sdlGa4.track('view_item'", false);
    $this->get(route('listing', ['uuid' => $listing->uuid]))->assertOk()->assertSee("sdlGa4.track('view_item'", false);
    $this->get(route('marketplace', ['q' => 'speaker']))->assertOk()->assertSee("sdlGa4.track('search'", false);
    $this->actingAs($this->buyer)->get(route('checkout'))->assertOk()->assertSee("sdlGa4.track('begin_checkout'", false);

    $this->actingAs($seller);
    foreach (['dashboard.listings', 'dashboard.designs', 'dashboard.library', 'dashboard.orders', 'selling.dashboard'] as $name) {
        $this->get(route($name))->assertOk();
    }
});
