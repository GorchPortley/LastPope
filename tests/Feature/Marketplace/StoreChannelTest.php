<?php

use App\Enums\ListingCategory;
use App\Enums\ListingChannel;
use App\Enums\ListingCondition;
use App\Enums\ListingStatus;
use App\Models\Design;
use App\Models\Driver;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| R1.1: SDLabs sells new drivers through STORE listings (admins only, one active per driver, shown on the
| driver page); members sell kits, completed speakers and used gear through MARKETPLACE listings.
*/

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('private');
});

// --- enums and defaults ---------------------------------------------------------------------------

it('labels the channels and categories for the interface', function () {
    expect(ListingChannel::STORE->label())->toBe('Store')
        ->and(ListingChannel::MARKETPLACE->label())->toBe('Marketplace')
        ->and(ListingChannel::options())->toBe(['store' => 'Store', 'marketplace' => 'Marketplace'])
        ->and(ListingCategory::DRIVER->label())->toBe('Driver')
        ->and(ListingCategory::KIT->label())->toBe('Kit')
        ->and(ListingCategory::COMPLETED_SPEAKER->label())->toBe('Completed speaker')
        ->and(ListingCategory::USED_GEAR->label())->toBe('Used gear')
        ->and(ListingCategory::marketplaceOptions())->toHaveKeys(['kit', 'completed_speaker', 'used_gear'])->not->toHaveKey('driver')
        ->and(ListingCategory::DRIVER->channel())->toBe(ListingChannel::STORE)
        ->and(ListingCategory::KIT->channel())->toBe(ListingChannel::MARKETPLACE);

    foreach (ListingCategory::cases() as $category) {
        expect($category->label())->not->toBe('')->and($category->pluralLabel())->not->toBe('');
    }
});

it('defaults to the marketplace and derives a category from the condition when none is given', function () {
    $seller = onboardedSeller();

    $kit = Listing::create(['seller_id' => $seller->id, 'title' => 'A kit', 'condition' => ListingCondition::NEW]);
    $used = Listing::create(['seller_id' => $seller->id, 'title' => 'Old woofer', 'condition' => ListingCondition::USED_GOOD]);

    expect($kit->channel)->toBe(ListingChannel::MARKETPLACE)
        ->and($kit->category)->toBe(ListingCategory::KIT)
        ->and($used->category)->toBe(ListingCategory::USED_GEAR)
        ->and($kit->fresh()->channel)->toBe(ListingChannel::MARKETPLACE)
        ->and($kit->isMarketplace())->toBeTrue()->and($kit->isStore())->toBeFalse();

    $store = Listing::create(['seller_id' => adminUser()->id, 'channel' => 'store', 'driver_id' => Driver::factory()->create()->id, 'title' => 'Store']);
    expect($store->category)->toBe(ListingCategory::DRIVER)->and($store->isStore())->toBeTrue();
});

it('stores the design link and exposes it from both sides', function () {
    $design = Design::factory()->create();
    $kit = Listing::factory()->kit()->forDesign($design)->create();
    $speaker = Listing::factory()->completedSpeaker()->forDesign($design)->create();
    Listing::factory()->create(); // unrelated

    expect($kit->design->is($design))->toBeTrue()
        ->and($kit->category)->toBe(ListingCategory::KIT)
        ->and($speaker->category)->toBe(ListingCategory::COMPLETED_SPEAKER)
        ->and($design->listings()->pluck('id')->all())->toEqualCanonicalizing([$kit->id, $speaker->id]);

    // the design disappearing only unlinks the listing
    $design->delete();
    expect($kit->fresh()->design_id)->toBeNull();
});

// --- store rules -----------------------------------------------------------------------------------

it('accepts a store listing that follows the rules', function () {
    $listing = storeListing();

    expect($listing->channel)->toBe(ListingChannel::STORE)
        ->and($listing->channelRuleViolations())->toBe([])
        ->and($listing->validateChannelRules())->toBe($listing)
        ->and($listing->publishBlockers())->toBe([])
        ->and($listing->isAvailableForPurchase())->toBeTrue()
        ->and($listing->seller->isPlatformSeller())->toBeTrue();
});

