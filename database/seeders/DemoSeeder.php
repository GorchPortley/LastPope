<?php

namespace Database\Seeders;

use App\Enums\EntitlementSource;
use App\Enums\ListingCategory;
use App\Enums\ListingChannel;
use App\Enums\ListingCondition;
use App\Enums\ListingStatus;
use App\Models\Address;
use App\Models\Banner;
use App\Models\Cart;
use App\Models\Design;
use App\Models\DesignDriver;
use App\Models\Driver;
use App\Models\Listing;
use App\Models\Order;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Snapshots\DesignSnapshotBuilder;
use App\Services\Snapshots\DriverSnapshotBuilder;
use App\Services\Forum\ForumClient;
use App\Support\PlaceholderImage;
use App\Support\SyntheticMeasurement;
use Database\Factories\Support\OrderBuilder;
use Database\Seeders\Demo\DemoCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * A rich demo/dev dataset: users in every role, drivers with synthetic (but physically consistent)
 * FRD/ZMA measurements, designs with components and built snapshots, listings, entitlements,
 * carts, orders in several states, reviews and banners. Login: admin@sdlabs.test / password
 * (every demo user has the password "password", or DEMO_PASSWORD when that is set).
 *
 * Listings come in two channels: a dozen STORE listings (SDLabs sells new catalogue drivers; seller = the
 * demo admin) and a marketplace of kits, completed speakers and used gear sold between members. The seeder
 * finishes with `forum:seed-demo` when the forum is switched on and reachable.
 *
 * Only runs in local, demo and testing environments. Storage keys and listing UUIDs are derived
 * from the product slug, so re-seeding overwrites the same folders instead of piling up new ones.
 * Uses no Faker, so it also works in images built without dev dependencies.
 */
class DemoSeeder extends Seeder
{
    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, array{model: Driver, spec: array<string, mixed>|null}> */
    private array $drivers = [];

    /** @var array<string, Design> */
    private array $designs = [];

    /** @var array<string, Listing> */
    private array $listings = [];

    public function run(): void
    {
        if (! app()->environment(['local', 'demo', 'testing'])) {
            $this->command?->warn('DemoSeeder only runs in the local, demo and testing environments.');

            return;
        }

        if (User::where('email', 'admin@sdlabs.test')->exists()) {
            $this->command?->info('Demo data is already present.');
            $this->seedForum();

            return;
        }

        DB::transaction(function () {
            $this->seedUsers();
            $this->seedSiteContent();
            $this->seedDrivers();
            $this->seedDesigns();
            $this->seedListings();
            $this->seedReviews();
            $this->seedBanners();
            $this->seedCommerce();
        });

        $this->seedForum();
    }

    /**
     * Threads and replies for the demo content, when the forum is switched on and answering. It is idempotent,
     * so it also runs when the demo data was seeded before. Otherwise say how to do it later.
     */
    private function seedForum(): void
    {
        if (config('forum.enabled')) {
            $status = app(ForumClient::class)->checkConnection();

            if ($status['reachable'] && $status['authorized']) {
                $this->command ? $this->command->call('forum:seed-demo') : Artisan::call('forum:seed-demo');

                return;
            }
        }

        $this->command?->info('Run `php artisan forum:setup && php artisan forum:seed-demo` once the forum is up.');
    }

    // --- Users ---------------------------------------------------------------------------------

    private function seedUsers(): void
    {
        $password = Hash::make(config('marketplace.demo_password'));

        foreach (DemoCatalog::users() as $key => $data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'username' => $data['username'],
                'password' => $password,
                'verified' => 1,
                'avatar' => 'demo/default.png',
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            if (($data['role'] ?? null) === 'admin') {
                $user->syncRoles(['admin']);
            }

            if (isset($data['shop'])) {
                SellerProfile::create($data['shop'] + [
                    'user_id' => $user->id,
                    'stripe_account_id' => 'acct_demo'.Str::lower(Str::random(12)),
                    'charges_enabled' => true,
                    'payouts_enabled' => true,
                    'details_submitted' => true,
                ]);
            }

            if (str_starts_with($key, 'buyer')) {
                Address::create([
                    'user_id' => $user->id,
                    'name' => $data['name'],
                    'line1' => (100 + crc32($key) % 900).' Maple Street',
                    'city' => 'Springfield',
                    'state' => 'OH',
                    'postal_code' => '45501',
                    'country' => 'US',
                    'phone' => '555-0100',
                    'is_default' => true,
                ]);
            }

            $this->users[$key] = $user;
        }
    }

