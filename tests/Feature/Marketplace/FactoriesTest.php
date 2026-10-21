<?php

use App\Enums\EntitlementSource;
use App\Enums\ListingCondition;
use App\Enums\ListingStatus;
use App\Enums\OrderStatus;
use App\Enums\SellerOrderStatus;
use App\Models\Address;
use App\Models\Banner;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Design;
use App\Models\DesignDriver;
use App\Models\DesignSnapshot;
use App\Models\Driver;
use App\Models\DriverSnapshot;
use App\Models\Entitlement;
use App\Models\Listing;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\StripeEvent;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

it('creates every model from its factory', function () {
    foreach ([
        User::class, SellerProfile::class, Design::class, Driver::class, DesignDriver::class, DesignSnapshot::class,
        DriverSnapshot::class, Listing::class, Cart::class, CartItem::class, Address::class, Order::class, SellerOrder::class,
        OrderItem::class, Entitlement::class, StripeEvent::class, Banner::class,
    ] as $model) {
        $instance = $model::factory()->create();

        expect($instance->exists)->toBeTrue("{$model} factory");
        expect($model::query()->whereKey($instance->getKey())->exists())->toBeTrue("{$model} row");
    }
});

it('fills storage keys and keeps designs and drivers apart', function () {
    $design = Design::factory()->create();
    $driver = Driver::factory()->create();

    expect($design->storage_key)->toMatch('/^[0-9a-f-]{36}$/')
        ->and($driver->storage_key)->toMatch('/^[0-9a-f-]{36}$/')
        ->and($design->storageDirectory())->toBe('designs/'.$design->storage_key)
        ->and($design->snapshotDirectory())->toBe('designs/'.$design->storage_key.'/snapshots')
        ->and($driver->storageDirectory('Frequency'))->toBe('drivers/'.$driver->storage_key.'/Frequency')
        ->and(Design::factory()->create()->storage_key)->not->toBe($design->storage_key);
});

it('has design and driver states', function () {
    $free = Design::factory()->free()->create();
    $paid = Design::factory()->paid(1234)->create();
    $draft = Design::factory()->draft()->create();
    $withSnapshot = Design::factory()->withSnapshot('v2.0')->create();

    expect($free->price_cents)->toBe(0)
        ->and($paid->price_cents)->toBe(1234)
        ->and(Design::factory()->paid()->create()->price_cents)->toBeGreaterThan(0)
        ->and($draft->active)->toBeFalse()
        ->and(Design::factory()->active()->create()->active)->toBeTrue()
        ->and($withSnapshot->snapshots)->toHaveCount(1)
        ->and($withSnapshot->snapshots->first()->version)->toBe('v2.0')
        ->and($withSnapshot->snapshots->first()->download_path)->toStartWith('designs/'.$withSnapshot->storage_key.'/snapshots/')
        ->and(Design::free()->whereKey($free->id)->exists())->toBeTrue()
        ->and(Design::paid()->whereKey($free->id)->exists())->toBeFalse();

    $driver = Driver::factory()->paid(499)->draft()->withSnapshot()->create(['msrp_cents' => null]);
    expect($driver->data_price_cents)->toBe(499)
        ->and($driver->msrp_cents)->toBeNull()
        ->and($driver->active)->toBeFalse()
        ->and($driver->snapshots)->toHaveCount(1);
});

it('can write a real snapshot file', function () {
    Storage::fake('private');

    $design = Design::factory()->withSnapshot('v1.0', true)->create();
    $snapshot = $design->snapshots->first();

    expect(Storage::disk('private')->exists($snapshot->download_path))->toBeTrue()
        ->and($snapshot->refresh()->file_size)->toBe(Storage::disk('private')->size($snapshot->download_path))
        ->and((new ZipArchive())->open(Storage::disk('private')->path($snapshot->download_path)))->toBeTrue();
});