it('requires an admin seller, a driver, the driver category and condition new for the store', function () {
    $listing = storeListing();

    $listing->seller_id = onboardedSeller()->id;
    $listing->unsetRelation('seller');
    expect($listing->channelRuleViolations())->toHaveKey('seller_id');

    $listing = storeListing();
    $listing->driver_id = null;
    expect($listing->channelRuleViolations())->toHaveKey('driver_id');

    $listing = storeListing();
    $listing->category = ListingCategory::KIT;
    expect($listing->channelRuleViolations())->toHaveKey('category');

    foreach ([ListingCondition::OPEN_BOX, ListingCondition::USED_GOOD] as $condition) {
        $listing = storeListing();
        $listing->condition = $condition;
        expect($listing->channelRuleViolations())->toHaveKey('condition');
    }

    expect(fn () => $listing->validateChannelRules())->toThrow(ValidationException::class);
});

it('reports the rule violations by field name in a ValidationException', function () {
    $listing = new Listing(['channel' => 'store', 'seller_id' => onboardedSeller()->id, 'condition' => 'used_good', 'category' => 'kit']);

    try {
        $listing->validateChannelRules();
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toEqualCanonicalizing(['seller_id', 'driver_id', 'category', 'condition']);
    }
});

it('allows only one active store listing per driver', function () {
    $driver = Driver::factory()->create();
    $admin = adminUser();
    $first = storeListing([], $driver, $admin);

    // a second active one is refused ...
    $second = Listing::factory()->inStore($driver, $admin)->make();
    expect($second->channelRuleViolations())->toHaveKey('driver_id')
        ->and($second->conflictingStoreListing()?->is($first))->toBeTrue();

    // ... and a draft one cannot be published while the first is active
    $draft = storeListing(['status' => ListingStatus::DRAFT], $driver, $admin);
    expect($draft->channelRuleViolations())->toBe([])
        ->and($draft->publishBlockers())->toContain('This driver already has an active store listing. Pause or remove it first.')
        ->and($draft->canBePublished())->toBeFalse();

    // the first one itself, other drivers, and marketplace listings of the driver are not in conflict
    expect($first->fresh()->channelRuleViolations())->toBe([])
        ->and(storeListing()->channelRuleViolations())->toBe([])
        ->and(Listing::factory()->used()->forDriver($driver)->make()->conflictingStoreListing())->toBeNull();

    // pausing the first one frees the driver
    $first->update(['status' => ListingStatus::PAUSED]);
    expect($draft->fresh()->publishBlockers())->toBe([]);

    $draft->update(['status' => ListingStatus::ACTIVE]);
    expect($draft->fresh()->channelRuleViolations())->toBe([])
        ->and($driver->storeListing()?->is($draft))->toBeTrue();

    // a removed (soft deleted) store listing does not block either
    $draft->delete();
    $replacement = Listing::factory()->inStore($driver, $admin)->make();
    expect($replacement->channelRuleViolations())->toBe([]);
});

// --- marketplace rules ----------------------------------------------------------------------------

it('lets the marketplace sell kits, completed speakers and used gear only', function () {
    foreach ([ListingCategory::KIT, ListingCategory::COMPLETED_SPEAKER, ListingCategory::USED_GEAR] as $category) {
        $listing = Listing::factory()->make(['category' => $category, 'condition' => ListingCondition::USED_GOOD]);
        expect($listing->channelRuleViolations())->toBe([], $category->value);
    }

    $driver = Listing::factory()->make(['category' => ListingCategory::DRIVER]);
    expect($driver->channelRuleViolations())->toHaveKey('category')
        ->and($driver->publishBlockers())->not->toBeEmpty();
});

