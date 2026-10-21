<?php

namespace Database\Seeders\Demo;

use App\Enums\DesignCategoryEnum;
use App\Enums\DriverCategoryEnum;
use App\Enums\ListingCondition;
use App\Enums\ListingStatus;

/**
 * The curated content of the demo dataset: fictional brands and products, so nothing here can be
 * mistaken for a real manufacturer's data. Plain arrays, no Faker (the seeder also runs in
 * production-like "demo" images that are installed without dev dependencies).
 *
 * Driver `measure` is the input of SyntheticMeasurement::driverSpec() (null = no measurement files).
 */
final class DemoCatalog
{
    /** @return array<string, array<string, mixed>> */
    public static function users(): array
    {
        return [
            'admin' => ['name' => 'SDLabs Admin', 'email' => 'admin@sdlabs.test', 'username' => 'admin', 'role' => 'admin'],
            'designer1' => ['name' => 'Marcus Hale', 'email' => 'marcus@sdlabs.test', 'username' => 'marcushale',
                'shop' => ['shop_name' => 'Hale Acoustics', 'country' => 'US', 'bio' => 'Small-batch speaker designs, tested and tuned in a garage in Ohio.']],
            'designer2' => ['name' => 'Priya Nair', 'email' => 'priya@sdlabs.test', 'username' => 'priyanair',
                'shop' => ['shop_name' => 'Nair Designs', 'country' => 'US', 'bio' => 'Portable and desktop speakers, plus the odd used driver from my bench.']],
            'designer3' => ['name' => 'Tomas Weber', 'email' => 'tomas@sdlabs.test', 'username' => 'tomasweber'],
            'shop1' => ['name' => 'Cone & Dome Audio', 'email' => 'shop1@sdlabs.test', 'username' => 'coneanddome',
                'shop' => ['shop_name' => 'Cone & Dome Audio', 'country' => 'US', 'bio' => 'New drivers from stock. We ship within three working days.']],
            'shop2' => ['name' => 'Salvage Sound Co.', 'email' => 'shop2@sdlabs.test', 'username' => 'salvagesound',
                'shop' => ['shop_name' => 'Salvage Sound Co.', 'country' => 'US', 'bio' => 'Tested, used and open-box drivers. Every unit is measured before it is listed.']],
            'buyer1' => ['name' => 'Alex Rivera', 'email' => 'buyer1@sdlabs.test', 'username' => 'alexrivera'],
            'buyer2' => ['name' => 'Jordan Lee', 'email' => 'buyer2@sdlabs.test', 'username' => 'jordanlee'],
            'buyer3' => ['name' => 'Sam Okafor', 'email' => 'buyer3@sdlabs.test', 'username' => 'samokafor'],
            'buyer4' => ['name' => 'Chloe Martin', 'email' => 'buyer4@sdlabs.test', 'username' => 'chloemartin'],
            'buyer5' => ['name' => 'Ivan Petrov', 'email' => 'buyer5@sdlabs.test', 'username' => 'ivanpetrov'],
        ];
    }

