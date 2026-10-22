<?php

use App\Enums\FulfillmentMode;
use App\Enums\ListingCategory;
use App\Enums\ListingChannel;
use App\Enums\ListingCondition;
use App\Enums\ListingStatus;
use App\Enums\OrderStatus;
use App\Http\Livewire\DriverFrequencyResponseViewer;
use App\Http\Livewire\FrequencyResponseViewer;
use App\Models\Banner;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Design;
use App\Models\Driver;
use App\Models\Entitlement;
use App\Models\Listing;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| The test database is migrated and seeded once before the first test (tests/bootstrap.php), in the
| "testing" environment, so the demo data is there. These tests read the real private disk, where
| that seeding wrote its files, and look at the dataset as a whole.
*/

it('creates the demo users', function () {
    $admin = User::where('email', 'admin@sdlabs.test')->firstOrFail();

    expect($admin->isPlatformSeller())->toBeTrue()
        ->and(Hash::check('password', $admin->password))->toBeTrue()
        ->and(User::where('email', 'like', 'marcus@%')->orWhere('email', 'like', 'priya@%')->orWhere('email', 'like', 'tomas@%')->count())->toBe(3)
        ->and(User::where('email', 'like', 'buyer%@sdlabs.test')->count())->toBe(5)
        ->and(User::where('email', 'like', 'shop%@sdlabs.test')->count())->toBe(2);

    foreach (['shop1@sdlabs.test', 'shop2@sdlabs.test'] as $email) {
        $shop = User::where('email', $email)->firstOrFail();
        expect($shop->canReceivePayouts())->toBeTrue()
            ->and($shop->sellerProfile->stripe_account_id)->toStartWith('acct_')
            ->and($shop->listings()->count())->toBeGreaterThan(3);
    }

    expect(SellerProfile::onboarded()->count())->toBeGreaterThanOrEqual(2);
});

it('has drivers in every category with measurement files and public snapshots', function () {
    $drivers = Driver::with('snapshots')->get();

    expect($drivers->count())->toBeGreaterThanOrEqual(20)
        ->and($drivers->pluck('category')->unique()->count())->toBe(count(App\Enums\DriverCategoryEnum::cases()) - 1); // all but "Other"

    foreach ($drivers->filter(fn ($d) => $d->frequency_files) as $driver) {
        expect(Storage::disk('private')->exists($driver->frequency_files[0]))->toBeTrue()
            ->and(Storage::disk('private')->exists($driver->impedance_files[0]))->toBeTrue()
            ->and($driver->snapshots)->toHaveCount(1)
            ->and(Storage::disk('private')->exists($driver->snapshots->first()->download_path))->toBeTrue()
            ->and($driver->factory_specs)->toHaveKeys(['Fs', 'Qts', 'Qes', 'Qms', 'Vas', 'Re', 'Le', 'Sd', 'BL', 'Xmax', 'SPL'])
            ->and($driver->card_image[0])->toStartWith('drivers/'.$driver->storage_key.'/');
    }

    // public images only on the public disk, source files only on the private one
    foreach ($drivers as $driver) {
        expect(Storage::disk('public')->exists($driver->card_image[0]))->toBeTrue();
        foreach ($driver->frequency_files ?? [] as $path) {
            expect(Storage::disk('public')->exists($path))->toBeFalse();
        }
    }
});

it('has designs of every category with components and built snapshots', function () {
    $designs = Design::with(['snapshots', 'components.driver'])->get();

    expect($designs->count())->toBeGreaterThanOrEqual(10)
        ->and($designs->pluck('category')->unique()->count())->toBe(count(App\Enums\DesignCategoryEnum::cases()))
        ->and($designs->where('price_cents', 0)->count())->toBeGreaterThan(2)
        ->and($designs->where('price_cents', '>', 0)->count())->toBeGreaterThan(2);

    foreach ($designs as $design) {
        expect($design->components)->not->toBeEmpty()
            ->and($design->snapshots)->not->toBeEmpty()
            ->and($design->frd_files)->not->toBeEmpty();

        foreach ($design->snapshots as $snapshot) {
            $path = Storage::disk('private')->path($snapshot->download_path);
            expect($snapshot->file_size)->toBe(filesize($path));
            $zip = new ZipArchive();
            expect($zip->open($path))->toBeTrue();
            expect($zip->locateName('manifest.json'))->not->toBeFalse()
                ->and($zip->locateName('README.txt'))->not->toBeFalse();
            $zip->close();
        }
    }
});