it('never lists used gear as new, but kits and completed speakers can be new or used', function () {
    $usedGear = Listing::factory()->make(['category' => ListingCategory::USED_GEAR, 'condition' => ListingCondition::NEW]);

    expect($usedGear->channelRuleViolations())->toHaveKey('condition')
        ->and($usedGear->publishBlockers())->not->toBeEmpty();

    foreach (array_filter(ListingCondition::cases(), fn ($c) => $c !== ListingCondition::NEW) as $condition) {
        expect(Listing::factory()->make(['category' => ListingCategory::USED_GEAR, 'condition' => $condition])->channelRuleViolations())->toBe([], $condition->value);
    }

    foreach ([ListingCategory::KIT, ListingCategory::COMPLETED_SPEAKER] as $category) {
        foreach (ListingCondition::cases() as $condition) {
            expect(Listing::factory()->make(['category' => $category, 'condition' => $condition])->channelRuleViolations())->toBe([], $category->value.'/'.$condition->value);
        }
    }
});

it('links used gear to a catalogue driver and kits to a design, both optional', function () {
    $driver = Driver::factory()->create();
    $gear = Listing::factory()->used()->forDriver($driver)->create();
    $plain = Listing::factory()->used()->create();

    expect($gear->category)->toBe(ListingCategory::USED_GEAR)
        ->and($gear->driver->is($driver))->toBeTrue()
        ->and($gear->channelRuleViolations())->toBe([])
        ->and($plain->driver_id)->toBeNull()->and($plain->channelRuleViolations())->toBe([])
        ->and($driver->listings()->pluck('id')->all())->toBe([$gear->id]);
});

// --- scopes ------------------------------------------------------------------------------------------

it('separates the store from the marketplace with scopes', function () {
    $store = storeListing();
    $kit = Listing::factory()->kit()->create();
    $gear = Listing::factory()->used()->create();
    $mine = fn ($query) => $query->whereKey([$store->id, $kit->id, $gear->id])->pluck('id')->all();

    expect($mine(Listing::store()))->toBe([$store->id])
        ->and($mine(Listing::marketplace()))->toEqualCanonicalizing([$kit->id, $gear->id])
        ->and($mine(Listing::ofCategory(ListingCategory::KIT)))->toBe([$kit->id])
        ->and($mine(Listing::ofCategory('used_gear')))->toBe([$gear->id])
        ->and($mine(Listing::marketplace()->forSale()))->toEqualCanonicalizing([$kit->id, $gear->id]);
});

it('gives a driver its active store listing, and only that one', function () {
    $driver = Driver::factory()->create();
    $admin = adminUser();

    expect($driver->storeListing())->toBeNull();

    storeListing(['status' => ListingStatus::DRAFT], $driver, $admin);
    storeListing(['status' => ListingStatus::PAUSED], $driver, $admin);
    Listing::factory()->used()->forDriver($driver)->create();
    expect($driver->storeListing())->toBeNull();

    $active = storeListing([], $driver, $admin);
    expect($driver->storeListing()?->is($active))->toBeTrue()
        ->and($driver->listings()->count())->toBe(4)
        ->and(Driver::factory()->create()->storeListing())->toBeNull();
});

// --- policy ------------------------------------------------------------------------------------------

it('lets only admins create and edit store listings', function () {
    $admin = adminUser();
    $user = onboardedSeller();
    $store = storeListing(admin: $admin);

    expect(Gate::forUser($admin)->allows('createStore', Listing::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $store))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $store))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('create', new Listing(['channel' => 'store'])))->toBeTrue()
        ->and(Gate::forUser($user)->allows('createStore', Listing::class))->toBeFalse()
        ->and(Gate::forUser($user)->allows('create', new Listing(['channel' => 'store'])))->toBeFalse()
        ->and(Gate::forUser($user)->allows('update', $store))->toBeFalse()
        ->and(Gate::forUser($user)->allows('delete', $store))->toBeFalse()
        // anybody may sell in the marketplace
        ->and(Gate::forUser($user)->allows('create', Listing::class))->toBeTrue()
        ->and(Gate::forUser($user)->allows('create', new Listing(['channel' => 'marketplace'])))->toBeTrue();
});

