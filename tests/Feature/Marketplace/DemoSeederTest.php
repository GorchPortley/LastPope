<?php

use App\Enums\ListingCondition;
use App\Enums\OrderStatus;
use App\Http\Livewire\DriverFrequencyResponseViewer;
use App\Http\Livewire\FrequencyResponseViewer;
use App\Models\Banner;
use App\Models\Cart;
use App\Models\Design;
use App\Models\Driver;
use App\Models\Entitlement;
use App\Models\Listing;
use App\Models\Order;
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

it('has drivers in every category with measurement files and data packs', function () {
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

it('has listings: new and used, pairs, domestic-only and in several states', function () {
    $listings = Listing::all();

    expect($listings->count())->toBeGreaterThanOrEqual(15)
        ->and($listings->filter->isNew()->count())->toBeGreaterThan(3)
        ->and($listings->filter->isUsed()->count())->toBeGreaterThan(3)
        ->and($listings->where('units_per_lot', 2)->count())->toBeGreaterThan(1)
        ->and($listings->whereNull('shipping_international_cents')->count())->toBeGreaterThan(1)
        ->and($listings->pluck('status')->map->value->unique()->sort()->values()->all())->toContain('active', 'draft', 'paused', 'sold_out')
        ->and($listings->pluck('condition')->unique()->count())->toBeGreaterThanOrEqual(4)
        ->and($listings->whereNotNull('driver_id')->count())->toBeGreaterThan(5);

    foreach ($listings as $listing) {
        expect($listing->photos)->not->toBeEmpty()
            ->and(Storage::disk('public')->exists($listing->photos[0]))->toBeTrue()
            ->and($listing->photos[0])->toStartWith('listings/'.$listing->uuid.'/');
    }
});

it('has entitlements, carts and orders in several states, plus reviews and banners', function () {
    expect(Order::pluck('status')->map->value->unique()->sort()->values()->all())
        ->toBe(['cancelled', 'completed', 'paid', 'pending', 'refunded'])
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
    User::where('email', 'admin@sdlabs.test')->delete(); // would be re-created if the guard did not hold

    app(DemoSeeder::class)->run();

    expect(User::where('email', 'admin@sdlabs.test')->exists())->toBeFalse()
        ->and(User::count())->toBeLessThan($users);
});
