<?php

use App\Http\Livewire\BuyBox;
use App\Http\Livewire\CartIcon;
use App\Models\Design;
use App\Models\Driver;
use App\Models\Listing;
use App\Models\User;
use App\Services\Marketplace\CartService;
use Livewire\Livewire;

beforeEach(function () {
    $this->buyer = User::factory()->create();
    $this->carts = app(CartService::class);
});

it('asks guests to log in and sends them to the login page when they press the button', function () {
    $design = paidDesign(onboardedSeller(), 1500);

    Livewire::test(BuyBox::class, ['purchasable' => $design])
        ->assertSee('$15.00')
        ->assertSee('Log in to buy')
        ->assertSeeHtml('data-buy-box="login"')
        ->call('add')
        ->assertRedirect(route('login'));

    Livewire::test(BuyBox::class, ['purchasable' => Design::factory()->free()->withSnapshot()->create()])
        ->assertSee('Free')->assertSee('Log in to get it for free');
});

it('adds a paid design to the cart and then shows it as in the cart', function () {
    $design = paidDesign(onboardedSeller(), 1500);

    Livewire::actingAs($this->buyer)->test(BuyBox::class, ['purchasable' => $design])
        ->assertSee('Add to cart')
        ->assertSeeHtml('data-buy-box="add"')
        ->call('add')
        ->assertDispatched('cart-updated')
        ->assertSee('was added to your cart')
        ->assertSee('In your cart')
        ->assertSeeHtml('data-buy-box="in_cart"');

    expect($this->carts->count($this->buyer))->toBe(1);

    // already there: shown that way from the start
    Livewire::actingAs($this->buyer)->test(BuyBox::class, ['purchasable' => $design])->assertSeeHtml('data-buy-box="in_cart"');
});

it('shows "Free" and takes a free digital item straight into the library without touching the cart', function () {
    $free = Design::factory()->free()->withSnapshot('v1.0', true)->create();

    $box = Livewire::actingAs($this->buyer)->test(BuyBox::class, ['purchasable' => $free])
        ->assertSee('Free')->assertSee('Get for free')->assertSeeHtml('data-buy-box="free"');

    // rendering is side-effect free: no entitlement yet, although a free item is downloadable
    expect($this->buyer->entitlements()->count())->toBe(0);

    $box->call('add')->assertDispatched('cart-updated')->assertSee('In your library')->assertSee('Download')->assertSeeHtml('data-buy-box="owned"');

    expect($this->buyer->entitlementFor($free)?->isActive())->toBeTrue()
        ->and($this->carts->count($this->buyer))->toBe(0);
});

it('offers a download for items in the library, never a second purchase', function () {
    $design = paidDesign(onboardedSeller(), 1500);
    $this->buyer->grantEntitlement($design);

    Livewire::actingAs($this->buyer)->test(BuyBox::class, ['purchasable' => $design])
        ->assertSee('In your library')
        ->assertSee(route('design-snapshots.download', $design->snapshots->first()), false)
        ->assertDontSee('Add to cart')
        ->assertSeeHtml('data-buy-box="owned"');
});

it('lets admins download without owning, and tells the designer it is their own item', function () {
    $design = paidDesign(onboardedSeller(), 1500);

    Livewire::actingAs(adminUser())->test(BuyBox::class, ['purchasable' => $design])->assertSee('In your library')->assertSee('Download');

    $owner = Livewire::actingAs($design->designer)->test(BuyBox::class, ['purchasable' => $design])
        ->assertSee('your own design')->assertSeeHtml('data-buy-box="owner"')->assertDontSee('Add to cart');
    $owner->call('add');
    expect($this->carts->count($design->designer))->toBe(0);
});

it('says why a revoked or unavailable item cannot be bought', function () {
    $revoked = paidDesign(onboardedSeller(), 1500);
    $this->buyer->grantEntitlement($revoked)->revoke();
    Livewire::actingAs($this->buyer)->test(BuyBox::class, ['purchasable' => $revoked])->assertSee('withdrawn')->assertDontSee('Add to cart');

    $unpublished = Design::factory()->paid(1000)->draft()->withSnapshot()->for(onboardedSeller(), 'designer')->create();
    Livewire::actingAs($this->buyer)->test(BuyBox::class, ['purchasable' => $unpublished])
        ->assertSee('This item is not published.')->assertSeeHtml('data-buy-box="unavailable"')->assertDontSee('Add to cart');

    $noFiles = Design::factory()->paid(1000)->for(onboardedSeller(), 'designer')->create();
    Livewire::actingAs($this->buyer)->test(BuyBox::class, ['purchasable' => $noFiles])->assertSee('No files have been published');

    $unpaid = Design::factory()->paid(1000)->withSnapshot()->for(User::factory()->create(), 'designer')->create();
    Livewire::actingAs($this->buyer)->test(BuyBox::class, ['purchasable' => $unpaid])->assertSee('seller cannot receive payments yet');
});