it('has user and seller states', function () {
    $plain = User::factory()->create();
    $admin = User::factory()->admin()->create();
    $platform = User::factory()->platformSeller()->create();
    $pending = User::factory()->seller()->create();
    $onboarded = User::factory()->onboardedSeller()->create();

    expect($plain->sellerProfile)->toBeNull()
        ->and($plain->hasRole('registered'))->toBeTrue()
        ->and($admin->hasRole('admin'))->toBeTrue()
        ->and($admin->isPlatformSeller())->toBeTrue()
        ->and($platform->isPlatformSeller())->toBeTrue()
        ->and($pending->sellerProfile)->not->toBeNull()
        ->and($pending->canReceivePayouts())->toBeFalse()
        ->and($onboarded->canReceivePayouts())->toBeTrue()
        ->and($onboarded->sellerProfile->stripe_account_id)->toStartWith('acct_')
        ->and($onboarded->sellerProfile->slug)->not->toBeEmpty();

    expect(SellerProfile::factory()->pending()->create()->canReceivePayouts())->toBeFalse()
        ->and(SellerProfile::factory()->withoutStripe()->create()->stripe_account_id)->toBeNull()
        ->and(SellerProfile::factory()->commission(500)->create()->feeBps())->toBe(500)
        ->and(SellerProfile::factory()->create()->feeBps())->toBe(1000)
        ->and(SellerProfile::onboarded()->count())->toBeGreaterThan(0);
});

it('encrypts the Flarum password at rest', function () {
    $user = User::factory()->create();
    $user->forum_password = 'plain-secret';
    $user->save();

    $raw = DB::table('users')->where('id', $user->id)->value('forum_password');

    expect($raw)->not->toBe('plain-secret')
        ->and(decrypt($raw, false))->toBe('plain-secret')
        ->and($user->refresh()->forum_password)->toBe('plain-secret')
        ->and($user->toArray())->not->toHaveKey('forum_password');
});

it('has listing states', function () {
    $new = Listing::factory()->brandNew()->create();
    $used = Listing::factory()->used()->create();
    $pair = Listing::factory()->pair()->create();
    $domestic = Listing::factory()->domesticOnly()->create();
    $linked = Listing::factory()->forDriver($driver = Driver::factory()->create())->create();

    expect($new->condition)->toBe(ListingCondition::NEW)
        ->and($used->condition->isUsed())->toBeTrue()
        ->and($used->quantity)->toBe(1)
        ->and($pair->units_per_lot)->toBe(2)
        ->and($domestic->shipping_international_cents)->toBeNull()
        ->and($linked->driver->is($driver))->toBeTrue()
        ->and($driver->listings)->toHaveCount(1)
        ->and(Listing::factory()->draft()->create()->status)->toBe(ListingStatus::DRAFT)
        ->and(Listing::factory()->paused()->create()->status)->toBe(ListingStatus::PAUSED)
        ->and(Listing::factory()->soldOut()->create()->quantity)->toBe(0)
        ->and($new->seller->canReceivePayouts())->toBeTrue()
        ->and(Listing::factory()->openBox()->create()->isNew())->toBeTrue()
        ->and(Listing::factory()->active()->create()->status)->toBe(ListingStatus::ACTIVE);

    $seller = User::factory()->onboardedSeller()->create();
    $listing = Listing::factory()->forSeller($seller)->create();
    expect($seller->listings)->toHaveCount(1)->and($listing->seller->is($seller))->toBeTrue();
});

it('softly deletes listings', function () {
    $listing = Listing::factory()->create();
    $listing->delete();

    expect(Listing::count())->toBe(Listing::withoutTrashed()->count())
        ->and(Listing::withTrashed()->whereKey($listing->id)->exists())->toBeTrue()
        ->and(Listing::whereKey($listing->id)->exists())->toBeFalse();
});

it('has cart and address states', function () {
    $listing = Listing::factory()->create(['price_cents' => 2000]);
    $design = Design::factory()->paid(900)->create();

    $cart = Cart::factory()->withItems([[$listing, 3], $design])->create();

    expect($cart->items)->toHaveCount(2)
        ->and($cart->itemCount())->toBe(4)
        ->and($cart->subtotalCents())->toBe(6900)
        ->and($cart->items->firstWhere('purchasable_type', 'listing')->purchasable->is($listing))->toBeTrue()
        ->and($listing->cartItems)->toHaveCount(1)
        ->and($cart->user->cart->is($cart))->toBeTrue();

    $user = User::factory()->create();
    $first = Address::factory()->for($user)->default()->create();
    $second = Address::factory()->for($user)->default()->create();

    expect($first->refresh()->is_default)->toBeFalse()
        ->and($second->refresh()->is_default)->toBeTrue()
        ->and($user->addresses)->toHaveCount(2)
        ->and(Address::factory()->inCountry('de')->make()->country)->toBe('DE')
        ->and($second->toSnapshot())->toHaveKeys(['name', 'line1', 'city', 'postal_code', 'country']);
});

