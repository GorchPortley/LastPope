<?php

use App\Enums\EntitlementSource;
use App\Enums\ListingStatus;
use App\Models\Design;
use App\Models\User;
use App\Services\Marketplace\CartService;
use App\Services\Marketplace\Data\CartIssueType;
use App\Services\Marketplace\Data\CartOutcome;

beforeEach(function () {
    $this->carts = app(CartService::class);
    $this->buyer = User::factory()->create();
});

it('adds a paid digital item once and keeps its quantity at 1', function () {
    $design = paidDesign(cents: 2500);

    $first = $this->carts->add($this->buyer, $design, 5);
    $second = $this->carts->add($this->buyer, $design);

    expect($first->outcome)->toBe(CartOutcome::ADDED)
        ->and($first->ok())->toBeTrue()
        ->and($first->item->quantity)->toBe(1)
        ->and($first->item->unit_price_cents)->toBe(2500)
        ->and($second->outcome)->toBe(CartOutcome::ALREADY_IN_CART)
        ->and($second->level())->toBe('info')
        ->and($this->carts->count($this->buyer))->toBe(1)
        ->and($this->carts->contains($this->buyer, $design))->toBeTrue();
});

it('forces digital lines back to quantity 1 when updating', function () {
    $design = paidDesign();
    $item = $this->carts->add($this->buyer, $design)->item;

    $result = $this->carts->updateQuantity($this->buyer, $item, 4);

    expect($result->outcome)->toBe(CartOutcome::UNCHANGED)
        ->and($item->fresh()->quantity)->toBe(1);
});

it('refuses your own items', function () {
    $seller = onboardedSeller();
    $listing = sellableListing($seller);
    $design = paidDesign($seller);

    expect($this->carts->add($seller, $listing)->outcome)->toBe(CartOutcome::OWN_ITEM)
        ->and($this->carts->add($seller, $design)->outcome)->toBe(CartOutcome::OWN_ITEM)
        ->and($this->carts->count($seller))->toBe(0);
});

it('refuses a digital item the buyer already has access to', function () {
    $design = paidDesign();
    $this->buyer->grantEntitlement($design);

    $result = $this->carts->add($this->buyer, $design);

    expect($result->outcome)->toBe(CartOutcome::ALREADY_OWNED)
        ->and($this->carts->count($this->buyer))->toBe(0);
});

it('lets the buyer buy again after a refund revoked their access', function () {
    $design = paidDesign();
    $this->buyer->grantEntitlement($design);
    $this->buyer->revokeEntitlement($design);

    expect($this->carts->add($this->buyer, $design)->outcome)->toBe(CartOutcome::ADDED);
});

it('skips the cart for free digital items and grants a free entitlement at once', function () {
    $free = Design::factory()->free()->withSnapshot()->create();

    $result = $this->carts->add($this->buyer, $free);

    expect($result->outcome)->toBe(CartOutcome::FREE_GRANTED)
        ->and($result->ok())->toBeTrue()
        ->and($result->entitlement->source)->toBe(EntitlementSource::FREE)
        ->and($this->buyer->entitlements()->count())->toBe(1)
        ->and($this->carts->count($this->buyer))->toBe(0)
        ->and($this->carts->add($this->buyer, $free)->outcome)->toBe(CartOutcome::ALREADY_OWNED);
});

it('does not hand a free item back to somebody whose access was withdrawn', function () {
    $free = Design::factory()->free()->withSnapshot()->create();
    $this->buyer->grantEntitlement($free, EntitlementSource::FREE);
    $this->buyer->revokeEntitlement($free);

    $result = $this->carts->add($this->buyer, $free);

    expect($result->outcome)->toBe(CartOutcome::ACCESS_REVOKED)
        ->and($this->buyer->hasAccessTo($free))->toBeFalse();
});

it('does not add unavailable items', function () {
    $draft = Design::factory()->paid(1000)->draft()->withSnapshot()->for(onboardedSeller(), 'designer')->create();
    $noFiles = Design::factory()->paid(1000)->for(onboardedSeller(), 'designer')->create();
    $unpaid = Design::factory()->paid(1000)->withSnapshot()->for(User::factory()->seller(), 'designer')->create();
    $paused = sellableListing(attributes: ['status' => ListingStatus::PAUSED]);
    $freeDraft = Design::factory()->free()->draft()->withSnapshot()->create();

    foreach ([$draft, $noFiles, $unpaid, $paused, $freeDraft] as $item) {
        expect($this->carts->add($this->buyer, $item)->outcome)->toBe(CartOutcome::NOT_AVAILABLE);
    }

    expect($this->carts->count($this->buyer))->toBe(0)
        ->and($this->buyer->entitlements()->count())->toBe(0);
});