it('lets every active demo design be sold, and explains the one that cannot be published', function () {
    foreach (Design::where('active', true)->get() as $design) {
        expect($design->isAvailableForPurchase())->toBeTrue($design->name.' should be for sale');
    }

    $draft = Design::where('active', false)->firstOrFail();
    expect($draft->publishBlockers())->not->toBeEmpty()
        ->and($draft->isAvailableForPurchase())->toBeFalse();
});

it('has a store: one active listing per ~12 catalogue drivers, sold by the demo admin', function () {
    $admin = User::where('email', 'admin@sdlabs.test')->firstOrFail();
    $store = Listing::store()->with('driver')->get();

    expect($store->count())->toBeGreaterThanOrEqual(10)->toBeLessThanOrEqual(14)
        ->and($store->pluck('driver_id')->unique()->count())->toBe($store->count()) // one per driver
        ->and($store->every(fn ($l) => $l->seller_id === $admin->id))->toBeTrue()
        ->and($store->every(fn ($l) => $l->category === ListingCategory::DRIVER && $l->condition === ListingCondition::NEW))->toBeTrue()
        // own stock for the plain ones; a dropshipped product has none (a supplier ships it), a hybrid one a few units
        ->and($store->every(fn ($l) => $l->status === ListingStatus::ACTIVE && match ($l->fulfillment) {
            FulfillmentMode::STOCK => $l->quantity >= 5 && $l->quantity <= 40,
            FulfillmentMode::DROPSHIP => $l->quantity === 0,
            FulfillmentMode::STOCK_THEN_DROPSHIP => $l->quantity >= 1 && $l->quantity <= 40,
        }))->toBeTrue()
        ->and($store->every(fn ($l) => $l->price_cents > 0 && $l->ships_from_country === strtoupper(config('marketplace.default_country', 'US'))))->toBeTrue()
        ->and($store->every(fn ($l) => $l->shipping_domestic_cents > 0 && $l->shipping_international_cents > $l->shipping_domestic_cents))->toBeTrue();

    foreach ($store as $listing) {
        expect($listing->driver->storeListing()?->is($listing))->toBeTrue()
            ->and($listing->channelRuleViolations())->toBe([])
            ->and($listing->isAvailableForPurchase())->toBeTrue();
    }

    // none of it leaks into the marketplace
    expect(Listing::marketplace()->whereIn('id', $store->pluck('id'))->count())->toBe(0);
});

it('has marketplace listings: kits, completed speakers and used gear in several states, all within the rules', function () {
    $listings = Listing::marketplace()->get();
    $byCategory = $listings->groupBy(fn ($l) => $l->category->value);

    expect($listings->count())->toBeGreaterThanOrEqual(14)->toBeLessThanOrEqual(18)
        ->and($byCategory['kit']->count())->toBeGreaterThanOrEqual(3)
        ->and($byCategory['completed_speaker']->count())->toBeGreaterThanOrEqual(2)
        ->and($byCategory['used_gear']->count())->toBeGreaterThanOrEqual(5)
        ->and($byCategory->keys()->sort()->values()->all())->toBe(['completed_speaker', 'kit', 'used_gear'])
        // some kits / speakers are built from site designs, some used gear is a catalogue driver
        ->and($byCategory['kit']->whereNotNull('design_id')->count())->toBeGreaterThanOrEqual(2)
        ->and($byCategory['completed_speaker']->whereNotNull('design_id')->count())->toBeGreaterThanOrEqual(2)
        ->and($byCategory['used_gear']->whereNotNull('driver_id')->count())->toBeGreaterThanOrEqual(4)
        ->and($listings->filter->isNew()->count())->toBeGreaterThan(3)
        ->and($listings->filter->isUsed()->count())->toBeGreaterThan(3)
        ->and($listings->where('units_per_lot', 2)->count())->toBeGreaterThan(1)
        ->and($listings->whereNull('shipping_international_cents')->count())->toBeGreaterThan(1)
        ->and($listings->pluck('status')->map->value->unique()->sort()->values()->all())->toContain('active', 'draft', 'paused', 'sold_out')
        ->and($listings->pluck('condition')->unique()->count())->toBeGreaterThanOrEqual(4);

    foreach ($listings as $listing) {
        expect($listing->channelRuleViolations())->toBe([], $listing->title)
            ->and($listing->category->channel())->toBe(ListingChannel::MARKETPLACE)
            ->and($listing->photos)->not->toBeEmpty()
            ->and(Storage::disk('public')->exists($listing->photos[0]))->toBeTrue()
            ->and($listing->photos[0])->toStartWith('listings/'.$listing->uuid.'/');

        if ($listing->category === ListingCategory::USED_GEAR) {
            expect($listing->condition)->not->toBe(ListingCondition::NEW);
        }

        if ($listing->design_id !== null) {
            expect($listing->design)->not->toBeNull()->and($listing->category)->not->toBe(ListingCategory::USED_GEAR);
        }
    }
});

