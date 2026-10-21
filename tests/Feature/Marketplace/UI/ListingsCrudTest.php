<?php

use App\Enums\ListingCategory;
use App\Enums\ListingChannel;
use App\Enums\ListingCondition;
use App\Enums\ListingStatus;
use App\Models\Driver;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

function listingFormData(array $overrides = []): array
{
    return $overrides + [
        'published' => true,
        'title' => 'Dayton RS225 woofer',
        'category' => ListingCategory::USED_GEAR->value,
        'condition' => ListingCondition::USED_GOOD->value,
        'photos' => [UploadedFile::fake()->image('front.jpg')],
        'price_cents' => '45.50',
        'quantity' => 2,
        'units_per_lot' => 2,
        'ships_from_country' => 'DE',
        'handling_days' => 4,
        'shipping_domestic_cents' => '8',
        'shipping_international_cents' => '19.90',
        'additional_item_shipping_cents' => '2.5',
        'returns_accepted' => false,
    ];
}

function listingsPage(object $test, User $user): Livewire\Features\SupportTesting\Testable
{
    return voltPage($test, $user, '/dashboard/listings', 'dashboard.listings');
}

it('creates a listing with money typed in dollars, photos on the public disk and a shop page for the seller', function () {
    $seller = onboardedSeller();
    $driver = Driver::factory()->create();

    listingsPage($this, $seller)
        ->callTableAction('create', data: listingFormData(['driver_id' => $driver->id, 'description' => '<p>Barely used.</p>']))
        ->assertHasNoTableActionErrors();

    $listing = Listing::where('seller_id', $seller->id)->where('title', 'Dayton RS225 woofer')->firstOrFail();

    expect($listing->price_cents)->toBe(4550)
        ->and($listing->quantity)->toBe(2)
        ->and($listing->units_per_lot)->toBe(2)
        ->and($listing->condition)->toBe(ListingCondition::USED_GOOD)
        ->and($listing->ships_from_country)->toBe('DE')
        ->and($listing->shipping_domestic_cents)->toBe(800)
        ->and($listing->shipping_international_cents)->toBe(1990)
        ->and($listing->additional_item_shipping_cents)->toBe(250)
        ->and($listing->handling_days)->toBe(4)
        ->and($listing->driver_id)->toBe($driver->id)
        ->and($listing->description)->toContain('Barely used.')
        ->and($listing->status)->toBe(ListingStatus::ACTIVE)
        ->and($listing->uuid)->toMatch('/^[0-9a-f-]{36}$/')
        ->and($listing->photos)->toHaveCount(1)
        ->and($listing->photos[0])->toStartWith('listings/'.$listing->uuid.'/photos/');

    Storage::disk('public')->assertExists($listing->photos[0]);
});

it('leaves the international shipping empty (domestic only) when it is not filled in', function () {
    $seller = onboardedSeller();

    listingsPage($this, $seller)
        ->callTableAction('create', data: listingFormData(['title' => 'Local only', 'shipping_international_cents' => '']))
        ->assertHasNoTableActionErrors();

    expect(Listing::where('title', 'Local only')->first()->shipping_international_cents)->toBeNull();
});

it('saves a listing as a draft with the reasons when it cannot be sold yet', function () {
    $unpaid = User::factory()->create(); // no payout account

    listingsPage($this, $unpaid)
        ->callTableAction('create', data: listingFormData(['title' => 'Cannot publish']))
        ->assertHasNoTableActionErrors()
        ->assertNotified('Saved as a draft');

    $listing = Listing::where('title', 'Cannot publish')->firstOrFail();
    expect($listing->status)->toBe(ListingStatus::DRAFT);

    // a free listing is not publishable either, whoever the seller is
    listingsPage($this, onboardedSeller())
        ->callTableAction('create', data: listingFormData(['title' => 'No price', 'price_cents' => '0']))
        ->assertNotified('Saved as a draft');
    expect(Listing::where('title', 'No price')->first()->status)->toBe(ListingStatus::DRAFT);

    // as a draft on request it is saved without any warning
    listingsPage($this, onboardedSeller())
        ->callTableAction('create', data: listingFormData(['title' => 'Draft on purpose', 'published' => false]))
        ->assertNotNotified('Saved as a draft');
    expect(Listing::where('title', 'Draft on purpose')->first()->status)->toBe(ListingStatus::DRAFT);
});

it('lets admins, who sell as the platform, publish without Stripe', function () {
    $admin = adminUser();

    listingsPage($this, $admin)->callTableAction('create', data: listingFormData(['title' => 'Platform listing']));

    expect(Listing::where('title', 'Platform listing')->first()->status)->toBe(ListingStatus::ACTIVE);
    $this->actingAs($admin)->get('/dashboard/listings')->assertDontSee('Connect Stripe to publish paid listings');
});