    /** @return array<string, array<string, mixed>> keyed by slug */
    public static function drivers(): array
    {
        $d = fn (string $slug, string $brand, string $model, DriverCategoryEnum $category, float $size, int $impedance, int $power, string $owner, array $extra = []) => [
            'slug' => $slug, 'brand' => $brand, 'model' => $model, 'category' => $category->value, 'size' => $size,
            'impedance' => $impedance, 'power' => $power, 'owner' => $owner,
        ] + $extra + ['official' => false, 'data_price_cents' => 0, 'msrp_cents' => null, 'measure' => null];

        $list = [
            $d('a38', 'Aurelia', 'A38-S', DriverCategoryEnum::SUBWOOFER, 15, 4, 600, 'admin', ['official' => true, 'data_price_cents' => 699, 'msrp_cents' => 31900,
                'measure' => ['type' => 'subwoofer', 'fs' => 22, 'qes' => 0.38, 'qms' => 5.2, 're' => 3.4, 'sd_cm2' => 855, 'bl' => 17.5, 'le_mh' => 2.4, 'xmax_mm' => 16], 'tag' => 'Long-throw 15 inch subwoofer']),
            $d('a30', 'Aurelia', 'A30-S', DriverCategoryEnum::SUBWOOFER, 12, 4, 400, 'admin', ['official' => true, 'msrp_cents' => 21900,
                'measure' => ['type' => 'subwoofer', 'fs' => 26, 'qes' => 0.42, 'qms' => 4.6, 're' => 3.2, 'sd_cm2' => 540, 'bl' => 14.6, 'le_mh' => 1.9, 'xmax_mm' => 13], 'tag' => 'Compact 12 inch subwoofer']),
            $d('n25', 'NordCone', 'N25-DVC', DriverCategoryEnum::SUBWOOFER, 10, 4, 300, 'designer3', ['msrp_cents' => 14900,
                'measure' => ['type' => 'subwoofer', 'fs' => 30, 'qes' => 0.5, 'qms' => 4.1, 're' => 3.5, 'sd_cm2' => 330, 'bl' => 12.4, 'le_mh' => 1.5, 'xmax_mm' => 12], 'tag' => 'Dual voice coil 10 inch subwoofer']),
            $d('h8', 'Halden', 'H8-MB', DriverCategoryEnum::MIDBASS, 8, 6, 150, 'admin', ['official' => true, 'msrp_cents' => 12900,
                'measure' => ['type' => 'woofer', 'fs' => 42, 'qes' => 0.55, 'qms' => 4.0, 're' => 5.6, 'sd_cm2' => 220, 'bl' => 9.8, 'le_mh' => 0.9, 'xmax_mm' => 7.5], 'tag' => '8 inch midbass']),
            $d('k65', 'Kestrel', 'K65-MB', DriverCategoryEnum::MIDBASS, 6.5, 4, 120, 'admin', ['official' => true, 'msrp_cents' => 8900,
                'measure' => ['type' => 'woofer', 'fs' => 55, 'qes' => 0.5, 'qms' => 3.5, 're' => 3.1, 'sd_cm2' => 135, 'bl' => 7.0, 'le_mh' => 0.6, 'xmax_mm' => 6], 'tag' => 'Punchy 6.5 inch midbass']),
            $d('b5', 'Brightwell', 'B5-W', DriverCategoryEnum::WOOFER, 5, 4, 80, 'designer2', ['msrp_cents' => 5900,
                'measure' => ['type' => 'woofer', 'fs' => 62, 'qes' => 0.55, 'qms' => 4.1, 're' => 3.2, 'sd_cm2' => 85, 'bl' => 5.9, 'le_mh' => 0.45, 'xmax_mm' => 4.5, 'f_lp' => 9000], 'tag' => '5 inch coated-paper woofer']),
            $d('a16', 'Aurelia', 'A16-W', DriverCategoryEnum::WOOFER, 6.5, 6, 100, 'designer1', ['data_price_cents' => 499, 'msrp_cents' => 7900,
                'measure' => ['type' => 'woofer', 'fs' => 38, 'qes' => 0.45, 'qms' => 3.2, 're' => 5.6, 'sd_cm2' => 133, 'bl' => 7.4, 'le_mh' => 0.7, 'xmax_mm' => 5.5], 'tag' => '6.5 inch woofer for two-way monitors']),
            $d('n17', 'NordCone', 'N17-W', DriverCategoryEnum::WOOFER, 6.5, 4, 100, 'designer2', ['msrp_cents' => 6900,
                'measure' => ['type' => 'woofer', 'fs' => 44, 'qes' => 0.5, 'qms' => 3.8, 're' => 3.3, 'sd_cm2' => 128, 'bl' => 6.6, 'le_mh' => 0.55, 'xmax_mm' => 5], 'tag' => 'Fibre-cone 6.5 inch woofer']),
            $d('h20', 'Halden', 'H20-W', DriverCategoryEnum::WOOFER, 8, 6, 150, 'admin', ['official' => true, 'msrp_cents' => 10900,
                'measure' => ['type' => 'woofer', 'fs' => 34, 'qes' => 0.48, 'qms' => 3.6, 're' => 5.5, 'sd_cm2' => 214, 'bl' => 10.5, 'le_mh' => 1.0, 'xmax_mm' => 7], 'tag' => '8 inch woofer with low distortion motor']),
            $d('v3', 'Vantec', 'V3-M', DriverCategoryEnum::MIDRANGE, 3, 4, 40, 'designer3', ['msrp_cents' => 4900,
                'measure' => ['type' => 'midrange', 'fs' => 140, 'qes' => 0.7, 'qms' => 2.8, 're' => 3.4, 'sd_cm2' => 28, 'bl' => 3.4, 'le_mh' => 0.12, 'xmax_mm' => 3, 'f_lp' => 12000], 'tag' => '3 inch dome midrange']),
            $d('m5', 'Brightwell', 'M5-MR', DriverCategoryEnum::MIDRANGE, 5, 6, 60, 'designer3', ['msrp_cents' => 6400,
                'measure' => ['type' => 'midrange', 'fs' => 85, 'qes' => 0.6, 'qms' => 3.4, 're' => 5.6, 'sd_cm2' => 85, 'bl' => 6.2, 'le_mh' => 0.3, 'xmax_mm' => 3.5], 'tag' => '5 inch midrange']),
            $d('kx8', 'Kestrel', 'KX8-C', DriverCategoryEnum::COAXIAL, 8, 8, 120, 'admin', ['official' => true, 'data_price_cents' => 299, 'msrp_cents' => 16900,
                'measure' => ['type' => 'fullrange', 'fs' => 48, 'qes' => 0.6, 'qms' => 3.5, 're' => 5.4, 'sd_cm2' => 215, 'bl' => 9.0, 'le_mh' => 0.8, 'xmax_mm' => 6, 'f_lp' => 16000], 'tag' => '8 inch coaxial']),
            $d('at25', 'Aurelia', 'AT-25', DriverCategoryEnum::TWEETER, 1, 4, 80, 'admin', ['official' => true, 'data_price_cents' => 399, 'msrp_cents' => 3900,
                'measure' => ['type' => 'tweeter', 'fs' => 700, 'qes' => 0.8, 'qms' => 2.4, 're' => 4.7, 'sd_cm2' => 5.7, 'bl' => 2.7, 'le_mh' => 0.04, 'xmax_mm' => 0.5], 'tag' => '1 inch soft dome tweeter']),
            $d('ht28', 'Halden', 'HT-28', DriverCategoryEnum::TWEETER, 1.1, 6, 80, 'designer1', ['msrp_cents' => 4500,
                'measure' => ['type' => 'tweeter', 'fs' => 600, 'qes' => 0.75, 'qms' => 2.6, 're' => 5.2, 'sd_cm2' => 7.0, 'bl' => 3.1, 'le_mh' => 0.05, 'xmax_mm' => 0.6], 'tag' => '1.1 inch fabric dome tweeter']),
            $d('bt25', 'Brightwell', 'BT-25S', DriverCategoryEnum::TWEETER, 1, 4, 60, 'designer2', ['msrp_cents' => 4200,
                'measure' => ['type' => 'tweeter', 'fs' => 900, 'qes' => 0.85, 'qms' => 2.2, 're' => 4.6, 'sd_cm2' => 5.7, 'bl' => 2.6, 'le_mh' => 0.04, 'xmax_mm' => 0.5], 'tag' => '1 inch silk dome tweeter']),
            $d('vt19', 'Vantec', 'VT-19R', DriverCategoryEnum::TWEETER, 0.75, 4, 50, 'designer2', ['msrp_cents' => 3600,
                'measure' => ['type' => 'tweeter', 'fs' => 1100, 'qes' => 0.9, 'qms' => 2.0, 're' => 5.0, 'sd_cm2' => 3.1, 'bl' => 2.3, 'le_mh' => 0.03, 'xmax_mm' => 0.4], 'tag' => '3/4 inch ring radiator']),
            $d('sw3', 'Sonora', 'SW3-WB', DriverCategoryEnum::WIDEBAND, 3, 8, 25, 'designer3', ['msrp_cents' => 3200,
                'measure' => ['type' => 'fullrange', 'fs' => 105, 'qes' => 0.75, 'qms' => 3.0, 're' => 7.2, 'sd_cm2' => 30, 'bl' => 3.9, 'le_mh' => 0.2, 'xmax_mm' => 2.5], 'tag' => '3 inch wideband driver']),
            $d('r4', 'Ridgeline', 'R4-FR', DriverCategoryEnum::FULLRANGE, 4, 4, 40, 'designer3', ['msrp_cents' => 5400,
                'measure' => ['type' => 'fullrange', 'fs' => 85, 'qes' => 0.65, 'qms' => 3.2, 're' => 3.6, 'sd_cm2' => 52, 'bl' => 5.0, 'le_mh' => 0.35, 'xmax_mm' => 3.5], 'tag' => '4 inch full-range driver']),
            $d('cd14', 'Kestrel', 'CD14-HF', DriverCategoryEnum::COMPRESSION_DRIVER, 1.4, 8, 60, 'admin', ['official' => true, 'msrp_cents' => 11900,
                'measure' => ['type' => 'tweeter', 'fs' => 800, 'qes' => 0.9, 'qms' => 3.2, 're' => 6.5, 'sd_cm2' => 12, 'bl' => 9.0, 'le_mh' => 0.06, 'xmax_mm' => 0.6], 'tag' => '1.4 inch compression driver']),
            $d('pr8', 'Halden', 'PR8', DriverCategoryEnum::PASSIVE_RADIATOR, 8, 4, 0, 'admin', ['official' => true, 'msrp_cents' => 4900, 'tag' => '8 inch passive radiator']),
            $d('ex30', 'Ridgeline', 'EX30', DriverCategoryEnum::EXCITER, 1.2, 4, 30, 'designer3', ['msrp_cents' => 2400, 'tag' => 'Surface exciter']),
        ];

        return collect($list)->keyBy('slug')->all();
    }