it('only lets orders, carts and entitlements point at things that can be bought', function () {
    foreach (OrderItem::with('purchasable')->get() as $item) {
        expect($item->purchasable_type)->toBeIn(['design', 'listing'])
            ->and($item->purchasable)->not->toBeNull($item->title);
    }

    foreach (CartItem::with('purchasable')->get() as $item) {
        expect($item->purchasable_type)->toBeIn(['design', 'listing'])
            ->and($item->purchasable)->not->toBeNull();
    }

    foreach (Entitlement::with('entitleable')->get() as $entitlement) {
        expect($entitlement->entitleable)->toBeInstanceOf(Design::class);
    }

    // SDLabs' own sales are in the data: awaiting shipment (an admin ships it) and already shipped
    $adminOrders = SellerOrder::whereHas('seller', fn ($q) => $q->where('email', 'admin@sdlabs.test'))->get();
    expect($adminOrders->pluck('status')->map->value->all())->toContain('awaiting_shipment', 'shipped');
});

it('has entitlements, carts and orders in several states, plus reviews and banners', function () {
    expect(Order::pluck('status')->map->value->unique()->sort()->values()->all())
        ->toBe(['cancelled', 'completed', 'paid', 'partially_refunded', 'pending', 'refunded'])
        ->and(Entitlement::whereNull('revoked_at')->count())->toBeGreaterThan(3)
        ->and(Entitlement::whereNotNull('revoked_at')->count())->toBeGreaterThanOrEqual(1)
        ->and(Cart::count())->toBeGreaterThanOrEqual(3)
        ->and(Design::first()->numberOfReviews())->toBeGreaterThan(0)
        ->and(Banner::count())->toBeGreaterThanOrEqual(3);

    foreach (Order::with('sellerOrders', 'items')->get() as $order) {
        expect($order->total_cents)->toBe($order->subtotal_cents + $order->shipping_cents + $order->tax_cents)
            ->and($order->items->sum('line_total_cents'))->toBe($order->subtotal_cents)
            ->and($order->sellerOrders->sum('subtotal_cents'))->toBe($order->subtotal_cents);
    }
});

it('draws real curves in the viewers: the design sum is flat and a driver shows its own response', function () {
    $design = Design::where('name', 'Harbor 2')->firstOrFail();
    $viewer = Livewire::test(FrequencyResponseViewer::class, ['design' => $design]);

    expect($viewer->get('chartData'))->toHaveCount(2);

    // the complex sum of the two ways is flat through the crossover region (see the crossover points in the description)
    $crossover = $design->components->first()->high_frequency;
    $sum = collect($viewer->get('summedResponse'))->filter(fn ($p) => $p['x'] >= $crossover / 1.5 && $p['x'] <= $crossover * 1.5);
    expect($sum->count())->toBeGreaterThan(10);
    foreach ($sum as $point) {
        expect($point['y'])->toEqualWithDelta(85.0, 1.0);
    }

    $driver = Driver::where('model', 'A16-W')->firstOrFail();
    $driverViewer = Livewire::test(DriverFrequencyResponseViewer::class, ['driver' => $driver]);
    expect($driverViewer->get('chartData'))->toHaveCount(1)
        ->and(count($driverViewer->get('chartData')[0]['data']))->toBeGreaterThan(300);
});

