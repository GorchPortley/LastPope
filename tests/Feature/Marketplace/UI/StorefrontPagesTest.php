<?php

use App\Enums\ListingStatus;
use App\Models\Design;
use App\Models\Driver;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
| R1.3: the store / marketplace split on the public pages. SDLabs sells new drivers from the driver page (buy box,
| sold out, nothing), members sell kits, completed speakers and used gear in the marketplace, and the design and
| listing pages link the two sides together.
*/

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('private');
});

// --- store listings have no page of their own -----------------------------------------------------------

it('redirects a store listing to its driver page with a permanent redirect', function () {
    $store = storeListing();

    $this->get(route('listing', ['uuid' => $store->uuid]))
        ->assertStatus(301)
        ->assertRedirect(route('driver', ['id' => $store->driver_id]));
});

it('does not reveal an unpublished store listing, and still shows marketplace listings in place', function () {
    $draft = storeListing(['status' => ListingStatus::DRAFT]);
    $kit = sellableListing(attributes: ['title' => 'Stays on its page']);

    $this->get(route('listing', ['uuid' => $draft->uuid]))->assertNotFound();
    $this->get(route('listing', ['uuid' => $kit->uuid]))->assertOk()->assertSee('Stays on its page');
});

// --- the driver page: SDLabs store states -------------------------------------------------------------------

function pricedDriver(array $attributes = []): Driver
{
    return Driver::factory()->create($attributes + ['brand' => 'Maker', 'model' => 'Z9', 'msrp_cents' => 21000, 'link' => 'https://maker.example/z9']);
}

it('shows the "Sold by SDLabs" buy box when the store has the driver in stock', function () {
    $driver = pricedDriver();
    storeListing(['quantity' => 7, 'price_cents' => 15900, 'shipping_domestic_cents' => 899, 'handling_days' => 2], $driver);

    $this->get(route('driver', ['id' => $driver->id]))->assertOk()->assertSee('Log in to buy');

    $this->actingAs(User::factory()->create())->get(route('driver', ['id' => $driver->id]))
        ->assertOk()
        ->assertSee('data-driver-store', false)
        ->assertSee('Sold by SDLabs')
        ->assertSee('$159.00')
        ->assertSee('In stock, ships in 2 days')
        ->assertSee('data-buy-box-shipping', false)->assertSee('Ships from United States')->assertSee('$8.99 in-country')
        ->assertSee('Quantity')->assertSee('Add to cart')
        ->assertSee('Manufacturer list price: $210.00')
        ->assertDontSee('Out of stock at SDLabs')
        ->assertDontSee('Buy from the manufacturer');
});

it('says "Out of stock at SDLabs" when the store listing sold out, and keeps the manufacturer link', function () {
    $soldOut = pricedDriver();
    storeListing(['quantity' => 0, 'status' => ListingStatus::SOLD_OUT], $soldOut);
    $emptyShelf = pricedDriver(['model' => 'Z10']);
    storeListing(['quantity' => 0, 'status' => ListingStatus::ACTIVE], $emptyShelf);

    foreach ([$soldOut, $emptyShelf] as $driver) {
        $this->get(route('driver', ['id' => $driver->id]))
            ->assertOk()
            ->assertSee('data-driver-sold-out', false)
            ->assertSee('Out of stock at SDLabs')
            ->assertSee('data-driver-msrp', false)->assertSee('Buy from the manufacturer')
            ->assertDontSee('data-driver-store', false)->assertDontSee('Add to cart');
    }
});

it('falls back to the manufacturer price and link when SDLabs does not sell the driver', function () {
    $none = pricedDriver();
    $paused = pricedDriver(['model' => 'Z11']);
    storeListing(['status' => ListingStatus::PAUSED], $paused);

    foreach ([$none, $paused] as $driver) {
        $this->get(route('driver', ['id' => $driver->id]))
            ->assertOk()
            ->assertSee('data-driver-msrp', false)->assertSee('$210.00')->assertSee('Buy from the manufacturer')
            ->assertDontSee('data-driver-store', false)->assertDontSee('Out of stock at SDLabs')->assertDontSee('data-buy-box', false);
    }
});

