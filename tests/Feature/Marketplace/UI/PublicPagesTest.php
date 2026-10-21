<?php

use App\Enums\ListingStatus;
use App\Models\Design;
use App\Models\Driver;
use App\Models\Listing;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('private');
});

// --- /marketplace ---------------------------------------------------------------------------------

it('lists what is for sale and hides drafts, paused and sold out listings', function () {
    sellableListing(attributes: ['title' => 'Alpha Woofer']);
    Listing::factory()->draft()->create(['title' => 'Hidden Draft']);
    Listing::factory()->paused()->create(['title' => 'Paused Thing']);
    Listing::factory()->soldOut()->create(['title' => 'Gone Thing', 'quantity' => 0]);

    $this->get(route('marketplace'))
        ->assertOk()
        ->assertSee('Alpha Woofer')
        ->assertDontSee('Hidden Draft')
        ->assertDontSee('Paused Thing')
        ->assertDontSee('Gone Thing');
});

it('shows condition, price, shop name and the pair badge on the listing cards', function () {
    $seller = onboardedSeller();
    $seller->sellerProfile->update(['shop_name' => 'Pair Shop']);
    sellableListing($seller, 6400, ['title' => 'Matched Woofers', 'units_per_lot' => 2]);

    $this->get(route('marketplace'))
        ->assertOk()
        ->assertSee('Matched Woofers')
        ->assertSee('$64.00')
        ->assertSee('Pair Shop')
        ->assertSee('per pair')
        ->assertSee('New');
});

it('filters by condition, driver type, price range and text', function () {
    $tweeter = Driver::factory()->create(['category' => 'Tweeter', 'brand' => 'Seas', 'model' => 'Millennium']);
    $woofer = Driver::factory()->create(['category' => 'Woofer']);
    sellableListing(attributes: ['title' => 'Fresh Tweeter', 'driver_id' => $tweeter->id, 'price_cents' => 12000]);
    Listing::factory()->used()->forSeller(onboardedSeller())->create(['title' => 'Old Woofer', 'driver_id' => $woofer->id, 'price_cents' => 3000]);

    $this->get('/marketplace?condition=new')->assertSee('Fresh Tweeter')->assertDontSee('Old Woofer');
    $this->get('/marketplace?condition=used')->assertSee('Old Woofer')->assertDontSee('Fresh Tweeter');
    $this->get('/marketplace?category=Tweeter')->assertSee('Fresh Tweeter')->assertDontSee('Old Woofer');
    $this->get('/marketplace?driver='.$woofer->id)->assertSee('Old Woofer')->assertDontSee('Fresh Tweeter');
    $this->get('/marketplace?min_price=100&max_price=150')->assertSee('Fresh Tweeter')->assertDontSee('Old Woofer');
    $this->get('/marketplace?q=Millennium')->assertSee('Fresh Tweeter')->assertDontSee('Old Woofer');
    $this->get('/marketplace?q=nothing-matches')->assertSee('Nothing here yet');
});

it('sorts by price and by newest', function () {
    // the demo data is in the test database too: a unique search term keeps the page to these three
    sellableListing(attributes: ['title' => 'Sortprobe Cheap', 'price_cents' => 1000, 'created_at' => now()->subDay()]);
    sellableListing(attributes: ['title' => 'Sortprobe Mid', 'price_cents' => 5000, 'created_at' => now()->subHours(2)]);
    sellableListing(attributes: ['title' => 'Sortprobe Dear', 'price_cents' => 9000, 'created_at' => now()]);

    $this->get('/marketplace?q=Sortprobe&sort=price_asc')->assertSeeInOrder(['Sortprobe Cheap', 'Sortprobe Mid', 'Sortprobe Dear']);
    $this->get('/marketplace?q=Sortprobe&sort=price_desc')->assertSeeInOrder(['Sortprobe Dear', 'Sortprobe Mid', 'Sortprobe Cheap']);
    $this->get('/marketplace?q=Sortprobe')->assertSeeInOrder(['Sortprobe Dear', 'Sortprobe Mid', 'Sortprobe Cheap']);
});