    /** The sample changelog entry and the admin's profile text that the legacy Wave seeders used to insert. */
    private function seedSiteContent(): void
    {
        DB::table('changelogs')->insert([
            'title' => 'SDLabs Beta 1.0 Released',
            'description' => 'We have just released the first beta version of SDLabs.cc. Click here to learn more!',
            'body' => '<p>Thanks for being here. Make sure to stay up-to-date on our latest releases: marketplace, payouts and more are coming.</p>',
            'created_at' => now()->subMonths(2),
            'updated_at' => now()->subMonths(2),
        ]);

        DB::table('profile_key_values')->insert([
            'type' => 'text_area',
            'keyvalue_id' => $this->users['admin']->id,
            'keyvalue_type' => 'users',
            'key' => 'about',
            'value' => 'Hello, I am the admin user.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // --- Drivers -------------------------------------------------------------------------------

    private function seedDrivers(): void
    {
        $seed = 0;

        foreach (DemoCatalog::drivers() as $slug => $def) {
            $seed++;
            $key = $this->key('driver', $slug);
            $spec = isset($def['measure']) ? SyntheticMeasurement::driverSpec($def['measure']) : null;

            $files = ['frequency_files' => [], 'impedance_files' => [], 'other_files' => []];
            if ($spec) {
                $title = $def['brand'].' '.$def['model'];
                $files['frequency_files'] = [$this->writePrivate("drivers/{$key}/Frequency/{$slug}-on-axis.frd", SyntheticMeasurement::driverFrd($spec, null, $title))];
                $files['impedance_files'] = [$this->writePrivate("drivers/{$key}/Impedance/{$slug}.zma", SyntheticMeasurement::driverZma($spec, null, $title))];
            }

            $image = $this->writePublic(
                "drivers/{$key}/Attachments/Display Images/{$slug}.jpg",
                PlaceholderImage::driver($seed, $spec['type'] ?? 'woofer'),
            );

            /** @var Driver $driver */
            $driver = Model::withoutEvents(fn () => Driver::create([
                'user_id' => $this->users[$def['owner']]->id,
                'storage_key' => $key,
                'brand' => $def['brand'],
                'model' => $def['model'],
                'tag' => $def['tag'] ?? null,
                'active' => true,
                'category' => $def['category'],
                'size' => $def['size'],
                'impedance' => $def['impedance'],
                'power' => $def['power'],
                'msrp_cents' => $def['msrp_cents'],
                'link' => 'https://example.com/drivers/'.$slug,
                'summary' => ($def['tag'] ?? $def['model']).' from '.$def['brand'].'.',
                'description' => '<p>'.e($def['brand'].' '.$def['model']).' is a '.e(Str::lower($def['category'])).' with a nominal impedance of '.$def['impedance'].' ohm. The Thiele/Small parameters and the measured curves were generated from one model, so they agree with each other.</p>',
                'factory_specs' => $spec ? SyntheticMeasurement::factorySpecs($spec) : null,
                'card_image' => [$image],
                'official' => $def['official'],
            ] + $files));

            if ($spec) {
                app(DriverSnapshotBuilder::class)->build($driver, 'v1.0');
            }

            $this->drivers[$slug] = ['model' => $driver, 'spec' => $spec];
        }
    }

    // --- Designs -------------------------------------------------------------------------------

    private function seedDesigns(): void
    {
        $seed = 100;

        foreach (DemoCatalog::designs() as $slug => $def) {
            $seed++;
            $key = $this->key('design', $slug);
            $ways = $this->designWays($def);
            $count = count($ways);

            $previews = [];
            foreach ($ways as $i => $way) {
                $label = $way['position'].'-'.$this->drivers[$way['driver']]['model']->model;
                $previews[] = $this->writePrivate(
                    "designs/{$key}/Attachments/Display Responses/{$label}.frd",
                    SyntheticMeasurement::wayFrd($way['spec'], $way['high_pass'], $way['low_pass'], $def['name'].' '.$label),
                );
            }

            $card = $this->writePublic(
                "designs/{$key}/Attachments/Display Images/cover.jpg",
                PlaceholderImage::design($seed, min($count, 3), $count === 1 && $this->drivers[$ways[0]['driver']]['spec']['type'] === 'subwoofer'),
            );

            $design = Model::withoutEvents(fn () => Design::create([
                'user_id' => $this->users[$def['owner']]->id,
                'storage_key' => $key,
                'name' => $def['name'],
                'tag' => $def['tag'],
                'card_image' => [$card],
                'active' => $def['active'],
                'category' => $def['category'],
                'price_cents' => $def['price_cents'],
                'build_cost_cents' => $def['build_cost_cents'],
                'impedance' => $def['impedance'],
                'power' => $def['power'],
                'summary' => '<p>'.e($def['blurb']).'</p>',
                'description' => $this->designDescription($def, $ways),
                'bill_of_materials' => $def['bom'],
                'frd_files' => $previews,
                'enclosure_files' => [$this->writePrivate("designs/{$key}/Enclosure/cut-list.csv", $this->cutList($def))],
                'electronic_files' => $count > 1 ? [$this->writePrivate("designs/{$key}/Electronics/crossover-points.txt", $this->crossoverNotes($def, $ways))] : [],
                'design_other_files' => [$this->writePrivate("designs/{$key}/Other Files/build-notes.txt", "Build notes for {$def['name']}\n\n{$def['blurb']}\n")],
                'official' => $def['official'] ?? false,
            ]));

            foreach ($ways as $i => $way) {
                $driver = $this->drivers[$way['driver']]['model'];
                $spec = $way['spec'];
                $position = strtoupper($way['position']);
                $title = $driver->fullName();

                DesignDriver::create([
                    'design_id' => $design->id,
                    'driver_id' => $driver->id,
                    'position' => $way['position'],
                    'quantity' => $way['quantity'],
                    'low_frequency' => $way['high_pass'] ?? round($spec['fs']),
                    'high_frequency' => $way['low_pass'] ?? 20000,
                    'air_volume' => $way['air_volume'],
                    'description' => '<p>'.e($title).' in the '.e($position).' band'.($way['high_pass'] ? ', high-passed at '.$way['high_pass'].' Hz' : '').($way['low_pass'] ? ', low-passed at '.$way['low_pass'].' Hz' : '').'.</p>',
                    'specifications' => SyntheticMeasurement::factorySpecs($spec),
                    'frequency_files' => [$this->writePrivate("designs/{$key}/{$position}/Frequency/{$way['driver']}-on-axis.frd", SyntheticMeasurement::driverFrd($spec, null, $title))],
                    'impedance_files' => [$this->writePrivate("designs/{$key}/{$position}/Impedance/{$way['driver']}.zma", SyntheticMeasurement::driverZma($spec, null, $title))],
                    'other_files' => [],
                ]);
            }

            $design->unsetRelation('components');

            foreach ($def['snapshots'] as $version) {
                app(DesignSnapshotBuilder::class)->build($design->refresh(), $version);
            }

            $this->designs[$slug] = $design;
        }
    }

    /**
     * The bands of a design, low to high, with the driver spec and the crossover points between them.
     *
     * @return array<int, array{position: string, driver: string, quantity: int, air_volume: float, spec: array<string, mixed>, high_pass: float|null, low_pass: float|null}>
     */
    private function designWays(array $def): array
    {
        $ways = [];
        foreach ($def['components'] as [$position, $driverSlug, $quantity, $airVolume]) {
            $ways[] = [
                'position' => $position,
                'driver' => $driverSlug,
                'quantity' => $quantity,
                'air_volume' => $airVolume,
                'spec' => $this->drivers[$driverSlug]['spec'],
                'high_pass' => null,
                'low_pass' => null,
            ];
        }

        for ($i = 0; $i < count($ways) - 1; $i++) {
            [$low, $high] = [$ways[$i]['spec'], $ways[$i + 1]['spec']];
            [$min, $max] = match (true) {
                $low['type'] === 'subwoofer' => [60, 300],
                $high['type'] === 'tweeter' => [1200, 5000],
                $high['type'] === 'midrange' => [200, 1500],
                default => [200, 2500],
            };

            $crossover = SyntheticMeasurement::bestCrossover($low, $high, $min, $max);
            $ways[$i]['low_pass'] = $crossover;
            $ways[$i + 1]['high_pass'] = $crossover;
        }

        return $ways;
    }

    private function designDescription(array $def, array $ways): string
    {
        $html = '<p>'.e($def['blurb']).'</p><h2>Drivers</h2><ul>';
        foreach ($ways as $way) {
            $driver = $this->drivers[$way['driver']]['model'];
            $html .= '<li>'.e($way['position']).': '.$way['quantity'].' x '.e($driver->fullName()).'</li>';
        }
        $html .= '</ul>';

        if (count($ways) > 1) {
            $html .= '<h2>Crossover</h2><p>Linkwitz-Riley fourth-order slopes at '
                .implode(' Hz and ', array_filter(array_map(fn ($w) => $w['low_pass'], $ways))).' Hz.</p>';
        }

        return $html.'<h2>Measurements</h2><p>The response previews on this page are the on-axis curves of each band; the thick line is their complex sum.</p>';
    }

    private function cutList(array $def): string
    {
        return "part,qty,material,width_mm,height_mm\nbaffle,1,18 mm plywood,220,360\nside,2,18 mm plywood,280,360\ntop/bottom,2,18 mm plywood,220,280\nback,1,18 mm plywood,220,360\n";
    }

    private function crossoverNotes(array $def, array $ways): string
    {
        $lines = ['Crossover points for '.$def['name'], ''];
        foreach ($ways as $way) {
            $lines[] = sprintf('%-4s %-18s high-pass: %-8s low-pass: %s', $way['position'], $this->drivers[$way['driver']]['model']->fullName(),
                $way['high_pass'] ? $way['high_pass'].' Hz' : '-', $way['low_pass'] ? $way['low_pass'].' Hz' : '-');
        }

        return implode("\n", $lines)."\n";
    }

    // --- Listings ------------------------------------------------------------------------------

    private function seedListings(): void
    {
        $seed = 200;
        $country = strtoupper((string) config('marketplace.default_country', 'US'));
        $admin = $this->users['admin'];

        // The store: SDLabs sells new catalogue drivers, one active store listing per driver.
        foreach (DemoCatalog::storeListings() as $def) {
            $seed++;
            $driver = $this->drivers[$def['driver']]['model'];
            $title = $driver->fullName().' - '.($driver->tag ?: 'new driver');

            $this->listings['store-'.$def['driver']] = $this->createListing([
                'uuid' => $this->key('listing', 'store-'.$def['driver']),
                'channel' => ListingChannel::STORE,
                'category' => ListingCategory::DRIVER,
                'seller_id' => $admin->id,
                'driver_id' => $driver->id,
                'design_id' => null,
                'title' => $title,
                'condition' => ListingCondition::NEW,
                'description' => $title.".\n\nNew, from SDLabs stock, in the original packaging. The measurements and T/S parameters are on the driver page.",
                'price_cents' => $def['price'],
                'quantity' => $def['quantity'],
                'units_per_lot' => 1,
                'ships_from_country' => $country,
                'shipping_domestic_cents' => $def['domestic'],
                'shipping_international_cents' => $def['international'],
                'additional_item_shipping_cents' => $def['additional'],
                'handling_days' => 2,
                'returns_accepted' => true,
                'return_policy' => '30 days, buyer pays return shipping.',
                'status' => ListingStatus::ACTIVE,
            ], $seed, $def['kind'], false);
        }

        // The marketplace: kits, completed speakers and used gear sold between members.
        foreach (DemoCatalog::listings() as $def) {
            $seed++;
            $used = $def['condition']->isUsed();
            $what = match ($def['category']) {
                ListingCategory::KIT => 'A kit of parts.',
                ListingCategory::COMPLETED_SPEAKER => 'Built and tested by the seller.',
                default => 'Tested and measured before listing.',
            };

            $this->listings[$def['slug']] = $this->createListing([
                'uuid' => $this->key('listing', $def['slug']),
                'channel' => ListingChannel::MARKETPLACE,
                'category' => $def['category'],
                'seller_id' => $this->users[$def['seller']]->id,
                'driver_id' => $def['driver'] ? $this->drivers[$def['driver']]['model']->id : null,
                'design_id' => $def['design'] ? $this->designs[$def['design']]->id : null,
                'title' => $def['title'],
                'condition' => $def['condition'],
                'description' => $def['title'].".\n\n".$what.' '.($used
                    ? 'Cosmetic wear is visible in the photos.'
                    : 'Brand new and unused.')."\nPlease ask if you need more photos or measurements.",
                'price_cents' => $def['price_cents'],
                'quantity' => $def['quantity'],
                'units_per_lot' => $def['units_per_lot'],
                'ships_from_country' => $def['ships_from'],
                'shipping_domestic_cents' => $def['domestic'],
                'shipping_international_cents' => $def['international'],
                'additional_item_shipping_cents' => $def['additional'],
                'handling_days' => $def['handling_days'],
                'returns_accepted' => $def['returns'],
                'return_policy' => $def['returns'] ? ($def['return_policy'] ?? '30 days, buyer pays return shipping.') : null,
                'status' => $def['status'],
            ], $seed, $def['kind'], $used);
        }
    }

    /** Photos, then the listing itself (checked against the store / marketplace rules). */
    private function createListing(array $attributes, int $seed, string $kind, bool $used): Listing
    {
        $uuid = $attributes['uuid'];
        $photos = [];
        foreach ([1, 2] as $n) {
            $photos[] = $this->writePublic("listings/{$uuid}/photo-{$n}.jpg", PlaceholderImage::listing($seed + $n * 7, $kind, $used));
        }

        // without model events: nothing here should reach out to the forum while seeding
        $listing = Model::withoutEvents(fn () => Listing::create($attributes + [
            'slug' => Str::slug($attributes['title']),
            'photos' => $photos,
        ]));

        return $listing->validateChannelRules();
    }

    // --- Reviews, banners ----------------------------------------------------------------------

    private function seedReviews(): void
    {
        foreach (DemoCatalog::reviews() as $review) {
            $this->designs[$review['design']]->review($review['review'], $this->users[$review['user']], $review['rating'], $review['title']);
        }
    }

    private function seedBanners(): void
    {
        foreach (['front_page' => 'Spring build-off: share your design', 'design_page' => 'New: paid designs from verified designers', 'driver_page' => 'Measured drivers, free to download', 'marketplace_page' => 'Kits, finished speakers and used gear from the community'] as $location => $name) {
            $path = $this->writePublic('banners/'.$location.'.jpg', PlaceholderImage::banner(crc32($location) % 1000));

            Banner::create(['name' => $name, 'image_path' => $path, 'location' => $location, 'is_active' => true, 'priority' => 1]);
        }
    }

    // --- Entitlements, carts, orders -----------------------------------------------------------

    private function seedCommerce(): void
    {
        $u = $this->users;
        $d = $this->designs;
        $l = $this->listings;

        // Free downloads in libraries.
        foreach (['harbor-2', 'little-brook', 'meadow-full-range'] as $slug) {
            $u['buyer2']->grantEntitlement($d[$slug], EntitlementSource::FREE);
        }
        $u['buyer4']->grantEntitlement($d['echo-chamber'], EntitlementSource::FREE);
        $u['buyer5']->grantEntitlement($d['harbor-2'], EntitlementSource::ADMIN_GRANT);

        $address = fn (string $buyer) => $u[$buyer]->addresses()->first();

        // Paid, with a paid design (fulfilled instantly) and a new driver from the store waiting for an admin to ship it.
        OrderBuilder::build($this->order('buyer1', 'paid', $address('buyer1')), [$d['slate-tower'], [$l['store-a16'], 1]], 'paid');
        // Shipped: two store drivers on their way (shipped by an admin).
        OrderBuilder::build($this->order('buyer2', 'paid', $address('buyer2')), [[$l['store-h20'], 1], [$l['store-at25'], 2]], 'shipped');
        // Completed: a used driver from a member, delivered, plus a paid design.
        OrderBuilder::build($this->order('buyer3', 'completed', $address('buyer3')), [[$l['k65-used'], 1], $d['courtyard-monitor']], 'completed');
        // Waiting for payment.
        OrderBuilder::build($this->order('buyer4', 'pending', $address('buyer4')), [[$l['store-bt25'], 1]], 'pending');
        // Refunded: entitlement revoked.
        OrderBuilder::build($this->order('buyer5', 'refunded', null), [$d['courtyard-monitor']], 'refunded');
        // Cancelled checkout.
        OrderBuilder::build($this->order('buyer1', 'cancelled', null), [$d['quarry-sub-15']], 'cancelled');

        // Carts with something in them.
        $this->cart('buyer1', [[$l['store-h8'], 1], [$l['two-way-crossover-kit'], 1]]);
        $this->cart('buyer4', [[$d['atlas-four'], 1]]);
        $this->cart('buyer5', [[$l['harbor-2-kit'], 1]]);
    }

    private function order(string $buyer, string $stage, ?Address $address): Order
    {
        $paid = in_array($stage, ['paid', 'completed', 'refunded'], true);

        return Order::create([
            'user_id' => $this->users[$buyer]->id,
            'status' => $stage,
            'shipping_address' => $address?->toSnapshot(),
            'stripe_checkout_session_id' => 'cs_test_demo_'.Str::lower(Str::random(16)),
            'stripe_payment_intent_id' => $paid ? 'pi_demo_'.Str::lower(Str::random(16)) : null,
            'stripe_charge_id' => $paid ? 'ch_demo_'.Str::lower(Str::random(16)) : null,
            'paid_at' => $paid ? now()->subDays(rand(1, 20)) : null,
        ]);
    }

    /** @param array<int, array{0: \App\Contracts\Purchasable, 1: int}> $items */
    private function cart(string $buyer, array $items): void
    {
        $cart = Cart::create(['user_id' => $this->users[$buyer]->id]);

        foreach ($items as [$item, $quantity]) {
            $cart->items()->create([
                'purchasable_type' => $item->getMorphClass(),
                'purchasable_id' => $item->getKey(),
                'quantity' => $item->isDigital() ? 1 : $quantity,
                'unit_price_cents' => $item->purchasablePriceCents(),
            ]);
        }
    }

    // --- Files ---------------------------------------------------------------------------------

    /** A stable UUID per product, so the same slug always maps to the same storage folder. */
    private function key(string $type, string $slug): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'https://sdlabs.test/demo/'.$type.'/'.$slug)->toString();
    }

    private function writePrivate(string $path, string $contents): string
    {
        Storage::disk('private')->put($path, $contents);

        return $path;
    }

    private function writePublic(string $path, string $contents): string
    {
        Storage::disk('public')->put($path, $contents);

        return $path;
    }
}