it('points the driver page to the used ones on the marketplace', function () {
    $driver = pricedDriver();
    sellableListing(attributes: ['driver_id' => $driver->id, 'price_cents' => 9900]);
    Listing::factory()->used()->forSeller(onboardedSeller())->create(['driver_id' => $driver->id, 'price_cents' => 7500]);
    Listing::factory()->used()->forSeller(onboardedSeller())->draft()->create(['driver_id' => $driver->id]); // not for sale

    $this->get(route('driver', ['id' => $driver->id]))
        ->assertOk()
        ->assertSee('data-driver-listings', false)
        ->assertSee('Used ones on the marketplace (2)')
        ->assertSee('From $75.00')
        ->assertSee(e(route('marketplace', ['type' => 'used_gear', 'driver' => $driver->id])), false);

    $this->get(route('driver', ['id' => pricedDriver(['model' => 'Z12'])->id]))->assertDontSee('Used ones on the marketplace');
});

it('keeps the store channel and the marketplace channel apart on the driver page', function () {
    $driver = pricedDriver();
    storeListing([], $driver);

    // the store listing is not "a used one"
    $this->get(route('driver', ['id' => $driver->id]))->assertDontSee('Used ones on the marketplace');
});

// --- the drivers index ------------------------------------------------------------------------------------------

it('marks the drivers SDLabs has in stock on the driver cards, without a query per driver', function () {
    $stocked = pricedDriver(['brand' => 'Cardprobe', 'model' => 'IN1']);
    storeListing(['price_cents' => 8800, 'quantity' => 4], $stocked);
    $soldOut = pricedDriver(['brand' => 'Cardprobe', 'model' => 'OUT1']);
    storeListing(['quantity' => 0, 'status' => ListingStatus::SOLD_OUT], $soldOut);
    foreach (range(1, 6) as $i) {
        storeListing([], pricedDriver(['brand' => 'Cardprobe', 'model' => 'BULK'.$i]));
    }

    $listingQueries = 0;
    DB::listen(function ($query) use (&$listingQueries) {
        if (str_contains($query->sql, 'from "listings"') || str_contains($query->sql, 'from `listings`')) {
            $listingQueries++;
        }
    });

    $html = $this->get('/drivers')->assertOk()->getContent();

    expect(substr_count($html, 'data-driver-in-stock'))->toBeGreaterThanOrEqual(7)
        ->and($html)->toContain('In stock at SDLabs &middot; $88.00')
        ->and($listingQueries)->toBeLessThanOrEqual(2); // one eager load for the whole page, not one per card
});

it('shows no stock line for a driver the store does not carry', function () {
    pricedDriver(['brand' => 'Plainprobe', 'model' => 'P1']);

    $this->get('/drivers?brand=Plainprobe')->assertOk()->assertSee('P1')->assertDontSee('In stock at SDLabs');
});

// --- the marketplace index --------------------------------------------------------------------------------------------

it('filters the marketplace by type and shows the type tabs', function () {
    $seller = onboardedSeller();
    sellableListing($seller, attributes: ['title' => 'Typeprobe Kit']);
    Listing::factory()->completedSpeaker()->forSeller($seller)->create(['title' => 'Typeprobe Speaker']);
    Listing::factory()->used()->forSeller($seller)->create(['title' => 'Typeprobe Gear']);
    storeListing(['title' => 'Typeprobe Store Driver']);

    $this->get('/marketplace?q=Typeprobe')
        ->assertOk()
        ->assertSee('Kits, finished speakers and used gear from the community')
        ->assertSee('Typeprobe Kit')->assertSee('Typeprobe Speaker')->assertSee('Typeprobe Gear')
        ->assertDontSee('Typeprobe Store Driver')
        ->assertSee('role="tablist"', false)->assertSee('Completed speakers')->assertSee('Used gear');

    $this->get('/marketplace?q=Typeprobe&type=kit')->assertSee('Typeprobe Kit')->assertDontSee('Typeprobe Speaker')->assertDontSee('Typeprobe Gear');
    $this->get('/marketplace?q=Typeprobe&type=completed_speaker')->assertSee('Typeprobe Speaker')->assertDontSee('Typeprobe Kit');
    $this->get('/marketplace?q=Typeprobe&type=used_gear')->assertSee('Typeprobe Gear')->assertDontSee('Typeprobe Kit')->assertDontSee('Typeprobe Speaker');

    // the store category is not a marketplace type: it is ignored, and the store driver stays out
    $this->get('/marketplace?q=Typeprobe&type=driver')->assertSee('Typeprobe Kit')->assertDontSee('Typeprobe Store Driver');
    $this->get('/marketplace?q=Typeprobe&type=nonsense')->assertOk()->assertSee('Typeprobe Gear');
});

it('marks the active tab and keeps the other filters in the tab links', function () {
    $html = $this->get('/marketplace?type=used_gear&condition=used')->assertOk()->getContent();

    expect($html)->toMatch('/aria-selected="true"[^>]*>\s*Used gear/')
        ->and($html)->toContain('type=kit')
        ->and($html)->toContain('condition=used');
});