it('only offers what ships to the default address country when asked', function () {
    sellableListing(attributes: ['title' => 'Domestic Only', 'ships_from_country' => 'US', 'shipping_international_cents' => null]);
    sellableListing(attributes: ['title' => 'Worldwide', 'ships_from_country' => 'US', 'shipping_international_cents' => 2500]);
    sellableListing(attributes: ['title' => 'German Local', 'ships_from_country' => 'DE', 'shipping_international_cents' => null]);

    $german = User::factory()->create();
    addressFor($german, 'DE');
    $american = User::factory()->create();
    addressFor($american, 'US');

    $this->actingAs($german)->get('/marketplace?ships_to_me=1')
        ->assertOk()
        ->assertSee('Ships to Germany')
        ->assertSee('German Local')->assertSee('Worldwide')->assertDontSee('Domestic Only');

    $this->actingAs($american)->get('/marketplace?ships_to_me=1')
        ->assertSee('Domestic Only')->assertSee('Worldwide')->assertDontSee('German Local');

    // without the toggle everything is listed; guests have no toggle at all
    $this->actingAs($german)->get('/marketplace')->assertSee('Domestic Only');
    auth()->logout();
    $this->get('/marketplace?ships_to_me=1')->assertOk()->assertSee('Domestic Only')->assertDontSee('Ships to ');
});

it('paginates twelve listings per page and keeps the filters in the page links', function () {
    foreach (range(1, 13) as $i) {
        sellableListing(attributes: ['title' => 'Pagerun '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'price_cents' => 1000 + $i]);
    }

    $this->get('/marketplace?q=Pagerun&sort=price_asc')
        ->assertOk()
        ->assertSee('13 listings')
        ->assertSee('Pagerun 01')->assertSee('Pagerun 12')->assertDontSee('Pagerun 13')
        ->assertSee('page=2', false)->assertSee('sort=price_asc', false);

    $this->get('/marketplace?q=Pagerun&sort=price_asc&page=2')->assertSee('Pagerun 13')->assertDontSee('Pagerun 01');
});

// --- /marketplace/listing/{uuid} ------------------------------------------------------------------

it('shows a listing with its facts, shipping table, seller box and a login prompt for guests', function () {
    $seller = onboardedSeller();
    $seller->sellerProfile->update(['shop_name' => 'Dave Audio', 'slug' => 'dave-audio', 'country' => 'CA']);
    $listing = sellableListing($seller, 8800, [
        'title' => 'Peerless Tweeter', 'description' => '<p>Mint condition with box.</p>', 'quantity' => 4, 'handling_days' => 5,
        'shipping_domestic_cents' => 750, 'shipping_international_cents' => 2250, 'additional_item_shipping_cents' => 150,
        'returns_accepted' => false, 'photos' => [],
    ]);

    $this->get(route('listing', ['uuid' => $listing->uuid]))
        ->assertOk()
        ->assertSee('Peerless Tweeter')
        ->assertSee('$88.00')
        ->assertSee('Mint condition with box.')
        ->assertSee('4 available')
        ->assertSee('Log in to buy')
        ->assertSee('Domestic (US)')->assertSee('$7.50')
        ->assertSee('International')->assertSee('$22.50')
        ->assertSee('Each additional item')->assertSee('+ $1.50')
        ->assertSee('5 days')
        ->assertSee('does not accept returns')
        ->assertSee('Dave Audio')
        ->assertSee(route('seller', ['slug' => 'dave-audio']), false)
        ->assertSee('Contact seller')
        ->assertSee(route('login'), false)
        ->assertDontSee('/chatify/'.$seller->id, false);
});

