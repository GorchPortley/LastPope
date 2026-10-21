<?php

use App\Contracts\Purchasable;
use App\Enums\ListingCondition;
use App\Enums\ListingStatus;
use App\Models\Design;
use App\Models\Driver;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;

it('is implemented by designs, drivers and listings', function () {
    expect(new Design())->toBeInstanceOf(Purchasable::class)
        ->and(new Driver())->toBeInstanceOf(Purchasable::class)
        ->and(new Listing())->toBeInstanceOf(Purchasable::class);
});

it('registers a non-enforcing morph map', function () {
    expect((new Design())->getMorphClass())->toBe('design')
        ->and((new Driver())->getMorphClass())->toBe('driver')
        ->and((new Listing())->getMorphClass())->toBe('listing')
        ->and(Relation::getMorphedModel('listing'))->toBe(Listing::class)
        ->and(Relation::morphMap()['user'] ?? null)->toBeNull()   // we do not map users (Wave owns that alias)
        ->and(Relation::requiresMorphMap())->toBeFalse();
});

// --- Design ----------------------------------------------------------------------------------

it('describes a design as a digital product', function () {
    $design = Design::factory()->paid(2500)->create(['name' => 'Harbor']);

    expect($design->purchasableTitle())->toBe('Harbor')
        ->and($design->purchasablePriceCents())->toBe(2500)
        ->and($design->getSeller()->is($design->designer))->toBeTrue()
        ->and($design->isDigital())->toBeTrue()
        ->and($design->requiresShipping())->toBeFalse();
});

it('sells a free design that is active and has a snapshot, whoever the owner is', function () {
    $unpaid = User::factory()->create(); // no payout setup at all

    expect(Design::factory()->for($unpaid, 'designer')->withSnapshot()->create()->isAvailableForPurchase())->toBeTrue()
        ->and(Design::factory()->for($unpaid, 'designer')->create()->isAvailableForPurchase())->toBeFalse()              // no snapshot
        ->and(Design::factory()->for($unpaid, 'designer')->draft()->withSnapshot()->create()->isAvailableForPurchase())->toBeFalse(); // draft
});

it('sells a paid design only with a snapshot and a seller that can be paid', function () {
    $seller = onboardedSeller();

    expect(Design::factory()->for($seller, 'designer')->paid()->withSnapshot()->create()->isAvailableForPurchase())->toBeTrue()
        ->and(Design::factory()->for($seller, 'designer')->paid()->create()->isAvailableForPurchase())->toBeFalse()
        ->and(Design::factory()->for(User::factory()->seller()->create(), 'designer')->paid()->withSnapshot()->create()->isAvailableForPurchase())->toBeFalse() // onboarding unfinished
        ->and(Design::factory()->for(User::factory()->create(), 'designer')->paid()->withSnapshot()->create()->isAvailableForPurchase())->toBeFalse() // no seller profile
        ->and(Design::factory()->for($seller, 'designer')->paid()->draft()->withSnapshot()->create()->isAvailableForPurchase())->toBeFalse();
});

it('lets the platform sell paid designs without Stripe Connect', function () {
    $admin = User::factory()->admin()->create();

    expect($admin->isPlatformSeller())->toBeTrue()
        ->and($admin->canReceivePayouts())->toBeFalse()
        ->and(Design::factory()->for($admin, 'designer')->paid()->withSnapshot()->create()->isAvailableForPurchase())->toBeTrue();
});

it('explains why a design cannot be published', function () {
    $free = Design::factory()->draft()->create();
    $paidUnready = Design::factory()->for(User::factory()->create(), 'designer')->paid()->create();
    $paidNoSnapshot = Design::factory()->for(onboardedSeller(), 'designer')->paid()->create();
    $paidReady = Design::factory()->for(onboardedSeller(), 'designer')->paid()->withSnapshot()->create();

    expect($free->publishBlockers())->toBe([])
        ->and($free->canBePublished())->toBeTrue()
        ->and($paidUnready->publishBlockers())->toHaveCount(2)
        ->and($paidUnready->publishBlockers()[0])->toContain('snapshot')
        ->and($paidUnready->publishBlockers()[1])->toContain('Stripe')
        ->and($paidNoSnapshot->publishBlockers())->toHaveCount(1)
        ->and($paidNoSnapshot->publishBlockers()[0])->toContain('snapshot')
        ->and($paidReady->publishBlockers())->toBe([])
        ->and($paidReady->canBePublished())->toBeTrue();
});