it('writes driver specs that match the curves', function () {
    $driver = Driver::where('model', 'A16-W')->firstOrFail();
    $specs = $driver->factory_specs;
    $rows = App\Support\SyntheticMeasurement::parse(Storage::disk('private')->get($driver->frequency_files[0]));
    $z = App\Support\SyntheticMeasurement::parse(Storage::disk('private')->get($driver->impedance_files[0]));

    // level at fs = SPL + 20 log10(Qts)
    $atFs = collect($rows)->sortBy(fn ($r) => abs($r[0] - (float) $specs['Fs']))->first();
    expect($atFs[1])->toEqualWithDelta((float) $specs['SPL'] + 20 * log10((float) $specs['Qts']), 0.4);

    // impedance peak at Fs = Re (1 + Qms / Qes)
    $peak = collect($z)->filter(fn ($r) => $r[0] > 20 && $r[0] < 100)->sortByDesc(1)->first();
    expect($peak[0])->toEqualWithDelta((float) $specs['Fs'], 2.5)
        ->and($peak[1])->toEqualWithDelta((float) $specs['Re'] * (1 + (float) $specs['Qms'] / (float) $specs['Qes']), 1.0);
});

it('does nothing when the demo data is already there', function () {
    $users = User::count();
    $designs = Design::count();

    $this->seed(DemoSeeder::class);

    expect(User::count())->toBe($users)->and(Design::count())->toBe($designs);
});

it('only seeds demo data in local, demo and testing environments', function () {
    $this->app['env'] = 'production';
    $users = User::count();
    // the admin owns listings and orders, so rename instead of delete: it would be re-seeded if the guard did not hold
    User::where('email', 'admin@sdlabs.test')->update(['email' => 'admin-renamed@sdlabs.test']);

    app(DemoSeeder::class)->run();

    expect(User::where('email', 'admin@sdlabs.test')->exists())->toBeFalse()
        ->and(User::count())->toBe($users);
});

it('has problem reports in every state on member orders, with payout states that fit', function () {
    $issues = App\Models\OrderIssue::with(['sellerOrder.order', 'messages'])->get();

    expect($issues->pluck('status')->map->value->sort()->values()->all())->toBe(['escalated', 'offer_pending', 'open', 'resolved']);

    foreach ($issues as $issue) {
        $so = $issue->sellerOrder;

        expect($issue->buyer_id)->toBe($so->order->user_id)
            ->and($issue->seller_id)->toBe($so->seller_id)
            ->and($issue->messages->isNotEmpty())->toBeTrue()
            ->and($issue->open_seller_order_id)->toBe($issue->isUnresolved() ? $so->id : null);

        if ($issue->isUnresolved()) {
            // an unresolved case holds the payout: nothing was transferred, the parcel is still within its delay
            expect($so->isTransferred())->toBeFalse()
                ->and(app(App\Services\Marketplace\PayoutService::class)->holdReason($so))->toBe('issue');
        }
    }

    $resolved = $issues->firstWhere('status', App\Enums\IssueStatus::RESOLVED);
    $so = $resolved->sellerOrder;
    expect($resolved->resolution)->toBe(App\Enums\IssueResolution::PARTIAL_REFUND)
        ->and($so->refunded_cents)->toBe($resolved->refunded_cents)
        ->and($so->order->status)->toBe(App\Enums\OrderStatus::PARTIALLY_REFUNDED)
        // paid out when the 14 days after shipping ended, reduced by the refund
        ->and($so->isTransferred())->toBeTrue()
        ->and($so->transferred_at->equalTo($so->shipped_at->copy()->addDays(14)))->toBeTrue()
        // the fee is kept on a partial refund; the payout is what is left after the fee and the refund
        ->and($so->platform_fee_cents)->toBe((int) round($so->subtotal_cents * config('marketplace.fee_bps') / 10000))
        ->and($so->payout_cents)->toBe($so->subtotal_cents + $so->shipping_cents - $so->platform_fee_cents - $so->refunded_cents);

    // the one with an internal note keeps it away from the public thread
    $escalated = $issues->firstWhere('status', App\Enums\IssueStatus::ESCALATED);
    expect($escalated->messages->where('internal', true))->toHaveCount(1)
        ->and($escalated->publicMessages()->count())->toBe($escalated->messages->count() - 1);
});

it('adds the demo problem reports to a demo site that was seeded before they existed', function () {
    $before = App\Models\OrderIssue::count();
    $orders = App\Models\Order::count();
    App\Models\OrderIssue::query()->delete(); // as on a dev server seeded before R2

    $this->seed(DemoSeeder::class);

    expect(App\Models\OrderIssue::count())->toBe($before)
        ->and(App\Models\Order::count())->toBe($orders + 4)
        ->and(User::where('email', 'admin@sdlabs.test')->count())->toBe(1);

    // and only once
    $this->seed(DemoSeeder::class);
    expect(App\Models\OrderIssue::count())->toBe($before)->and(App\Models\Order::count())->toBe($orders + 4);
});