it('keeps the chosen type when the filter form is applied, and filters by driver', function () {
    $driver = pricedDriver(['brand' => 'Driverprobe', 'model' => 'D1']);
    $other = pricedDriver(['brand' => 'Driverprobe', 'model' => 'D2']);
    Listing::factory()->used()->forSeller(onboardedSeller())->create(['title' => 'Used D1', 'driver_id' => $driver->id]);
    Listing::factory()->used()->forSeller(onboardedSeller())->create(['title' => 'Used D2', 'driver_id' => $other->id]);

    $this->get('/marketplace?type=used_gear&driver='.$driver->id)
        ->assertOk()
        ->assertSee('Used D1')->assertDontSee('Used D2')
        ->assertSee('Driver: Driverprobe D1')
        ->assertSee('<input type="hidden" name="type" value="used_gear">', false)
        ->assertSee('<input type="hidden" name="driver" value="'.$driver->id.'">', false);
});

it('sorts with the field and direction selects of the other index pages', function () {
    sellableListing(attributes: ['title' => 'Dirprobe Cheap', 'price_cents' => 1000]);
    sellableListing(attributes: ['title' => 'Dirprobe Dear', 'price_cents' => 9000]);

    $this->get('/marketplace?q=Dirprobe&sort=price&direction=asc')->assertSeeInOrder(['Dirprobe Cheap', 'Dirprobe Dear']);
    $this->get('/marketplace?q=Dirprobe&sort=price&direction=desc')->assertSeeInOrder(['Dirprobe Dear', 'Dirprobe Cheap']);
});

// --- the listing page: where it comes from -----------------------------------------------------------------------------

it('shows the design a kit or a completed speaker is built from as a design card', function () {
    $design = Design::factory()->create(['name' => 'Builtfrom Tower']);
    $kit = sellableListing(attributes: ['design_id' => $design->id]);

    $this->get(route('listing', ['uuid' => $kit->uuid]))
        ->assertOk()
        ->assertSee('data-built-from', false)
        ->assertSee('Built from this design')
        ->assertSee('Builtfrom Tower')
        ->assertSee('/designs/design/'.$design->id, false);

    $speaker = Listing::factory()->completedSpeaker()->forSeller(onboardedSeller())->forDesign($design)->create();
    $this->get(route('listing', ['uuid' => $speaker->uuid]))->assertSee('data-built-from', false);
});

it('leaves the built-from card out without a published design', function () {
    $plain = sellableListing();
    $draftDesign = Design::factory()->draft()->create();
    $linked = sellableListing(attributes: ['design_id' => $draftDesign->id]);

    $this->get(route('listing', ['uuid' => $plain->uuid]))->assertDontSee('data-built-from', false);
    $this->get(route('listing', ['uuid' => $linked->uuid]))->assertDontSee('data-built-from', false);
});

it('shows the driver card and key specs on used gear that is a catalogue driver', function () {
    $driver = pricedDriver(['brand' => 'Keyspec', 'model' => 'K1', 'power' => 250, 'impedance' => 8, 'factory_specs' => ['Fs' => '33', 'Qts' => '0.41']]);
    storeListing(['price_cents' => 18800], $driver);
    $gear = Listing::factory()->used()->forSeller(onboardedSeller())->create(['driver_id' => $driver->id]);

    $this->get(route('listing', ['uuid' => $gear->uuid]))
        ->assertOk()
        ->assertSee('data-about-driver', false)
        ->assertSee('Keyspec K1')
        ->assertSee('Key specs')->assertSee('Power Handling')->assertSee('250W')->assertSee('8Ω')
        ->assertSee('0.41')
        ->assertSee('In stock at SDLabs &middot; $188.00', false) // the driver card tells where to buy it new
        ->assertSee(route('marketplace', ['driver' => $driver->id]), false);
});

// --- the design page: kits & builds for sale -----------------------------------------------------------------------------

it('lists the kits and builds for sale on the design page', function () {
    $design = Design::factory()->create();
    $seller = onboardedSeller();
    sellableListing($seller, 20000, ['title' => 'Buildprobe Kit', 'design_id' => $design->id]);
    Listing::factory()->completedSpeaker()->forSeller($seller)->forDesign($design)->create(['title' => 'Buildprobe Pair']);
    Listing::factory()->forSeller($seller)->forDesign($design)->draft()->create(['title' => 'Buildprobe Draft']);
    Listing::factory()->forSeller($seller)->forDesign($design)->soldOut()->create(['title' => 'Buildprobe Gone', 'quantity' => 0]);
    sellableListing($seller, attributes: ['title' => 'Buildprobe Other Design', 'design_id' => Design::factory()->create()->id]);

    $this->get('/designs/design/'.$design->id)
        ->assertOk()
        ->assertSee('data-design-builds', false)
        ->assertSee('Kits &amp; builds for sale', false)
        ->assertSee('Buildprobe Kit')->assertSee('Buildprobe Pair')
        ->assertDontSee('Buildprobe Draft')->assertDontSee('Buildprobe Gone')->assertDontSee('Buildprobe Other Design');
});