// --- Driver ----------------------------------------------------------------------------------

it('sells a driver data pack under the same rules', function () {
    $seller = onboardedSeller();
    $driver = Driver::factory()->for($seller, 'creator')->paid(399)->withSnapshot()->create(['brand' => 'Aurelia', 'model' => 'A16']);

    expect($driver->purchasableTitle())->toBe('Aurelia A16 data pack')
        ->and($driver->purchasablePriceCents())->toBe(399)
        ->and($driver->getSeller()->is($seller))->toBeTrue()
        ->and($driver->isDigital())->toBeTrue()
        ->and($driver->requiresShipping())->toBeFalse()
        ->and($driver->isAvailableForPurchase())->toBeTrue()
        ->and(Driver::factory()->paid()->withSnapshot()->create()->isAvailableForPurchase())->toBeFalse() // seller cannot be paid
        ->and(Driver::factory()->for($seller, 'creator')->paid()->create()->isAvailableForPurchase())->toBeFalse()
        ->and(Driver::factory()->withSnapshot()->create()->isAvailableForPurchase())->toBeTrue()
        ->and(Driver::factory()->paid()->create()->publishBlockers())->toHaveCount(2)
        ->and(Driver::factory()->create()->publishBlockers())->toBe([]);
});

it('keeps the MSRP separate from the data pack price', function () {
    $driver = Driver::factory()->create(['msrp_cents' => 12900, 'data_price_cents' => 0]);

    expect($driver->msrp_cents)->toBe(12900)
        ->and($driver->purchasablePriceCents())->toBe(0);
});

// --- Listing ---------------------------------------------------------------------------------

it('describes a listing as a physical product', function () {
    $listing = Listing::factory()->create(['title' => 'A woofer', 'price_cents' => 4500]);

    expect($listing->purchasableTitle())->toBe('A woofer')
        ->and($listing->purchasablePriceCents())->toBe(4500)
        ->and($listing->getSeller()->is($listing->seller))->toBeTrue()
        ->and($listing->isDigital())->toBeFalse()
        ->and($listing->requiresShipping())->toBeTrue();
});

it('sells a listing that is active, in stock, priced and sold by someone who can be paid', function () {
    expect(Listing::factory()->create()->isAvailableForPurchase())->toBeTrue()
        ->and(Listing::factory()->draft()->create()->isAvailableForPurchase())->toBeFalse()
        ->and(Listing::factory()->paused()->create()->isAvailableForPurchase())->toBeFalse()
        ->and(Listing::factory()->soldOut()->create()->isAvailableForPurchase())->toBeFalse()
        ->and(Listing::factory()->create(['quantity' => 0])->isAvailableForPurchase())->toBeFalse()
        ->and(Listing::factory()->create(['price_cents' => 0])->isAvailableForPurchase())->toBeFalse()
        ->and(Listing::factory()->forSeller(User::factory()->create())->create()->isAvailableForPurchase())->toBeFalse()
        ->and(Listing::factory()->forSeller(User::factory()->seller()->create())->create()->isAvailableForPurchase())->toBeFalse()
        ->and(Listing::factory()->forSeller(User::factory()->admin()->create())->create()->isAvailableForPurchase())->toBeTrue();
});

it('explains why a listing cannot be published', function () {
    $unpaid = User::factory()->create();
    $listing = Listing::factory()->draft()->forSeller($unpaid)->create(['price_cents' => 0, 'quantity' => 0]);

    expect($listing->publishBlockers())->toHaveCount(3)
        ->and(implode(' ', $listing->publishBlockers()))->toContain('price')->toContain('Quantity')->toContain('Stripe')
        ->and(Listing::factory()->draft()->create()->publishBlockers())->toBe([]);
});