it('caps listing quantity at the stock, also across repeated adds', function () {
    $listing = sellableListing(attributes: ['quantity' => 3]);

    $one = $this->carts->add($this->buyer, $listing, 2);
    $tooMany = $this->carts->add($this->buyer, $listing, 2);
    $more = $this->carts->add($this->buyer, $listing, 1);

    expect($one->outcome)->toBe(CartOutcome::ADDED)
        ->and($tooMany->outcome)->toBe(CartOutcome::INSUFFICIENT_STOCK)
        ->and($tooMany->available)->toBe(3)
        ->and($tooMany->message())->toContain('Only 3')
        ->and($more->outcome)->toBe(CartOutcome::QUANTITY_UPDATED)
        ->and($this->carts->count($this->buyer))->toBe(3);

    expect($this->carts->updateQuantity($this->buyer, $one->item, 4)->outcome)->toBe(CartOutcome::INSUFFICIENT_STOCK);
    expect($this->carts->updateQuantity($this->buyer, $one->item, 2)->outcome)->toBe(CartOutcome::QUANTITY_UPDATED)
        ->and($this->carts->count($this->buyer))->toBe(2);
});

it('reports sold-out listings and invalid quantities', function () {
    $soldOut = Database\Factories\ListingFactory::new()->forSeller(onboardedSeller())->soldOut()->create();
    $listing = sellableListing();

    $result = $this->carts->add($this->buyer, $soldOut);

    expect($result->outcome)->toBe(CartOutcome::INSUFFICIENT_STOCK)
        ->and($result->message())->toContain('sold out')
        ->and($this->carts->add($this->buyer, $listing, 0)->outcome)->toBe(CartOutcome::INVALID_QUANTITY);
});

it('removes lines, treats quantity 0 as remove, and clears the cart', function () {
    $listing = sellableListing();
    $design = paidDesign();
    $listingItem = $this->carts->add($this->buyer, $listing)->item;
    $this->carts->add($this->buyer, $design);

    expect($this->carts->updateQuantity($this->buyer, $listingItem, 0)->outcome)->toBe(CartOutcome::REMOVED)
        ->and($this->carts->count($this->buyer))->toBe(1)
        ->and($this->carts->clear($this->buyer)->outcome)->toBe(CartOutcome::CLEARED)
        ->and($this->carts->count($this->buyer))->toBe(0);
});

it('only lets a user touch their own cart lines', function () {
    $item = $this->carts->add($this->buyer, sellableListing())->item;
    $other = User::factory()->create();

    expect($this->carts->remove($other, $item)->outcome)->toBe(CartOutcome::ITEM_NOT_FOUND)
        ->and($this->carts->updateQuantity($other, $item->id, 2)->outcome)->toBe(CartOutcome::ITEM_NOT_FOUND)
        ->and($item->fresh())->not->toBeNull();
});

it('summarises the cart grouped by seller with fee, shipping and totals', function () {
    $sellerA = onboardedSeller();
    $sellerB = User::factory()->onboardedSeller(['commission_bps' => 500])->create();
    $design = paidDesign($sellerA, 2000);
    $listing = sellableListing($sellerB, 4000, ['shipping_domestic_cents' => 800, 'additional_item_shipping_cents' => 200]);
    fillCart($this->buyer, [$design, [$listing, 2]]);
    $address = addressFor($this->buyer);

    $summary = $this->carts->summary($this->buyer, $address);

    expect($summary->isEmpty())->toBeFalse()
        ->and($summary->needsAddress)->toBeTrue()
        ->and($summary->canCheckout())->toBeTrue()
        ->and($summary->itemCount)->toBe(3)
        ->and($summary->subtotalCents)->toBe(2000 + 8000)
        ->and($summary->shippingCents)->toBe(1000)
        ->and($summary->totalCents())->toBe(11000)
        ->and($summary->sellers)->toHaveCount(2);

    [$groupA, $groupB] = $summary->sellers;
    expect($groupA->subtotalCents)->toBe(2000)
        ->and($groupA->feeCents)->toBe(200)
        ->and($groupA->payoutCents)->toBe(1800)
        ->and($groupB->subtotalCents)->toBe(8000)
        ->and($groupB->shippingCents)->toBe(1000)
        ->and($groupB->feeCents)->toBe(400)
        ->and($groupB->payoutCents)->toBe(8000 + 1000 - 400)
        ->and($summary->feeCents)->toBe(600);
});