    /**
     * Designs, components low to high. `qty` is the number of drivers in that band.
     *
     * @return array<string, array<string, mixed>> keyed by slug
     */
    public static function designs(): array
    {
        $list = [
            [
                'slug' => 'harbor-2', 'name' => 'Harbor 2', 'tag' => 'A classic two-way stand-mount', 'category' => DesignCategoryEnum::TWO_WAY->value,
                'owner' => 'designer1', 'price_cents' => 0, 'build_cost_cents' => 32000, 'active' => true, 'impedance' => 6, 'power' => 100,
                'components' => [['LF', 'a16', 1, 11.0], ['HF', 'at25', 1, 0]],
                'snapshots' => ['v1.0', 'v1.1'],
                'blurb' => 'A sealed-box monitor that images like a much more expensive speaker. The baffle is narrow, the crossover is a single board with nine parts, and the whole thing builds over a weekend.',
                'bom' => ['Cabinet' => '18 mm birch plywood, 2 x 12 L', 'Damping' => '25 mm acoustic foam', 'Crossover' => 'Fourth-order Linkwitz-Riley, air-core inductors'],
            ],
            [
                'slug' => 'slate-tower', 'name' => 'Slate Tower', 'tag' => 'Three-way floorstander with a big, relaxed sound', 'category' => DesignCategoryEnum::THREE_WAY->value,
                'owner' => 'designer1', 'price_cents' => 8900, 'build_cost_cents' => 74000, 'active' => true, 'impedance' => 6, 'power' => 200,
                'components' => [['LF', 'h20', 2, 38.0], ['MF', 'm5', 1, 4.0], ['HF', 'bt25', 1, 0]],
                'snapshots' => ['v1.0'],
                'blurb' => 'Two 8 inch woofers in a ported tower, a sealed midrange chamber and a silk dome on top. Plans include the cut list, the full crossover schematic and the measurements of every driver in the cabinet.',
                'bom' => ['Cabinet' => '18 mm MDF, 2 x 48 L', 'Port' => '2 x 50 mm flared, 140 mm long', 'Crossover' => 'Two PCBs, polypropylene capacitors'],
            ],
            [
                'slug' => 'little-brook', 'name' => 'Little Brook', 'tag' => 'Portable bookshelf speaker for desk and travel', 'category' => DesignCategoryEnum::PORTABLE->value,
                'owner' => 'designer2', 'price_cents' => 0, 'build_cost_cents' => 18000, 'active' => true, 'impedance' => 4, 'power' => 50,
                'components' => [['LF', 'b5', 1, 3.0], ['HF', 'vt19', 1, 0]],
                'snapshots' => ['v1.0'],
                'blurb' => 'A 3 litre box that fits a backpack. Built around a 5 inch woofer and a ring radiator, driven by a small class-D amplifier module.',
                'bom' => ['Cabinet' => '12 mm plywood, 3 L', 'Amplifier' => '2 x 25 W class-D module', 'Finish' => 'Hard-wax oil'],
            ],
            [
                'slug' => 'quarry-sub-15', 'name' => 'Quarry Sub 15', 'tag' => 'A deep sealed subwoofer that does not shout', 'category' => DesignCategoryEnum::SUBWOOFER->value,
                'owner' => 'designer2', 'price_cents' => 3900, 'build_cost_cents' => 52000, 'active' => true, 'impedance' => 4, 'power' => 600,
                'components' => [['LF', 'a38', 1, 85.0]],
                'snapshots' => ['v1.0'],
                'blurb' => 'One 15 inch driver in an 85 litre sealed box. Flat to 25 Hz in room, with the plate-amp settings for two common amplifiers.',
                'bom' => ['Cabinet' => '25 mm MDF, 85 L', 'Bracing' => 'Two window braces', 'Amplifier' => '500 W plate amplifier'],
            ],
            [
                'slug' => 'meadow-full-range', 'name' => 'Meadow Full-Range', 'tag' => 'One driver, no crossover', 'category' => DesignCategoryEnum::FULL_RANGE->value,
                'owner' => 'designer3', 'price_cents' => 0, 'build_cost_cents' => 9000, 'active' => true, 'impedance' => 8, 'power' => 25,
                'components' => [['LF', 'sw3', 1, 2.0]],
                'snapshots' => ['v1.0'],
                'blurb' => 'The simplest design on the site: a 3 inch wideband driver in a small transmission-line cabinet. Nothing to solder except the terminals.',
                'bom' => ['Cabinet' => '9 mm plywood, 2 L folded line', 'Stuffing' => 'Long-fibre wool'],
            ],
            [
                'slug' => 'atlas-four', 'name' => 'Atlas Four', 'tag' => 'Reference four-way with a built-in subwoofer', 'category' => DesignCategoryEnum::FOUR_WAY_PLUS->value,
                'owner' => 'admin', 'official' => true, 'price_cents' => 14900, 'build_cost_cents' => 210000, 'active' => true, 'impedance' => 4, 'power' => 300,
                'components' => [['LF', 'a30', 1, 60.0], ['LMF', 'k65', 1, 7.0], ['MF', 'v3', 1, 1.0], ['HF', 'at25', 1, 0]],
                'snapshots' => ['v1.0', 'v2.0'],
                'blurb' => 'The official SDLabs four-way. A sealed 12 inch subwoofer, a 6.5 inch midbass, a 3 inch midrange and a soft dome, crossed over digitally or with a passive network. Includes every driver measurement we used.',
                'bom' => ['Cabinet' => '25 mm MDF with constrained-layer damping', 'Crossover' => 'DSP files plus passive alternative', 'Finish' => 'Satin lacquer'],
            ],
            [
                'slug' => 'courtyard-monitor', 'name' => 'Courtyard Monitor', 'tag' => 'Budget two-way with a surprisingly big soundstage', 'category' => DesignCategoryEnum::TWO_WAY->value,
                'owner' => 'designer2', 'price_cents' => 2900, 'build_cost_cents' => 24000, 'active' => true, 'impedance' => 4, 'power' => 80,
                'components' => [['LF', 'n17', 1, 14.0], ['HF', 'ht28', 1, 0]],
                'snapshots' => ['v1.0'],
                'blurb' => 'A 14 litre ported two-way using inexpensive drivers. A hybrid of careful baffle shaping and a modest crossover.',
                'bom' => ['Cabinet' => '15 mm MDF, 14 L', 'Port' => '1 x 38 mm, 120 mm long', 'Crossover' => 'Second-order electrical, acoustic LR4'],
            ],
            [
                'slug' => 'echo-chamber', 'name' => 'Echo Chamber', 'tag' => 'A full-range driver and a sub, nothing else', 'category' => DesignCategoryEnum::ESOTERIC->value,
                'owner' => 'designer3', 'price_cents' => 0, 'build_cost_cents' => 36000, 'active' => true, 'impedance' => 4, 'power' => 40,
                'components' => [['LF', 'n25', 1, 45.0], ['MF', 'r4', 1, 6.0]],
                'snapshots' => ['v1.0'],
                'blurb' => 'For listeners who prefer a single driver through the midrange. The 10 inch subwoofer takes over below 190 Hz with a first-order electrical slope.',
                'bom' => ['Cabinet' => 'Open-baffle full-range, sealed sub', 'Wiring' => 'Solid-core copper'],
            ],
            [
                'slug' => 'stagehand-system', 'name' => 'Stagehand System', 'tag' => 'Sub plus coaxial satellites for small venues', 'category' => DesignCategoryEnum::SYSTEM->value,
                'owner' => 'admin', 'official' => true, 'price_cents' => 19900, 'build_cost_cents' => 160000, 'active' => true, 'impedance' => 4, 'power' => 400,
                'components' => [['LF', 'a30', 2, 60.0], ['MF', 'kx8', 1, 12.0]],
                'snapshots' => ['v1.0'],
                'blurb' => 'A pair of satellites built around 8 inch coaxials and a pair of subwoofers, with a trapezoid cabinet for stacking. Rigging notes included.',
                'bom' => ['Cabinet' => '15 mm birch plywood, black paint', 'Hardware' => 'M10 flypoints and pole mount cups'],
            ],
            [
                'slug' => 'draft-tower', 'name' => 'Draft Tower (work in progress)', 'tag' => 'Unfinished: waiting for the payout setup', 'category' => DesignCategoryEnum::THREE_WAY->value,
                'owner' => 'designer3', 'price_cents' => 5900, 'build_cost_cents' => 61000, 'active' => false, 'impedance' => 4, 'power' => 150,
                'components' => [['LF', 'k65', 1, 20.0], ['MF', 'v3', 1, 1.0], ['HF', 'vt19', 1, 0]],
                'snapshots' => ['v0.1'],
                'blurb' => 'A paid three-way that is still a draft: its designer has not connected a payout account yet, so it cannot be published.',
                'bom' => ['Cabinet' => '18 mm MDF'],
            ],
        ];

        return collect($list)->keyBy('slug')->all();
    }