it('rejects a second cart line for the same product', function () {
    $cart = Cart::factory()->create();
    $listing = Listing::factory()->create();

    CartItem::factory()->forPurchasable($listing)->create(['cart_id' => $cart->id]);

    expect(fn () => CartItem::factory()->forPurchasable($listing)->create(['cart_id' => $cart->id]))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('generates human readable order numbers', function () {
    $number = Order::generateNumber();

    expect($number)->toMatch('/^SDL-[A-HJ-NP-Z2-9]{6}$/')
        ->and(Order::factory()->create()->number)->toMatch('/^SDL-[A-HJ-NP-Z2-9]{6}$/')
        ->and(Order::factory()->create()->status)->toBe(OrderStatus::PENDING);
});

it('builds paid orders with consistent totals and fulfils digital items', function () {
    $designer = User::factory()->onboardedSeller()->create();
    $design = Design::factory()->for($designer, 'designer')->paid(2000)->withSnapshot()->create();
    $shop = User::factory()->onboardedSeller()->create();
    $listing = Listing::factory()->forSeller($shop)->create(['price_cents' => 5000, 'ships_from_country' => 'US', 'shipping_domestic_cents' => 800, 'additional_item_shipping_cents' => 300]);
    $buyer = User::factory()->create();

    $order = Order::factory()->for($buyer, 'user')->paid([$design, [$listing, 2]])->create()->refresh();

    $designerOrder = $order->sellerOrders->firstWhere('seller_id', $designer->id);
    $shopOrder = $order->sellerOrders->firstWhere('seller_id', $shop->id);

    expect($order->status)->toBe(OrderStatus::PAID)
        ->and($order->paid_at)->not->toBeNull()
        ->and($order->stripe_checkout_session_id)->toStartWith('cs_')
        ->and($order->sellerOrders)->toHaveCount(2)
        ->and($order->items)->toHaveCount(2)
        // digital seller order is complete and paid out; physical one waits for the parcel
        ->and($designerOrder->status)->toBe(SellerOrderStatus::COMPLETED)
        ->and($designerOrder->subtotal_cents)->toBe(2000)
        ->and($designerOrder->platform_fee_cents)->toBe(200)
        ->and($designerOrder->payout_cents)->toBe(1800)
        ->and($designerOrder->transferred_at)->not->toBeNull()
        ->and($shopOrder->status)->toBe(SellerOrderStatus::AWAITING_SHIPMENT)
        ->and($shopOrder->subtotal_cents)->toBe(10000)
        ->and($shopOrder->shipping_cents)->toBe(1100)                      // 800 + one extra unit at 300
        ->and($shopOrder->platform_fee_cents)->toBe(1000)
        ->and($shopOrder->payout_cents)->toBe(10100)                       // subtotal + shipping - fee
        ->and($shopOrder->transferred_at)->toBeNull()                      // payout waits for "shipped"
        // order totals add up
        ->and($order->subtotal_cents)->toBe(12000)
        ->and($order->shipping_cents)->toBe(1100)
        ->and($order->total_cents)->toBe(13100)
        ->and($order->platform_fee_cents)->toBe(1200)
        ->and($order->items->sum('line_total_cents'))->toBe($order->subtotal_cents)
        // the buyer can download the design now
        ->and($buyer->hasAccessTo($design))->toBeTrue()
        ->and($buyer->entitlementFor($design)->source)->toBe(EntitlementSource::PURCHASE)
        ->and($buyer->entitlementFor($design)->orderItem->order->is($order))->toBeTrue()
        ->and($buyer->orders)->toHaveCount(1)
        ->and($shop->sellerOrders)->toHaveCount(1)
        ->and($order->hasPhysicalItems())->toBeTrue();
});

it('builds shipped, completed, refunded, pending and cancelled orders', function () {
    $listing = Listing::factory()->create();
    $design = Design::factory()->for(User::factory()->admin()->create(), 'designer')->paid()->withSnapshot()->create();

    $shipped = Order::factory()->shipped([$listing])->create()->refresh();
    $completed = Order::factory()->completed([$listing])->create()->refresh();
    $refunded = Order::factory()->refunded([$design])->create()->refresh();
    $pending = Order::factory()->pending([$listing])->create()->refresh();
    $cancelled = Order::factory()->cancelled()->create()->refresh();

    $shippedOrder = $shipped->sellerOrders->first();
    expect($shipped->status)->toBe(OrderStatus::PAID)
        ->and($shippedOrder->status)->toBe(SellerOrderStatus::SHIPPED)
        ->and($shippedOrder->carrier)->not->toBeNull()
        ->and($shippedOrder->tracking_number)->not->toBeNull()
        ->and($shippedOrder->shipped_at)->not->toBeNull()
        ->and($shippedOrder->stripe_transfer_id)->toStartWith('tr_');

    expect($completed->status)->toBe(OrderStatus::COMPLETED)
        ->and($completed->sellerOrders->first()->status)->toBe(SellerOrderStatus::COMPLETED)
        ->and($completed->sellerOrders->first()->delivered_at)->not->toBeNull();

    // platform seller: no fee, no payout, no transfer; refund revokes the entitlement
    $refundedSellerOrder = $refunded->sellerOrders->first();
    expect($refunded->status)->toBe(OrderStatus::REFUNDED)
        ->and($refundedSellerOrder->status)->toBe(SellerOrderStatus::REFUNDED)
        ->and($refundedSellerOrder->platform_fee_cents)->toBe(0)
        ->and($refundedSellerOrder->payout_cents)->toBe(0)
        ->and($refunded->user->hasAccessTo($design))->toBeFalse()
        ->and($refunded->user->entitlementFor($design)->revoked_at)->not->toBeNull();

    expect($pending->status)->toBe(OrderStatus::PENDING)
        ->and($pending->paid_at)->toBeNull()
        ->and($pending->sellerOrders->first()->status)->toBe(SellerOrderStatus::PENDING)
        ->and($cancelled->status)->toBe(OrderStatus::CANCELLED)
        ->and($cancelled->sellerOrders->first()->status)->toBe(SellerOrderStatus::CANCELLED);
});

it('keeps order items pointing at their products through the morph map', function () {
    $listing = Listing::factory()->create();
    $order = Order::factory()->paid([$listing])->create();
    $item = $order->items()->first();

    expect($item->purchasable_type)->toBe('listing')
        ->and($item->purchasable->is($listing))->toBeTrue()
        ->and($listing->orderItems)->toHaveCount(1)
        ->and($item->title)->toBe($listing->title)
        ->and($item->is_digital)->toBeFalse();
});

it('has entitlement states', function () {
    $design = Design::factory()->create();
    $driver = Driver::factory()->create();
    $user = User::factory()->create();

    $purchase = Entitlement::factory()->forUser($user)->forProduct($design)->create();
    $free = Entitlement::factory()->forProduct($driver)->free()->create();
    $revoked = Entitlement::factory()->revoked()->create();

    expect($purchase->source)->toBe(EntitlementSource::PURCHASE)
        ->and($purchase->entitleable->is($design))->toBeTrue()
        ->and($purchase->entitleable_type)->toBe('design')
        ->and($free->source)->toBe(EntitlementSource::FREE)
        ->and($free->entitleable->is($driver))->toBeTrue()
        ->and(Entitlement::factory()->owner()->create()->source)->toBe(EntitlementSource::OWNER)
        ->and(Entitlement::factory()->adminGrant()->create()->source)->toBe(EntitlementSource::ADMIN_GRANT)
        ->and($revoked->isActive())->toBeFalse()
        ->and(Entitlement::active()->whereKey($revoked->id)->exists())->toBeFalse()
        ->and($design->entitlements)->toHaveCount(1)
        ->and($user->entitlements)->toHaveCount(1);

    // one entitlement per user and product
    expect(fn () => Entitlement::factory()->forUser($user)->forProduct($design)->create())
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('has stripe event and banner factories', function () {
    expect(StripeEvent::factory()->create()->isProcessed())->toBeFalse()
        ->and(StripeEvent::factory()->processed()->create()->isProcessed())->toBeTrue()
        ->and(StripeEvent::factory()->create()->id)->toStartWith('evt_')
        ->and(Banner::factory()->inactive()->create()->is_active)->toBeFalse()
        ->and(Banner::activeForLocation('front_page')->count())->toBeGreaterThanOrEqual(1) // from the demo data
        ->and(Banner::factory()->at('design_page')->create()->location)->toBe('design_page');
});

it('stores one design component per band', function () {
    $design = Design::factory()->create();
    $a = DesignDriver::factory()->for($design)->create(['position' => 'LF']);
    $b = DesignDriver::factory()->for($design)->create(['position' => 'HF']);

    expect($design->components)->toHaveCount(2)
        ->and($a->id)->not->toBeNull()
        ->and($a->driver->designs)->toHaveCount(1)
        ->and($b->design->is($design))->toBeTrue();
});