it('links "Contact seller" to the Chatify conversation for signed-in buyers, but not for the seller', function () {
    $listing = sellableListing();
    $buyer = User::factory()->create();

    $this->actingAs($buyer)->get(route('listing', ['uuid' => $listing->uuid]))
        ->assertOk()->assertSee('/chatify/'.$listing->seller_id, false)->assertSee('Add to cart');

    $this->actingAs($listing->seller)->get(route('listing', ['uuid' => $listing->uuid]))
        ->assertOk()->assertDontSee('Contact seller')->assertSee('your own listing');
});

it('shows the pair wording and the shipping quote for the buyers country', function () {
    $listing = sellableListing(attributes: ['units_per_lot' => 2, 'ships_from_country' => 'US', 'shipping_international_cents' => null, 'shipping_domestic_cents' => 1200]);
    $us = User::factory()->create();
    addressFor($us, 'US');
    $de = User::factory()->create();
    addressFor($de, 'DE');

    $this->actingAs($us)->get(route('listing', ['uuid' => $listing->uuid]))
        ->assertSee('Sold as a pair')->assertSee('per pair')->assertSee('To your address in United States')->assertSee('$12.00');

    $this->actingAs($de)->get(route('listing', ['uuid' => $listing->uuid]))
        ->assertSee('does not ship to Germany');
});

it('renders the description through the sanitising renderer', function () {
    $listing = sellableListing(attributes: ['description' => '<p onclick="steal()">Clean text</p><script>alert("xss")</script><iframe src="https://evil.test"></iframe>']);

    $html = $this->get(route('listing', ['uuid' => $listing->uuid]))->assertOk()->assertSee('Clean text')->getContent();

    expect($html)->not->toContain('alert("xss")')->not->toContain('evil.test')->not->toContain('steal()');
});

it('turns a plain-text description into paragraphs', function () {
    $listing = sellableListing(attributes: ['description' => "First paragraph.\n\nSecond one & more."]);

    $this->get(route('listing', ['uuid' => $listing->uuid]))
        ->assertOk()->assertSee('<p>First paragraph.</p>', false)->assertSee('<p>Second one &amp; more.</p>', false);
});

it('shows the linked catalogue driver with its specs, only while that driver is published', function () {
    $driver = Driver::factory()->create(['brand' => 'Scan-Speak', 'model' => 'D2608', 'factory_specs' => ['Fs' => '650', 'Qts' => '0.9']]);
    $listing = sellableListing(attributes: ['driver_id' => $driver->id]);

    $this->get(route('listing', ['uuid' => $listing->uuid]))
        ->assertSee('About this driver')->assertSee('Scan-Speak D2608')->assertSee('Fs')->assertSee('650')
        ->assertSee(route('driver', ['id' => $driver->id]), false);

    $driver->update(['active' => false]);
    $this->get(route('listing', ['uuid' => $listing->uuid]))->assertDontSee('About this driver');
});

it('only shows unpublished listings to their seller and admins', function () {
    $draft = Listing::factory()->draft()->create();
    $removed = Listing::factory()->create(['status' => ListingStatus::REMOVED]);

    $this->get(route('listing', ['uuid' => $draft->uuid]))->assertNotFound();
    $this->actingAs(User::factory()->create())->get(route('listing', ['uuid' => $draft->uuid]))->assertNotFound();
    $this->actingAs($draft->seller)->get(route('listing', ['uuid' => $draft->uuid]))->assertOk()->assertSee('not see it');
    $this->actingAs(adminUser())->get(route('listing', ['uuid' => $draft->uuid]))->assertOk();
    $this->actingAs(User::factory()->create())->get(route('listing', ['uuid' => $removed->uuid]))->assertNotFound();
    $this->get('/marketplace/listing/'.fake()->uuid())->assertNotFound();
});

it('shows sold out listings as sold out instead of offering them', function () {
    $listing = Listing::factory()->soldOut()->create(['quantity' => 0]);

    $this->get(route('listing', ['uuid' => $listing->uuid]))->assertOk()->assertSee('Sold out');
});

// --- /sellers/{slug} -----------------------------------------------------------------------------