    /**
     * Physical listings. `kind` picks the product photo (woofer, tweeter, ...).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listings(): array
    {
        $l = fn (string $slug, string $seller, string $title, ListingCondition $condition, int $price, array $extra = []) => [
            'slug' => $slug, 'seller' => $seller, 'title' => $title, 'condition' => $condition, 'price_cents' => $price,
        ] + $extra + [
            'driver' => null, 'quantity' => 1, 'units_per_lot' => 1, 'ships_from' => 'US', 'domestic' => 899, 'international' => 2499,
            'additional' => 300, 'handling_days' => 3, 'returns' => true, 'status' => ListingStatus::ACTIVE, 'kind' => 'woofer',
        ];

        return [
            $l('a16-pair-new', 'shop1', 'Aurelia A16-W 6.5" woofer, matched pair', ListingCondition::NEW, 6400, ['driver' => 'a16', 'quantity' => 6, 'units_per_lot' => 2, 'additional' => 500, 'kind' => 'woofer']),
            $l('at25-new', 'shop1', 'Aurelia AT-25 1" soft dome tweeter', ListingCondition::NEW, 3900, ['driver' => 'at25', 'quantity' => 12, 'international' => null, 'kind' => 'tweeter']),
            $l('h20-new', 'shop1', 'Halden H20-W 8" woofer', ListingCondition::NEW, 8900, ['driver' => 'h20', 'quantity' => 4, 'international' => 3900, 'kind' => 'woofer']),
            $l('n25-new', 'shop1', 'NordCone N25-DVC 10" dual voice coil subwoofer', ListingCondition::NEW, 14900, ['driver' => 'n25', 'quantity' => 3, 'domestic' => 1900, 'international' => 5900, 'additional' => 1500, 'kind' => 'subwoofer']),
            $l('bt25-pair-new', 'shop1', 'Brightwell BT-25S silk dome tweeters, pair', ListingCondition::NEW, 6800, ['driver' => 'bt25', 'quantity' => 5, 'units_per_lot' => 2, 'kind' => 'tweeter']),
            $l('v3-open-box', 'shop1', 'Vantec V3-M 3" midrange, open box', ListingCondition::OPEN_BOX, 2900, ['driver' => 'v3', 'quantity' => 2, 'kind' => 'midrange', 'return_policy' => '14 days, buyer pays return shipping.']),
            $l('a38-used', 'shop2', 'Aurelia A38-S 15" subwoofer, used, good condition', ListingCondition::USED_GOOD, 15900, ['driver' => 'a38', 'domestic' => 2900, 'international' => null, 'returns' => false, 'kind' => 'subwoofer']),
            $l('h8-pair-used', 'shop2', 'Halden H8-MB 8" midbass pair, used, like new', ListingCondition::USED_LIKE_NEW, 7500, ['driver' => 'h8', 'units_per_lot' => 2, 'international' => null, 'kind' => 'woofer']),
            $l('k65-used', 'shop2', 'Kestrel K65-MB 6.5" midbass, used', ListingCondition::USED_GOOD, 2400, ['driver' => 'k65', 'returns' => false, 'kind' => 'woofer']),
            $l('m5-pair-used', 'shop2', 'Brightwell M5-MR 5" midrange pair, used, fair', ListingCondition::USED_FAIR, 3500, ['driver' => 'm5', 'units_per_lot' => 2, 'international' => null, 'returns' => false, 'kind' => 'midrange']),
            $l('r4-parts', 'shop2', 'Ridgeline R4-FR 4" full-range, for parts', ListingCondition::FOR_PARTS, 800, ['driver' => 'r4', 'status' => ListingStatus::PAUSED, 'international' => null, 'returns' => false, 'kind' => 'woofer']),
            $l('cd14-used', 'shop2', 'Kestrel CD14-HF compression driver, used, like new', ListingCondition::USED_LIKE_NEW, 9900, ['driver' => 'cd14', 'ships_from' => 'CA', 'domestic' => 1200, 'international' => 2900, 'returns' => false, 'kind' => 'tweeter']),
            $l('n17-used', 'designer2', 'NordCone N17-W 6.5" woofer, used from my prototype', ListingCondition::USED_GOOD, 1900, ['driver' => 'n17', 'returns' => false, 'kind' => 'woofer']),
            $l('vt19-pair-used', 'designer2', 'Vantec VT-19R ring radiators, pair, used', ListingCondition::USED_LIKE_NEW, 4200, ['driver' => 'vt19', 'units_per_lot' => 2, 'status' => ListingStatus::DRAFT, 'kind' => 'tweeter']),
            $l('sw3-sold-out', 'shop1', 'Sonora SW3-WB 3" wideband driver', ListingCondition::NEW, 2800, ['driver' => 'sw3', 'quantity' => 0, 'status' => ListingStatus::SOLD_OUT, 'kind' => 'midrange']),
        ];
    }

    /** @return array<int, array{design: string, user: string, rating: int, title: string, review: string}> */
    public static function reviews(): array
    {
        return [
            ['design' => 'harbor-2', 'user' => 'buyer1', 'rating' => 5, 'title' => 'Built it in a weekend', 'review' => 'Plans were clear and the response curve matched my measurements within a dB. Great first build.'],
            ['design' => 'harbor-2', 'user' => 'buyer2', 'rating' => 4, 'title' => 'Very good for the price', 'review' => 'Imaging is excellent. I would add more bracing next time.'],
            ['design' => 'harbor-2', 'user' => 'buyer3', 'rating' => 5, 'title' => 'Recommended', 'review' => 'Crossover files saved me hours.'],
            ['design' => 'slate-tower', 'user' => 'buyer1', 'rating' => 5, 'title' => 'Worth the money', 'review' => 'The cut list alone is worth the price. Sounds huge.'],
            ['design' => 'slate-tower', 'user' => 'buyer4', 'rating' => 4, 'title' => 'Excellent documentation', 'review' => 'Midrange chamber is tricky, but the notes make it doable.'],
            ['design' => 'little-brook', 'user' => 'buyer2', 'rating' => 4, 'title' => 'Tiny but capable', 'review' => 'Surprisingly full sound from three litres. The amp module runs warm.'],
            ['design' => 'quarry-sub-15', 'user' => 'buyer5', 'rating' => 5, 'title' => 'Tight bass', 'review' => 'Sealed and controlled, exactly as the measurements say.'],
            ['design' => 'atlas-four', 'user' => 'buyer3', 'rating' => 5, 'title' => 'Reference grade', 'review' => 'The DSP files made tuning trivial.'],
            ['design' => 'meadow-full-range', 'user' => 'buyer4', 'rating' => 3, 'title' => 'Fun little project', 'review' => 'Great for background listening, not for loud music.'],
            ['design' => 'courtyard-monitor', 'user' => 'buyer5', 'rating' => 4, 'title' => 'Solid budget build', 'review' => 'Cheap drivers, careful design. Does what it says.'],
        ];
    }
}
