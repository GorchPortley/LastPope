<?php

use App\Http\Livewire\BuyBox;
use App\Models\User;
use App\Support\Analytics\Ga4;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/** GA4 in the browser (docs/PLAN.md 12): the tag, Consent Mode, the banner and the events the pages render. */
function withGa4(string $id = 'G-TEST123'): void
{
    config(['ga4.measurement_id' => $id]);
}

it('renders no tag, no banner and no cookie link without a measurement id', function () {
    config(['ga4.measurement_id' => null]);

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->not->toContain('googletagmanager')
        ->and($html)->not->toContain('gtag(')
        ->and($html)->not->toContain('cookie-consent')
        ->and($html)->not->toContain('data-cookie-settings');
});

it('renders the tag with consent denied by default, the banner and the footer link when an id is set', function () {
    withGa4();

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('googletagmanager.com/gtag/js?id=G-TEST123')
        ->and($html)->toContain("gtag('consent', 'default'")
        ->and($html)->toContain("analytics_storage: 'denied'")
        ->and($html)->toContain("ad_user_data: 'denied'")
        ->and($html)->toContain('id="cookie-consent"')
        ->and($html)->toContain('data-cookie-choice="granted"')
        ->and($html)->toContain('data-cookie-choice="denied"')
        ->and($html)->toContain('data-cookie-settings')
        // consent is set before the tag is configured
        ->and(strpos($html, "gtag('consent', 'default'"))->toBeLessThan(strpos($html, "gtag('config'"));
});

it('serves the same tag in the dashboard layout, with an HMAC user id and not the raw id', function () {
    withGa4();
    $user = User::factory()->create();

    $html = $this->actingAs($user)->get(route('dashboard.orders'))->assertOk()->getContent();
    $hash = hash_hmac('sha256', (string) $user->id, config('app.key'));

    expect($html)->toContain('googletagmanager.com/gtag/js?id=G-TEST123')
        ->and($html)->toContain("{user_id: '".$hash."'}")
        ->and($html)->not->toContain("user_id: '".$user->id."'")
        ->and(Ga4::userId($user))->toBe($hash)
        ->and(Ga4::userId(null))->toBeNull();
});

it('builds GA4 items in currency units for designs, drivers and listings', function () {
    config(['marketplace.currency' => 'eur']);
    $design = paidDesign(onboardedSeller(), 1550);
    $listing = sellableListing(onboardedSeller(), 6000);
    $store = storeListing(['price_cents' => 12900]);

    $item = Ga4::item($design);
    expect($item)->toMatchArray(['item_id' => 'design-'.$design->id, 'item_name' => $design->name, 'item_category' => 'design', 'price' => 15.5, 'quantity' => 1]);

    expect(Ga4::item($listing, 2))->toMatchArray(['item_id' => 'listing-'.$listing->id, 'price' => 60.0, 'quantity' => 2])
        ->and(Ga4::item($listing)['item_category'])->toBe(str_replace('_', ' ', $listing->category->value))
        // the store's driver listing is the same product as its driver page
        ->and(Ga4::item($store)['item_id'])->toBe('driver-'.$store->driver_id)
        ->and(Ga4::item($store)['item_brand'])->toBe(config('marketplace.store_name'))
        ->and(Ga4::item($store->driver)['item_category'])->toBe('driver');

    $params = Ga4::ecommerce([Ga4::item($design), Ga4::item($listing, 2)]);
    expect($params['currency'])->toBe('EUR')
        ->and($params['value'])->toBe(135.5)
        ->and($params['items'])->toHaveCount(2);
});

it('reads the client id from the _ga cookie and stores consent for checkout', function () {
    $request = fn (array $cookies) => Illuminate\Http\Request::create('/', 'POST', [], $cookies);

    expect(Ga4::clientId($request(['_ga' => 'GA1.1.1234567890.1700000000'])))->toBe('1234567890.1700000000')
        ->and(Ga4::clientId($request(['_ga' => 'garbage'])))->toBeNull()
        ->and(Ga4::checkoutAttributes($request(['_ga' => 'GA1.1.11.22', 'sdl_consent' => 'granted'])))->toBe(['ga_client_id' => '11.22', 'analytics_consent' => true])
        ->and(Ga4::checkoutAttributes($request(['_ga' => 'GA1.1.11.22', 'sdl_consent' => 'denied'])))->toBe(['ga_client_id' => null, 'analytics_consent' => false])
        ->and(Ga4::checkoutAttributes($request([])))->toBe(['ga_client_id' => null, 'analytics_consent' => false]);
});

it('sends view_item from a design page and view_cart from the cart', function () {
    withGa4();
    $design = paidDesign(onboardedSeller(), 2000);
    $buyer = User::factory()->create();

    $this->get(route('design', ['id' => $design->id]))
        ->assertOk()
        ->assertSee("sdlGa4.track('view_item'", false)
        ->assertSee('design-'.$design->id, false);

    fillCart($buyer, [$design]);

    $this->actingAs($buyer)->get(route('cart'))
        ->assertOk()
        ->assertSee("sdlGa4.track('view_cart'", false);
});

it('dispatches add_to_cart from the buy box and select_item markup on the cards', function () {
    withGa4();
    $design = paidDesign(onboardedSeller(), 2000);
    $buyer = User::factory()->create();

    Livewire::actingAs($buyer)
        ->test(BuyBox::class, ['purchasable' => $design])
        ->call('add')
        ->assertDispatched('ga4', fn (string $event, array $data) => $data['name'] === 'add_to_cart'
            && $data['params']['items'][0]['item_id'] === 'design-'.$design->id
            && $data['params']['value'] == 20);

    $this->get(route('designs'))
        ->assertOk()
        ->assertSee("sdlGa4.track('view_item_list'", false)
        ->assertSee('data-ga4-click', false)
        ->assertSee('select_item', false);
});

it('flashes sign_up and login for the next page, and a sign-up is not also a login', function () {
    withGa4();
    $user = User::factory()->create();

    Route::middleware('web')->post('/_ga4-test/login', function () use ($user) {
        event(new Login('web', $user, false));
        event(new Login('web', $user, false)); // the login form fires it twice

        return redirect('/');
    });
    Route::middleware('web')->post('/_ga4-test/register', function () use ($user) {
        event(new Registered($user));
        event(new Login('web', $user, true));

        return redirect('/');
    });

    $this->post('/_ga4-test/login')->assertRedirect('/');
    $html = $this->get('/')->getContent();
    expect(substr_count($html, "sdlGa4.track('login'"))->toBe(1)
        ->and($html)->not->toContain("sdlGa4.track('sign_up'");

    $this->post('/_ga4-test/register');
    $html = $this->get('/')->getContent();
    expect($html)->toContain("sdlGa4.track('sign_up'")
        ->and($html)->not->toContain("sdlGa4.track('login'");

    // shown once
    expect($this->get('/')->getContent())->not->toContain("sdlGa4.track('sign_up'");
});