it('shows a shop page with the bio, country, listings, designs and paid data packs', function () {
    $seller = onboardedSeller();
    $seller->sellerProfile->update(['shop_name' => 'Hale Shop', 'slug' => 'hale-shop', 'bio' => 'We build and sell speakers.', 'country' => 'GB']);
    sellableListing($seller, attributes: ['title' => 'Shop Listing']);
    Listing::factory()->forSeller($seller)->draft()->create(['title' => 'Draft Listing']);
    Design::factory()->for($seller, 'designer')->create(['name' => 'Shop Design']);
    Design::factory()->for($seller, 'designer')->draft()->create(['name' => 'Secret Design']);
    paidDriverPack($seller, 700)->update(['brand' => 'Hale', 'model' => 'PaidPack']);
    Driver::factory()->for($seller, 'creator')->free()->create(['brand' => 'Hale', 'model' => 'FreePack']);

    $this->get(route('seller', ['slug' => 'hale-shop']))
        ->assertOk()
        ->assertSee('Hale Shop')
        ->assertSee('We build and sell speakers.')
        ->assertSee('United Kingdom')
        ->assertSee('Shop Listing')->assertDontSee('Draft Listing')
        ->assertSee('Shop Design')->assertDontSee('Secret Design')
        ->assertSee('Hale PaidPack')->assertDontSee('Hale FreePack')
        ->assertSee('$7.00');

    $this->get(route('seller', ['slug' => 'no-such-shop']))->assertNotFound();
});

// --- design and driver pages -----------------------------------------------------------------------

it('puts the buy box on the design page and hides unpublished designs from everybody but the designer', function () {
    $design = paidDesign(onboardedSeller(), 2500);
    $draft = Design::factory()->draft()->create();

    $this->get('/designs/design/'.$design->id)->assertOk()->assertSee('data-buy-box="login"', false)->assertSee('$25.00');
    $this->actingAs(User::factory()->create())->get('/designs/design/'.$design->id)->assertSee('data-buy-box="add"', false)->assertSee('Add to cart');

    $this->get('/designs/design/'.$draft->id)->assertNotFound();
    $this->actingAs($draft->designer)->get('/designs/design/'.$draft->id)->assertOk();
});

it('shows the manufacturer price and link next to the data pack buy box on the driver page', function () {
    $driver = Driver::factory()->for(onboardedSeller(), 'creator')->paid(500)->withSnapshot()
        ->create(['msrp_cents' => 21000, 'link' => 'https://maker.example/driver', 'brand' => 'Maker', 'model' => 'X1']);
    sellableListing(attributes: ['driver_id' => $driver->id, 'price_cents' => 9900]);
    $buyer = User::factory()->create();

    $this->actingAs($buyer)->get('/drivers/driver/'.$driver->id)
        ->assertOk()
        ->assertSee('data-driver-msrp', false)->assertSee('$210.00')->assertSee('MSRP')
        ->assertSee('https://maker.example/driver', false)->assertSee('Buy from the manufacturer')
        ->assertSee('1 listing in the marketplace')->assertSee('$99.00')
        ->assertSee(route('marketplace', ['driver' => $driver->id]), false)
        ->assertSee('Measurement data pack')->assertSee('$5.00')->assertSee('Add to cart');
});

it('does not link a driver page to a javascript: manufacturer link and skips the data pack box when none is sold', function () {
    $driver = Driver::factory()->free()->create(['link' => 'javascript:alert(1)', 'msrp_cents' => null]);

    $this->get('/drivers/driver/'.$driver->id)->assertOk()
        ->assertDontSee('javascript:alert', false)->assertDontSee('data-driver-msrp', false)->assertDontSee('data-buy-box', false);
});

it('hides unpublished drivers from the public', function () {
    $draft = Driver::factory()->draft()->create();

    $this->get('/drivers/driver/'.$draft->id)->assertNotFound();
    $this->actingAs($draft->creator)->get('/drivers/driver/'.$draft->id)->assertOk();
});