it('derives new and used from the condition', function () {
    expect(ListingCondition::NEW->isNew())->toBeTrue()
        ->and(ListingCondition::OPEN_BOX->isNew())->toBeTrue()
        ->and(ListingCondition::USED_LIKE_NEW->isNew())->toBeFalse()
        ->and(ListingCondition::USED_GOOD->isUsed())->toBeTrue()
        ->and(ListingCondition::USED_FAIR->isUsed())->toBeTrue()
        ->and(ListingCondition::FOR_PARTS->isUsed())->toBeTrue()
        ->and(ListingCondition::USED_GOOD->label())->toBe('Used - good');

    $new = Listing::factory()->brandNew()->create();
    $openBox = Listing::factory()->openBox()->create();
    $used = Listing::factory()->used()->create();

    expect($new->isNew())->toBeTrue()
        ->and($openBox->isNew())->toBeTrue()
        ->and($used->isUsed())->toBeTrue()
        ->and(Listing::brandNew()->whereKey([$new->id, $openBox->id, $used->id])->count())->toBe(2)
        ->and(Listing::used()->whereKey([$new->id, $openBox->id, $used->id])->pluck('id')->all())->toBe([$used->id]);
});

it('labels every enum case for the interface', function () {
    foreach ([ListingStatus::class, App\Enums\OrderStatus::class, App\Enums\SellerOrderStatus::class, App\Enums\EntitlementSource::class, ListingCondition::class] as $enum) {
        foreach ($enum::cases() as $case) {
            expect($case->label())->toBeString()->not->toBeEmpty();
        }
        expect($enum::options())->toHaveCount(count($enum::cases()));
    }
});

it('prices shipping from the seller country with a surcharge per extra unit', function () {
    $listing = Listing::factory()->create([
        'ships_from_country' => 'US',
        'shipping_domestic_cents' => 800,
        'shipping_international_cents' => 2500,
        'additional_item_shipping_cents' => 300,
    ]);

    expect($listing->shippingCentsFor('US'))->toBe(800)
        ->and($listing->shippingCentsFor('us', 3))->toBe(1400)
        ->and($listing->shippingCentsFor('DE'))->toBe(2500)
        ->and($listing->shippingCentsFor('DE', 2))->toBe(2800);

    $domesticOnly = Listing::factory()->domesticOnly()->create(['ships_from_country' => 'US']);
    expect($domesticOnly->shippingCentsFor('US'))->not->toBeNull()
        ->and($domesticOnly->shippingCentsFor('CA'))->toBeNull();
});

it('treats a pair listing as one lot of two units', function () {
    $pair = Listing::factory()->pair()->create();

    expect($pair->isLot())->toBeTrue()
        ->and($pair->units_per_lot)->toBe(2)
        ->and(Listing::factory()->create()->isLot())->toBeFalse();
});

it('finds listings by uuid and fills slug and uuid itself', function () {
    $listing = Listing::factory()->create(['title' => 'Aurelia A16-W 6.5" woofer', 'slug' => null, 'uuid' => null]);

    expect($listing->uuid)->toMatch('/^[0-9a-f-]{36}$/')
        ->and($listing->slug)->toBe('aurelia-a16-w-65-woofer')
        ->and(Listing::where('uuid', $listing->uuid)->first()->is($listing))->toBeTrue()
        ->and($listing->getRouteKeyName())->toBe('uuid');
});

it('knows who can receive payouts', function () {
    $pending = User::factory()->seller()->create();
    $ready = User::factory()->onboardedSeller()->create();

    expect($pending->canReceivePayouts())->toBeFalse()
        ->and($ready->canReceivePayouts())->toBeTrue()
        ->and(User::factory()->create()->canReceivePayouts())->toBeFalse()
        ->and($ready->isPlatformSeller())->toBeFalse();

    $ready->sellerProfile->update(['payouts_enabled' => false]);
    expect($ready->refresh()->canReceivePayouts())->toBeFalse();
});