it('does not need an address for digital carts, and says so for physical ones', function () {
    fillCart($this->buyer, [paidDesign()]);
    $digital = $this->carts->summary($this->buyer);

    expect($digital->needsAddress)->toBeFalse()->and($digital->canCheckout())->toBeTrue();

    fillCart($this->buyer, [sellableListing()]);
    $mixed = $this->carts->summary($this->buyer);

    expect($mixed->needsAddress)->toBeTrue()
        ->and($mixed->addressMissing())->toBeTrue()
        ->and($mixed->shippingPending)->toBeTrue()
        ->and($mixed->canCheckout())->toBeFalse()
        ->and($mixed->hasIssues())->toBeFalse();
});

it('ignores an address that belongs to somebody else', function () {
    fillCart($this->buyer, [sellableListing()]);
    $foreign = addressFor(User::factory()->create());

    expect($this->carts->summary($this->buyer, $foreign)->address)->toBeNull();
});

it('re-prices at today\'s price and flags the change without writing', function () {
    $design = paidDesign(cents: 2000);
    $item = $this->carts->add($this->buyer, $design)->item;
    $design->update(['price_cents' => 3000]);

    $line = $this->carts->summary($this->buyer)->lines[0];

    expect($line->unitPriceCents)->toBe(3000)
        ->and($line->storedUnitPriceCents)->toBe(2000)
        ->and($line->priceChanged())->toBeTrue()
        ->and($item->fresh()->unit_price_cents)->toBe(2000);
});

it('detects items that became unavailable, sold out, short on stock, owned, or deleted', function () {
    $draftLater = paidDesign();
    $soldOutLater = sellableListing(attributes: ['quantity' => 2]);
    $lessStock = sellableListing(attributes: ['quantity' => 5]);
    $boughtElsewhere = paidDesign();
    $deleted = sellableListing();
    fillCart($this->buyer, [$draftLater, [$soldOutLater, 1], [$lessStock, 4], $boughtElsewhere, $deleted]);

    $draftLater->update(['active' => false]);
    $soldOutLater->update(['quantity' => 0, 'status' => ListingStatus::SOLD_OUT]);
    $lessStock->update(['quantity' => 2]);
    $this->buyer->grantEntitlement($boughtElsewhere);
    $deleted->delete();

    $summary = $this->carts->summary($this->buyer);
    $types = collect($summary->lines)->mapWithKeys(fn ($line) => [$line->title => collect($line->issues)->pluck('type')->all()]);

    expect($summary->canCheckout())->toBeFalse()
        ->and($types[$draftLater->name])->toBe([CartIssueType::UNAVAILABLE])
        ->and($types[$soldOutLater->title])->toBe([CartIssueType::INSUFFICIENT_STOCK])
        ->and($types[$lessStock->title])->toBe([CartIssueType::INSUFFICIENT_STOCK])
        ->and($types[$boughtElsewhere->name])->toBe([CartIssueType::ALREADY_OWNED])
        ->and($types['Item no longer available'])->toBe([CartIssueType::ITEM_MISSING])
        ->and($summary->errors())->toHaveCount(5);
});

it('flags listings the seller will not ship to the chosen country', function () {
    $domesticOnly = sellableListing(attributes: ['ships_from_country' => 'US', 'shipping_international_cents' => null]);
    fillCart($this->buyer, [$domesticOnly]);

    $us = $this->carts->summary($this->buyer, addressFor($this->buyer, 'US'));
    $de = $this->carts->summary($this->buyer, addressFor($this->buyer, 'DE', default: false));

    expect($us->canCheckout())->toBeTrue()
        ->and($de->canCheckout())->toBeFalse()
        ->and($de->issues()[0]->type)->toBe(CartIssueType::NOT_SHIPPABLE);
});

it('backs the buy box on the design page with the same rules', function () {
    $seller = onboardedSeller();
    $design = paidDesign($seller, 1500);
    $free = Design::factory()->free()->withSnapshot()->create();

    $mine = Livewire\Livewire::actingAs($seller)->test(App\Http\Livewire\BuyBox::class, ['purchasable' => $design]);
    $mine->call('add');
    expect($this->carts->count($seller))->toBe(0);

    $box = Livewire\Livewire::actingAs($this->buyer)->test(App\Http\Livewire\BuyBox::class, ['purchasable' => $design]);
    $box->call('add')->assertDispatched('cart-updated')->assertSee('In your cart');
    expect($this->carts->count($this->buyer))->toBe(1);

    $freeBox = Livewire\Livewire::actingAs($this->buyer)->test(App\Http\Livewire\BuyBox::class, ['purchasable' => $free]);
    $freeBox->call('add')->assertSee('In your library');
    expect($this->buyer->entitlementFor($free)?->isActive())->toBeTrue()
        ->and($this->carts->count($this->buyer))->toBe(1);
});