it('hides the builds section of a design without listings', function () {
    $design = Design::factory()->create();

    $this->get('/designs/design/'.$design->id)->assertOk()->assertDontSee('data-design-builds', false)->assertDontSee('Kits &amp; builds for sale', false);
});

// --- the shared components --------------------------------------------------------------------------------------------------

it('draws listing cards like the design cards: view button, specs panel, price and shop', function () {
    $seller = onboardedSeller();
    $seller->sellerProfile->update(['shop_name' => 'Cardprobe Shop']);
    $listing = sellableListing($seller, 6400, ['title' => 'Cardprobe Pair', 'units_per_lot' => 2, 'photos' => []]);
    $listing->load(['seller.sellerProfile', 'driver', 'design']);

    $html = Blade::render('<x-marketing.listing-card-container :listings="$listings" cols="grid-cols-1" :padded="false" />', ['listings' => collect([$listing])]);

    expect($html)
        ->toContain('data-listing-card="'.$listing->uuid.'"')
        ->toContain('Cardprobe Pair')
        ->toContain('By: Cardprobe Shop')
        ->toContain('$64.00')->toContain('per pair')
        ->toContain('showSpecs')->toContain('Specifications')
        ->toContain(route('listing', ['uuid' => $listing->uuid]))
        ->toContain('grid-cols-1')
        ->not->toContain('xl:grid-cols-4');
});

it('draws the breadcrumb, tabs and spec grid of the owner pages', function () {
    $crumbs = Blade::render('<x-marketplace.breadcrumb :items="[[\'Marketplace\', \'/marketplace\'], [\'Cart\', null]]" />');
    $tabs = Blade::render('<x-marketplace.tabs :items="[\'a\' => [\'One\', \'/one\'], \'b\' => [\'Two\', \'/two\']]" active="b" />');
    $specs = Blade::render('<x-marketplace.specs :items="[\'Power\' => \'200W\', \'Empty\' => null]" />');

    expect($crumbs)->toContain('aria-label="Breadcrumb"')->toContain('href="/marketplace"')->toContain('<hr')
        ->and($tabs)->toContain('role="tablist"')->toContain('aria-selected="true"')->toContain('font-bold')
        ->and($specs)->toContain('Specifications')->toContain('200W')->not->toContain('Empty');
});

// --- the model helpers --------------------------------------------------------------------------------------------------------

it('knows the active and the latest store listing of a driver', function () {
    $driver = pricedDriver();
    expect($driver->storeListing())->toBeNull()->and($driver->latestStoreListing())->toBeNull();

    $old = storeListing(['status' => ListingStatus::SOLD_OUT, 'quantity' => 0], $driver);
    expect($driver->storeListing())->toBeNull()->and($driver->latestStoreListing()?->is($old))->toBeTrue();

    $current = storeListing([], $driver);
    expect($driver->storeListing()?->is($current))->toBeTrue()->and($driver->latestStoreListing()?->is($current))->toBeTrue();

    // paused or removed store listings are off sale, not out of stock
    $current->update(['status' => ListingStatus::PAUSED]);
    $old->update(['status' => ListingStatus::REMOVED]);
    expect($driver->storeListing())->toBeNull()->and($driver->latestStoreListing())->toBeNull();
});

it('eager-loads the active store listing of many drivers at once', function () {
    $a = pricedDriver();
    $b = pricedDriver(['model' => 'Z20']);
    $c = pricedDriver(['model' => 'Z21']);
    $stored = storeListing([], $a);
    storeListing(['status' => ListingStatus::DRAFT], $b);

    $drivers = Driver::with('activeStoreListing')->whereKey([$a->id, $b->id, $c->id])->get()->keyBy('id');

    DB::enableQueryLog();
    expect($drivers[$a->id]->storeListing()?->is($stored))->toBeTrue()
        ->and($drivers[$b->id]->storeListing())->toBeNull()
        ->and($drivers[$c->id]->storeListing())->toBeNull()
        ->and(DB::getQueryLog())->toBe([]); // the relation answered, no query per driver
    DB::disableQueryLog();
});