it('handles listings: quantity, stock, sold out and listings of another state', function () {
    $listing = sellableListing(attributes: ['quantity' => 5, 'units_per_lot' => 2]);

    $box = Livewire::actingAs($this->buyer)->test(BuyBox::class, ['purchasable' => $listing])
        ->assertSee('per pair')->assertSee('5 available')->assertSee('Quantity');

    $box->set('quantity', 2)->call('add')->assertDispatched('cart-updated')->assertSee('2 already in your cart');
    expect($this->carts->count($this->buyer))->toBe(2);

    $box->set('quantity', 9)->call('add')->assertSee('Only 5 available');
    expect($this->carts->count($this->buyer))->toBe(2);

    $box->set('quantity', 3)->call('add')->assertSee('5 already in your cart');

    $soldOut = Listing::factory()->soldOut()->create(['quantity' => 0]);
    Livewire::actingAs($this->buyer)->test(BuyBox::class, ['purchasable' => $soldOut])->assertSee('Sold out')->assertDontSee('Add to cart');

    $draft = Listing::factory()->draft()->create();
    Livewire::actingAs($this->buyer)->test(BuyBox::class, ['purchasable' => $draft])->assertSee('not for sale right now');
});

it('treats a listing quantity below one as one', function () {
    $listing = sellableListing();

    Livewire::actingAs($this->buyer)->test(BuyBox::class, ['purchasable' => $listing])->set('quantity', -4)->call('add');

    expect($this->carts->count($this->buyer))->toBe(1);
});

it('sells SDLabs\' new driver through the store listing box, with its label', function () {
    $driver = App\Models\Driver::factory()->create();
    $listing = storeListing(['price_cents' => 7000, 'quantity' => 9], $driver);

    Livewire::actingAs($this->buyer)->test(BuyBox::class, ['purchasable' => $listing, 'label' => 'New from SDLabs'])
        ->assertSee('New from SDLabs')->assertSee('$70.00')->assertSee('9 available')->assertSee('Add to cart')
        ->call('add')->assertSee('was added to your cart');

    expect($this->carts->count($this->buyer))->toBe(1);
});

// --- header cart icon -------------------------------------------------------------------------

it('shows the number of units in the cart in the header and refreshes it on cart-updated', function () {
    $icon = Livewire::actingAs($this->buyer)->test(CartIcon::class)->assertSet('count', 0)->assertSeeHtml('hidden');

    fillCart($this->buyer, [sellableListing(), [sellableListing(), 3]]);

    $icon->dispatch('cart-updated')->assertSet('count', 4)->assertSee('4');
});

it('renders the cart icon and the Marketplace link in the header for signed-in users only', function () {
    $this->get('/marketplace')->assertOk()->assertSee('Marketplace')->assertDontSee('data-cart-count', false);

    $this->actingAs($this->buyer)->get('/marketplace')->assertOk()->assertSee('data-cart-count', false)->assertSee(route('cart'), false);
});

it('shows the stock and a shipping summary on the buy box of a physical listing, but not of a design', function () {
    $listing = sellableListing(attributes: ['quantity' => 12, 'ships_from_country' => 'DE', 'shipping_domestic_cents' => 899, 'shipping_international_cents' => null, 'handling_days' => 1]);

    Livewire::actingAs($this->buyer)->test(BuyBox::class, ['purchasable' => $listing, 'label' => 'Sold by SDLabs'])
        ->assertSee('Sold by SDLabs')
        ->assertSee('12 available')
        ->assertSeeHtml('data-buy-box-shipping')
        ->assertSee('Ships from Germany within 1 day')
        ->assertSee('$8.99 in-country')->assertSee('not available international');

    Livewire::actingAs($this->buyer)->test(BuyBox::class, ['purchasable' => paidDesign(onboardedSeller(), 1500)])
        ->assertDontSeeHtml('data-buy-box-shipping');
});