it('does not let a seller who lost the admin role keep editing their store listing', function () {
    $admin = adminUser();
    $store = storeListing(admin: $admin);

    expect(Gate::forUser($admin)->allows('update', $store))->toBeTrue();

    $admin->syncRoles(['registered']);
    $admin = $admin->fresh();

    expect(Gate::forUser($admin)->allows('update', $store))->toBeFalse();
});

it('keeps marketplace listings editable by their own seller only', function () {
    $seller = onboardedSeller();
    $listing = Listing::factory()->forSeller($seller)->create();

    expect(Gate::forUser($seller)->allows('update', $listing))->toBeTrue()
        ->and(Gate::forUser($seller)->allows('delete', $listing))->toBeTrue()
        ->and(Gate::forUser(onboardedSeller())->allows('update', $listing))->toBeFalse()
        ->and(Gate::forUser(adminUser())->allows('update', $listing))->toBeTrue();
});

// --- pages: the store never shows up in the marketplace ------------------------------------------------

it('keeps store listings out of the marketplace browse, the seller shop and "more from this seller"', function () {
    $admin = User::factory()->admin()->onboardedSeller(['shop_name' => 'SDLabs Store', 'slug' => 'sdlabs-store'])->create();
    storeListing(['title' => 'Storeprobe Tweeter'], admin: $admin);
    $market = Listing::factory()->forSeller($admin)->kit()->create(['title' => 'Marketprobe Kit', 'price_cents' => 5000]);

    $this->get(route('marketplace', ['q' => 'probe']))
        ->assertOk()
        ->assertSee('Marketprobe Kit')
        ->assertDontSee('Storeprobe Tweeter');

    $this->get(route('seller', ['slug' => 'sdlabs-store']))
        ->assertOk()
        ->assertSee('Marketprobe Kit')
        ->assertDontSee('Storeprobe Tweeter');

    $this->get(route('listing', ['uuid' => $market->uuid]))
        ->assertOk()
        ->assertDontSee('Storeprobe Tweeter');
});

it('filters the browse by listing type', function () {
    Listing::factory()->kit()->create(['title' => 'Typeprobe Kit']);
    Listing::factory()->completedSpeaker()->create(['title' => 'Typeprobe Speaker']);
    Listing::factory()->used()->create(['title' => 'Typeprobe Gear']);

    $this->get('/marketplace?q=Typeprobe&type=kit')->assertSee('Typeprobe Kit')->assertDontSee('Typeprobe Speaker')->assertDontSee('Typeprobe Gear');
    $this->get('/marketplace?q=Typeprobe&type=completed_speaker')->assertSee('Typeprobe Speaker')->assertDontSee('Typeprobe Kit');
    $this->get('/marketplace?q=Typeprobe&type=used_gear')->assertSee('Typeprobe Gear')->assertDontSee('Typeprobe Kit');
    $this->get('/marketplace?q=Typeprobe&type=driver')->assertSee('Typeprobe Kit')->assertSee('Typeprobe Gear'); // not a marketplace type: ignored
});

it('sells the new driver from its page with the store listing and counts only marketplace listings', function () {
    $driver = Driver::factory()->create(['brand' => 'Storeprobe', 'model' => 'S1', 'active' => true]);
    $store = storeListing(['price_cents' => 12900, 'quantity' => 7], $driver);
    Listing::factory()->used()->forDriver($driver)->create(['price_cents' => 4000]);

    $this->get(route('driver', ['id' => $driver->id]))
        ->assertOk()
        ->assertSee('data-driver-store', false)
        ->assertSee('Sold by SDLabs')
        ->assertSee('$129.00')
        ->assertSee('In stock, ships in 3 days')
        ->assertSee('Used ones on the marketplace (1)');

    // a driver without a store listing has no store box
    $other = Driver::factory()->create(['active' => true]);
    $this->get(route('driver', ['id' => $other->id]))->assertOk()->assertDontSee('data-driver-store', false);

    // once the store listing is paused, the box is gone too
    $store->update(['status' => ListingStatus::PAUSED]);
    $this->get(route('driver', ['id' => $driver->id]))->assertOk()->assertDontSee('data-driver-store', false);
});