it('explains that Stripe has to be connected before paid listings can go live', function () {
    $this->actingAs(User::factory()->create())->get('/dashboard/listings')
        ->assertOk()->assertSee('Connect Stripe to publish paid listings')->assertSee(route('selling.dashboard'), false);

    $this->actingAs(onboardedSeller())->get('/dashboard/listings')->assertOk()->assertDontSee('Connect Stripe to publish paid listings');
});

it('validates the form: photos are required images, at most 8, and the numbers make sense', function () {
    $page = listingsPage($this, onboardedSeller());

    $page->callTableAction('create', data: listingFormData(['photos' => [], 'title' => '']))
        ->assertHasTableActionErrors(['photos' => 'required', 'title' => 'required']);

    $page->callTableAction('create', data: listingFormData(['photos' => [UploadedFile::fake()->create('manual.pdf', 20, 'application/pdf')]]))
        ->assertHasTableActionErrors(['photos']);

    $page->callTableAction('create', data: listingFormData(['photos' => array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg"), range(1, 9))]))
        ->assertHasTableActionErrors(['photos']);

    $page->callTableAction('create', data: listingFormData(['quantity' => -1, 'units_per_lot' => 0, 'ships_from_country' => 'ZZ', 'condition' => 'broken']))
        ->assertHasTableActionErrors(['quantity', 'units_per_lot', 'ships_from_country', 'condition']);

    expect(Listing::count())->toBe(Listing::withTrashed()->count());
});

it('shows only the sellers own listings', function () {
    $seller = onboardedSeller();
    $mine = sellableListing($seller, attributes: ['title' => 'My own thing']);
    $theirs = sellableListing(onboardedSeller(), attributes: ['title' => 'Their thing']);

    listingsPage($this, $seller)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);

    $this->actingAs($seller)->get('/dashboard/listings')->assertSee('My own thing')->assertDontSee('Their thing');
    expect($seller->can('update', $theirs))->toBeFalse()->and($seller->can('delete', $theirs))->toBeFalse()->and($seller->can('update', $mine))->toBeTrue();
});

it('edits a listing and keeps its uuid, photo folder and owner', function () {
    $seller = onboardedSeller();
    $listing = sellableListing($seller, attributes: ['title' => 'Before', 'photos' => ['listings/x/photos/a.jpg']]);
    Storage::disk('public')->put('listings/x/photos/a.jpg', 'img');
    $uuid = $listing->uuid;
    $other = User::factory()->create();

    listingsPage($this, $seller)
        ->callTableAction('edit', $listing, data: [
            'published' => true, 'title' => 'After', 'category' => 'kit', 'condition' => 'new', 'photos' => ['listings/x/photos/a.jpg'], 'price_cents' => '12.34', 'quantity' => 1,
            'units_per_lot' => 1, 'ships_from_country' => 'US', 'handling_days' => 2, 'shipping_domestic_cents' => '3', 'additional_item_shipping_cents' => '0',
            'uuid' => 'tampered', 'seller_id' => $other->id,
        ])
        ->assertHasNoTableActionErrors();

    $listing->refresh();
    expect($listing->title)->toBe('After')->and($listing->price_cents)->toBe(1234)->and($listing->uuid)->toBe($uuid)->and($listing->seller_id)->toBe($seller->id);
});

it('pauses and publishes a listing with the toggle, respecting what publishBlockers() says', function () {
    $seller = onboardedSeller();
    $live = sellableListing($seller);
    $noStock = Listing::factory()->forSeller($seller)->draft()->create(['quantity' => 0]);

    $page = listingsPage($this, $seller);

    $page->call('updateTableColumnState', 'published', $live->getKey(), false);
    expect($live->fresh()->status)->toBe(ListingStatus::PAUSED);

    $page->call('updateTableColumnState', 'published', $live->getKey(), true);
    expect($live->fresh()->status)->toBe(ListingStatus::ACTIVE);

    $page->call('updateTableColumnState', 'published', $noStock->getKey(), true)->assertNotified('Saved as a draft');
    expect($noStock->fresh()->status)->toBe(ListingStatus::DRAFT);

    $noStock->update(['quantity' => 3]);
    $page->call('updateTableColumnState', 'published', $noStock->getKey(), true);
    expect($noStock->fresh()->status)->toBe(ListingStatus::ACTIVE);
});

it('refuses the toggle for a seller without payouts', function () {
    $seller = User::factory()->create();
    $listing = Listing::factory()->forSeller($seller)->draft()->create();

    listingsPage($this, $seller)->call('updateTableColumnState', 'published', $listing->getKey(), true)->assertNotified('Saved as a draft');

    expect($listing->fresh()->status)->toBe(ListingStatus::DRAFT);
});

it('pauses an active listing that loses its stock instead of leaving it for sale', function () {
    $seller = onboardedSeller();
    $listing = sellableListing($seller);

    listingsPage($this, $seller)
        ->callTableAction('edit', $listing, data: [
            'published' => true, 'title' => $listing->title, 'category' => 'kit', 'condition' => 'new', 'photos' => $listing->photos ?: [UploadedFile::fake()->image('p.jpg')], 'price_cents' => '10', 'quantity' => 0,
            'units_per_lot' => 1, 'ships_from_country' => 'US', 'handling_days' => 3, 'shipping_domestic_cents' => '0', 'additional_item_shipping_cents' => '0',
        ])
        ->assertNotified('Listing paused');

    expect($listing->fresh()->status)->toBe(ListingStatus::PAUSED);
});

it('keeps a sold out listing published and puts it back on sale when it is restocked', function () {
    $seller = onboardedSeller();
    $listing = Listing::factory()->forSeller($seller)->soldOut()->create(['quantity' => 0]);
    $data = fn (int $quantity) => [
        'published' => true, 'title' => $listing->title, 'category' => 'kit', 'condition' => 'new', 'photos' => [UploadedFile::fake()->image('p.jpg')], 'price_cents' => '10', 'quantity' => $quantity,
        'units_per_lot' => 1, 'ships_from_country' => 'US', 'handling_days' => 3, 'shipping_domestic_cents' => '0', 'additional_item_shipping_cents' => '0',
    ];

    $page = listingsPage($this, $seller);
    $page->callTableAction('edit', $listing, data: $data(0))->assertNotNotified('Listing paused');
    expect($listing->fresh()->status)->toBe(ListingStatus::SOLD_OUT);

    $page->callTableAction('edit', $listing, data: $data(4));
    expect($listing->fresh()->status)->toBe(ListingStatus::ACTIVE);
});

it('deletes a listing softly', function () {
    $seller = onboardedSeller();
    $listing = sellableListing($seller);

    listingsPage($this, $seller)->callTableAction('delete', $listing)->assertHasNoTableActionErrors();

    expect(Listing::find($listing->id))->toBeNull()->and(Listing::withTrashed()->find($listing->id))->not->toBeNull();
    $this->get(route('listing', ['uuid' => $listing->uuid]))->assertNotFound();
});

// --- channel and category (R1.1) ---------------------------------------------------------------------------

it('always creates marketplace listings with the chosen type, whatever the form is told', function () {
    $seller = onboardedSeller();
    $design = App\Models\Design::factory()->create();

    listingsPage($this, $seller)
        ->callTableAction('create', data: listingFormData([
            'title' => 'Harbor kit', 'category' => ListingCategory::KIT->value, 'condition' => 'new', 'design_id' => $design->id,
            'channel' => 'store', // not a field of the form: ignored
        ]))
        ->assertHasNoTableActionErrors();

    $listing = Listing::where('title', 'Harbor kit')->firstOrFail();
    expect($listing->channel)->toBe(ListingChannel::MARKETPLACE)
        ->and($listing->category)->toBe(ListingCategory::KIT)
        ->and($listing->design_id)->toBe($design->id)
        ->and($listing->status)->toBe(ListingStatus::ACTIVE);
});

it('offers kits, completed speakers and used gear, never the store category', function () {
    $seller = onboardedSeller();

    foreach (['kit', 'completed_speaker', 'used_gear'] as $category) {
        listingsPage($this, $seller)
            ->callTableAction('create', data: listingFormData(['title' => "Type {$category}", 'category' => $category, 'condition' => 'used_fair']))
            ->assertHasNoTableActionErrors();
        expect(Listing::where('title', "Type {$category}")->first()->category->value)->toBe($category);
    }

    listingsPage($this, $seller)
        ->callTableAction('create', data: listingFormData(['title' => 'Store attempt', 'category' => 'driver']))
        ->assertHasTableActionErrors(['category']);
    expect(Listing::where('title', 'Store attempt')->exists())->toBeFalse();
});

it('refuses used gear listed as new, but a new kit is fine', function () {
    $seller = onboardedSeller();

    listingsPage($this, $seller)
        ->callTableAction('create', data: listingFormData(['title' => 'Liar', 'category' => 'used_gear', 'condition' => 'new']))
        ->assertHasTableActionErrors(['condition']);
    expect(Listing::where('title', 'Liar')->exists())->toBeFalse();

    listingsPage($this, $seller)
        ->callTableAction('create', data: listingFormData(['title' => 'Honest kit', 'category' => 'kit', 'condition' => 'new']))
        ->assertHasNoTableActionErrors();
    expect(Listing::where('title', 'Honest kit')->exists())->toBeTrue();
});

it('keeps store listings out of the sellers own list, even for an admin', function () {
    $admin = adminUser();
    $store = storeListing(['title' => 'Store thing'], admin: $admin);
    $mine = Listing::factory()->forSeller($admin)->kit()->create(['title' => 'Admin kit']);

    listingsPage($this, $admin)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$store]);
});
