<?php

namespace Database\Seeders;

use App\Enums\EntitlementSource;
use App\Enums\FulfillmentMode;
use App\Enums\IssueReason;
use App\Enums\IssueResolution;
use App\Enums\IssueStatus;
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
use App\Models\OrderIssue;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\User;
use App\Enums\OrderStatus;
use App\Enums\SellerOrderStatus;
use App\Enums\SupplierOrderStatus;
use App\Services\Marketplace\PurchaseOrderService;
use App\Services\Marketplace\ShipmentService;
use App\Services\Marketplace\SupplierFeedSync;
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
 * R3: two suppliers (one with a CSV stock feed file, one a web portal), some store listings that a supplier ships
 * (dropship, own stock first), costs on all of them, and store orders with purchase orders in several states and
 * parcels from several sources.
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

    /** @var array<string, Supplier> */
    private array $suppliers = [];

    public function run(): void
    {
        if (! app()->environment(['local', 'demo', 'testing'])) {
            $this->command?->warn('DemoSeeder only runs in the local, demo and testing environments.');

            return;
        }

        if (User::where('email', 'admin@sdlabs.test')->exists()) {
            $this->command?->info('Demo data is already present.');
            $this->seedIssuesOnExistingData();
            $this->seedDropshippingOnExistingData();
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
            $this->seedDropshipping();
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
        // Complimentary copies of the admin's paid designs: their forum discussions are private to the buyers, so
        // `forum:seed-demo` needs somebody to hold a conversation with.
        $u['buyer3']->grantEntitlement($d['atlas-four'], EntitlementSource::ADMIN_GRANT);
        $u['buyer1']->grantEntitlement($d['stagehand-system'], EntitlementSource::ADMIN_GRANT);

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

        $this->seedIssues($address);

        // Carts with something in them.
        $this->cart('buyer1', [[$l['store-h8'], 1], [$l['two-way-crossover-kit'], 1]]);
        $this->cart('buyer4', [[$d['atlas-four'], 1]]);
        $this->cart('buyer5', [[$l['harbor-2-kit'], 1]]);
    }

    /**
     * A site seeded before problem reports existed (docs/PLAN.md 7): add the demo cases on top of its demo users and
     * listings, once. Nothing happens when it has problem reports already or when the demo catalogue is not complete.
     */
    private function seedIssuesOnExistingData(): void
    {
        if (OrderIssue::query()->exists()) {
            return;
        }

        foreach (DemoCatalog::users() as $key => $data) {
            $user = User::where('email', $data['email'])->first();
            if ($user === null) {
                return;
            }
            $this->users[$key] = $user;
        }

        foreach (['a38-used', 'h8-pair-used', 'two-way-crossover-kit', 'n17-used'] as $slug) {
            $listing = Listing::where('uuid', $this->key('listing', $slug))->first();
            if ($listing === null) {
                return;
            }
            $this->listings[$slug] = $listing;
        }

        DB::transaction(function () {
            $this->seedIssues(fn (string $buyer) => $this->users[$buyer]->addresses()->first());
        });

        $this->command?->info('Added demo problem reports.');
    }

    /**
     * "Report a problem" cases in every stage, on member-to-member orders whose payout state fits (the payout waits
     * physical_payout_delay_days after shipping, and while a case is unresolved):
     *   open            shipped 3 days ago, the buyer and the seller exchanged messages, payout held
     *   offer pending   shipped 6 days ago, the seller offered a partial refund, payout held
     *   escalated       shipped 9 days ago, the buyer asked the admins to decide, an internal note, payout held
     *   resolved        shipped 16 days ago, settled with a partial refund on day 6, payout released on day 14 (reduced)
     */
    private function seedIssues(\Closure $address): void
    {
        $u = $this->users;
        $l = $this->listings;
        $money = fn (int $cents): string => money($cents);

        // 1. Open: not as described.
        $order = $this->order('buyer2', 'paid', $address('buyer2'));
        OrderBuilder::build($order, [[$l['a38-used'], 1]], 'shipped', ['shipped_days_ago' => 3]);
        $so = $order->sellerOrders()->first();
        $issue = $this->issue($so, IssueReason::NOT_AS_DESCRIBED, IssueStatus::OPEN, daysAgo: 1);
        $this->thread($issue, [
            [$u['buyer2'], 'The listing says "tested, good condition" but the surround is cracked on one side and the dust cap has a dent. I can send photos if that helps.', null, 1.0],
            [$u['shop2'], 'Sorry about that, it was fine when I packed it. Could you send a close-up of the surround? I want to see if it happened in transit.', null, 0.8],
            [$u['buyer2'], 'Sure, here it is. The crack runs about 4 cm along the edge.', null, 0.5],
        ]);

        // 2. Offer pending: the seller offers a partial refund.
        $order = $this->order('buyer5', 'paid', $address('buyer5'));
        OrderBuilder::build($order, [[$l['h8-pair-used'], 1]], 'shipped', ['shipped_days_ago' => 6]);
        $so = $order->sellerOrders()->first();
        $issue = $this->issue($so, IssueReason::NOT_AS_DESCRIBED, IssueStatus::OFFER_PENDING, daysAgo: 4, extra: [
            'offer_type' => IssueResolution::PARTIAL_REFUND,
            'offer_cents' => 2000,
            'offered_at' => now()->subDays(2),
        ]);
        $this->thread($issue, [
            [$u['buyer5'], 'One of the two woofers has a scratched cone. The listing said "like new" for both.', null, 4.0],
            [$u['shop2'], 'You are right, I missed that one. I would rather you keep the pair than send them back.', null, 2.1],
            [$u['shop2'], 'Offer: a partial refund of '.$money(2000).'.', 'offer', 2.0],
        ]);

        // 3. Escalated: nobody agreed, the admins decide.
        $order = $this->order('buyer1', 'paid', $address('buyer1'));
        OrderBuilder::build($order, [[$l['two-way-crossover-kit'], 1]], 'shipped', ['shipped_days_ago' => 9]);
        $so = $order->sellerOrders()->first();
        $issue = $this->issue($so, IssueReason::NOT_RECEIVED, IssueStatus::ESCALATED, daysAgo: 7, extra: [
            'escalated_at' => now()->subHours(20),
            'escalated_by' => $u['buyer1']->id,
        ]);
        $this->thread($issue, [
            [$u['buyer1'], 'The tracking says delivered on Monday but there is nothing at my door and the neighbours have not seen it.', null, 7.0],
            [$u['shop1'], 'The carrier confirms delivery to your address with a photo. Please check with your household or the carrier.', null, 6.0],
            [$u['buyer1'], 'I did. Nobody has it. I would like a refund.', null, 4.0],
            [$u['buyer1'], 'Asked the SDLabs team to step in.', 'escalated', 0.8],
        ]);
        $issue->messages()->create(['author_id' => $u['admin']->id, 'body' => 'Carrier photo shows a porch that does not match the buyer\'s address. Leaning towards a refund; waiting for the carrier claim number from the seller.', 'internal' => true]);

        // 4. Resolved with a partial refund before the payout: the seller was paid the reduced amount on day 14.
        $order = $this->order('buyer3', 'completed', $address('buyer3'));
        OrderBuilder::build($order, [[$l['n17-used'], 1]], 'completed', ['shipped_days_ago' => 16]);
        $so = $order->sellerOrders()->first();
        $refund = 500;
        $so->forceFill([
            'refunded_cents' => $refund,
            'payout_cents' => $so->payout_cents - $refund,
            'stripe_refund_id' => 're_demo_'.Str::lower(Str::random(14)),
            'refunded_at' => now()->subDays(10),
        ])->save();
        $order->update(['status' => 'partially_refunded']);
        $issue = $this->issue($so, IssueReason::DAMAGED, IssueStatus::RESOLVED, daysAgo: 12, extra: [
            'offer_type' => IssueResolution::PARTIAL_REFUND,
            'offer_cents' => $refund,
            'offered_at' => now()->subDays(10),
            'resolution' => IssueResolution::PARTIAL_REFUND,
            'refunded_cents' => $refund,
            'resolved_at' => now()->subDays(10),
            'resolved_by' => $u['buyer3']->id,
        ]);
        $this->thread($issue, [
            [$u['buyer3'], 'The magnet cover has a small dent from shipping. It works, but I would like something for the cosmetic damage.', null, 12.0],
            [$u['designer2'], 'Thanks for telling me. Is '.$money($refund).' back fair?', null, 10.2],
            [$u['designer2'], 'Offer: a partial refund of '.$money($refund).'.', 'offer', 10.1],
            [$u['buyer3'], 'Accepted the offer. '.$money($refund).' is being refunded. Case closed.', 'offer_accepted', 10.0],
        ]);
    }

    /** One problem report, created as the service would have left it (timestamps in the past). */
    private function issue(\App\Models\SellerOrder $sellerOrder, IssueReason $reason, IssueStatus $status, int $daysAgo, array $extra = []): OrderIssue
    {
        $created = now()->subDays($daysAgo);

        return OrderIssue::unguarded(fn () => OrderIssue::create($extra + [
            'order_id' => $sellerOrder->order_id,
            'seller_order_id' => $sellerOrder->id,
            'buyer_id' => $sellerOrder->order->user_id,
            'seller_id' => $sellerOrder->seller_id,
            'reason' => $reason,
            'status' => $status,
            'refunded_cents' => 0,
            'recovered_cents' => 0,
            'created_at' => $created,
            'updated_at' => $created,
        ]));
    }

    /**
     * The thread: [author, text, event, days ago] lines. Photos are not attached to demo messages.
     *
     * @param  array<int, array{0: User, 1: string, 2: ?string, 3: float}>  $lines
     */
    private function thread(OrderIssue $issue, array $lines): void
    {
        $last = null;

        foreach ($lines as $i => [$author, $text, $event, $daysAgo]) {
            $at = now()->subMinutes((int) round($daysAgo * 1440));
            $issue->messages()->make(['author_id' => $author->id, 'body' => $text, 'event' => $i === 0 ? 'opened' : $event])
                ->forceFill(['created_at' => $at, 'updated_at' => $at])
                ->save();
            $last = $at;
        }

        $issue->forceFill(['last_message_at' => $last])->saveQuietly();
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

    // --- Dropshipping (R3) -----------------------------------------------------------------------

    /**
     * A demo site seeded before dropshipping existed (docs/PLAN.md 8): add the suppliers, the dropshipped store listings
     * and the store orders on top of its demo users and store listings, once. Nothing happens when it has suppliers
     * already or when the demo catalogue is not complete.
     */
    private function seedDropshippingOnExistingData(): void
    {
        if (Supplier::query()->exists()) {
            return;
        }

        foreach (DemoCatalog::users() as $key => $data) {
            $user = User::where('email', $data['email'])->first();
            if ($user === null) {
                return;
            }
            $this->users[$key] = $user;
        }

        foreach (DemoCatalog::storeListings() as $def) {
            $listing = Listing::where('uuid', $this->key('listing', 'store-'.$def['driver']))->first();
            if ($listing === null) {
                return;
            }
            $this->listings['store-'.$def['driver']] = $listing;
        }

        DB::transaction(fn () => $this->seedDropshipping());

        $this->command?->info('Added demo suppliers, dropshipped store listings and store orders with purchase orders.');
    }

    /**
     * Two suppliers, some store listings they ship, costs everywhere, the feed read once, and store orders: a paid one
     * with purchase orders to send and to confirm, a partly shipped one (own parcel and one supplier's out, one still at the
     * supplier), a fully shipped one in two parcels, and two refunded ones - one after the purchase order was sent
     * (flagged "cancel with supplier") and one before (dropped).
     */
    private function seedDropshipping(): void
    {
        foreach (DemoCatalog::suppliers() as $key => $def) {
            $feed = $def['feed'];
            unset($def['feed']);

            if (filled($def['feed_file_path'] ?? null)) {
                $csv = "sku,quantity,cost\n".collect($feed)->map(fn (array $row) => implode(',', $row))->implode("\n")."\n";
                $this->writePrivate($def['feed_file_path'], $csv);
            }

            $this->suppliers[$key] = Supplier::create($def);
        }

        $plans = DemoCatalog::dropshipping();

        foreach (DemoCatalog::storeListings() as $position => $def) {
            $listing = $this->listings['store-'.$def['driver']];
            $plan = $plans[$def['driver']] ?? null;

            $listing->forceFill([
                'cost_cents' => (int) round($def['price'] * DemoCatalog::costShare($def['driver'], $position) / 100),
                'fulfillment' => $plan['fulfillment'] ?? FulfillmentMode::STOCK->value,
                'supplier_id' => $plan ? $this->suppliers[$plan['supplier']]->id : null,
                'supplier_sku' => $plan['sku'] ?? null,
                'quantity' => $plan['quantity'] ?? $listing->quantity,
                'supplier_stock' => $plan['supplier_stock'] ?? null,
                'supplier_stock_synced_at' => isset($plan['supplier_stock']) ? now() : null,
            ])->saveQuietly();

            $listing->validateChannelRules();
        }

        // The supplier with a feed file: stock and cost come from it, as the hourly sync would do.
        foreach ($this->suppliers as $supplier) {
            if ($supplier->hasFeed()) {
                app(SupplierFeedSync::class)->sync($supplier);
            }
        }

        $this->seedDropshipOrders();
    }

    private function seedDropshipOrders(): void
    {
        $u = $this->users;
        $l = $this->listings;
        $purchaseOrders = app(PurchaseOrderService::class);
        $shipments = app(ShipmentService::class);

        $build = function (string $buyer, string $stage, array $items, int $paidDaysAgo, ?string $orderStage = null): Order {
            $order = $this->order($buyer, $orderStage ?? ($stage === 'shipped' ? 'paid' : $stage), $this->users[$buyer]->addresses()->first());
            $order->update(['paid_at' => now()->subDays($paidDaysAgo)]);
            OrderBuilder::build($order, $items, $stage);

            return $order->fresh(['sellerOrders.items', 'supplierOrders.items']);
        };

        // 1. Paid yesterday: own stock + Northwind (purchase order e-mailed automatically, confirmed) + a Harbor Pro line
        //    that own stock cannot cover (purchase order waits for an admin to place it in their portal).
        $order = $build('buyer1', 'paid', [[$l['store-k65'], 1], [$l['store-kx8'], 1], [$l['store-a30'], 3]], 1);
        $this->advancePurchaseOrder($order->supplierOrders->firstWhere('supplier_id', $this->suppliers['northwind']->id), 'confirmed', 'NW-48121');

        // 2. Paid six days ago, in three parcels: the own-stock parcel and Northwind's shipped, Harbor Pro's still to come
        //    (the order is partially shipped, no payout clock and no problem window yet).
        $order = $build('buyer2', 'paid', [[$l['store-pr8'], 2], [$l['store-h8'], 1], [$l['store-ht28'], 1], [$l['store-a30'], 3]], 6);
        $sellerOrder = $order->sellerOrders->first();
        $northwind = $order->supplierOrders->firstWhere('supplier_id', $this->suppliers['northwind']->id);
        $harbor = $order->supplierOrders->firstWhere('supplier_id', $this->suppliers['harbor']->id);

        $shipments->recordShipment($sellerOrder, null, ['carrier' => 'USPS', 'tracking_number' => '9400111899223100'.$sellerOrder->id, 'shipped_at' => now()->subDays(5)], restrictToOwn: true, notify: false);
        $this->advancePurchaseOrder($northwind, 'confirmed', 'NW-47990');
        $shipments->recordShipment(
            $sellerOrder->fresh(),
            $northwind->items->mapWithKeys(fn ($line) => [$line->order_item_id => $line->quantity])->all(),
            ['carrier' => 'UPS', 'tracking_number' => '1ZNW'.str_pad((string) $northwind->id, 12, '0', STR_PAD_LEFT), 'tracking_url' => null, 'shipped_at' => now()->subDays(2)],
            $northwind,
            notify: false,
        );
        $northwind->forceFill(['status' => SupplierOrderStatus::SHIPPED, 'shipped_at' => now()->subDays(2), 'shipping_cost_cents' => 999])->save();
        $this->advancePurchaseOrder($harbor, 'placed', 'HP-PORTAL-90412');
        $harbor->forceFill(['notes' => '['.now()->subDays(4)->format('Y-m-d H:i').'] Supplier says this is on back-order for about a week.'])->save();

        // 3. Fully shipped in two parcels (own stock first, Northwind's a day later): the order is shipped with the time of the last parcel.
        $build('buyer3', 'shipped', [[$l['store-at25'], 1], [$l['store-cd14'], 1]], 3);

        // 4. Refunded after the purchase order was sent: the purchase order is flagged "cancel with supplier".
        $order = $build('buyer5', 'paid', [[$l['store-kx8'], 1]], 4);
        $this->advancePurchaseOrder($order->supplierOrders->first(), 'confirmed', 'NW-47875');
        $this->refundSellerOrder($order->sellerOrders->first(), 'The buyer changed their mind.', $purchaseOrders);

        // 5. Refunded before the purchase order was sent: it is dropped, nothing goes to the supplier.
        $order = $build('buyer4', 'paid', [[$l['store-a30'], 3]], 2);
        $this->refundSellerOrder($order->sellerOrders->first(), 'Out of stock at the supplier.', $purchaseOrders);
    }

    /** Move a purchase order along without sending any e-mail: sent (automatically), placed by hand, or confirmed. */
    private function advancePurchaseOrder(?SupplierOrder $po, string $state, ?string $reference = null): void
    {
        if ($po === null) {
            return;
        }

        $paidAt = $po->order->paid_at ?? now();

        $po->forceFill(match ($state) {
            'placed' => ['status' => SupplierOrderStatus::SENT, 'sent_at' => $paidAt->copy()->addHours(2), 'placed_manually' => true, 'supplier_reference' => $reference],
            'confirmed' => ['status' => SupplierOrderStatus::CONFIRMED, 'sent_at' => $paidAt->copy()->addMinutes(5), 'confirmed_at' => $paidAt->copy()->addHours(3), 'supplier_reference' => $reference],
            default => ['status' => SupplierOrderStatus::SENT, 'sent_at' => $paidAt->copy()->addMinutes(5)],
        })->save();
    }

    /** What RefundService does to a store order, without Stripe: the seller order and the order are refunded, the purchase orders react. */
    private function refundSellerOrder(SellerOrder $sellerOrder, string $reason, PurchaseOrderService $purchaseOrders): void
    {
        $sellerOrder->forceFill([
            'status' => SellerOrderStatus::REFUNDED,
            'refunded_cents' => $sellerOrder->grossCents(),
            'refunded_at' => now(),
        ])->save();
        $sellerOrder->order->forceFill(['status' => OrderStatus::REFUNDED])->save();

        $purchaseOrders->handleRefund($sellerOrder->fresh(), $reason);
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
