<?php

use App\Models\Design;
use App\Models\DesignDriver;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('private');
});

/** A driver with datasheet values and measurement files on the (fake) private disk. */
function documentedDriver(array $attributes = []): Driver
{
    $disk = Storage::disk('private');
    $dir = 'drivers/page-test';

    $frd = fn (float $level) => collect([20, 50, 100, 300, 1000, 3000, 10000, 20000])->map(fn ($f) => "$f $level 0")->implode("\n");
    $disk->put("$dir/Frequency/on-axis.frd", $frd(86));
    $disk->put("$dir/Frequency/off-axis-30.frd", $frd(82));
    $disk->put("$dir/Impedance/driver.zma", '20 6 0');
    $disk->put("$dir/Other/datasheet.pdf", str_repeat('x', 2048));

    return Driver::factory()->create($attributes + [
        'brand' => 'Aurelia',
        'model' => 'A16-W',
        'category' => 'Woofer',
        'size' => 6.5,
        'impedance' => '8',
        'power' => 120,
        'msrp_cents' => 21000,
        'link' => 'https://maker.example/a16',
        'summary' => 'A short note about the cone.',
        'description' => '<p>Long text about the surround.</p>',
        'factory_specs' => ['Re' => '5.8', 'Fs' => '38.5', 'Qts' => '0.395', 'Vas' => '23.4', 'Sd' => '132', 'BL' => '7.1', 'Xmax' => '6', 'Le' => '0.4', 'SPL' => '88.5', 'Mms' => '14.2', 'Cms' => '1.2', 'Rms' => '1.1', 'Vd' => '79', 'EBP' => '97', 'Extra' => '', 'Notes' => 'see sheet'],
        'frequency_files' => ["$dir/Frequency/on-axis.frd", "$dir/Frequency/off-axis-30.frd", "$dir/Frequency/missing.frd"],
        'impedance_files' => ["$dir/Impedance/driver.zma"],
        'other_files' => ["$dir/Other/datasheet.pdf"],
    ]);
}

it('renders the sheet with its number, name, category and spec table', function () {
    $driver = documentedDriver();

    $this->get('/drivers/driver/'.$driver->id)
        ->assertOk()
        ->assertSee('SDL-R-'.str_pad((string) $driver->id, 4, '0', STR_PAD_LEFT))
        ->assertSee('Aurelia')->assertSee('A16-W')->assertSee('Woofer')
        ->assertSee('data-driver-specs', false)
        ->assertSee('6.5 in')->assertSee('8 Ω')->assertSee('120 W')->assertSee('$210.00')
        ->assertSee('0 designs')
        ->assertSee('Factory specs')->assertSee('Measured response')->assertSee('About this driver')->assertSee('Used in designs')->assertSee('Downloads')->assertSee('Discussion')
        ->assertSee('A short note about the cone.')->assertSee('Long text about the surround.')
        ->assertSee('data-gallery', false);
});

it('lists the factory specs with their units and skips empty values', function () {
    $driver = documentedDriver();

    $this->get('/drivers/driver/'.$driver->id)
        ->assertOk()
        ->assertSee('data-driver-factory-specs', false)
        ->assertSee('data-spec="Fs"', false)->assertSee('38.5')->assertSee('Hz')
        ->assertSee('Resonance frequency')
        ->assertSee('0.395')->assertSee('Total Q')
        ->assertSee('23.4')->assertSee('132')->assertSee('cm²')->assertSee('T·m')->assertSee('mm/N')->assertSee('kg/s')->assertSee('cm³')->assertSee('mH')->assertSee('dB')
        ->assertSee('data-spec="Notes"', false)->assertSee('see sheet')
        ->assertDontSee('data-spec="Extra"', false)
        ->assertDontSee('data-spec="Qms"', false);

    $empty = Driver::factory()->create(['factory_specs' => ['Re' => '', 'Fs' => null]]);
    $this->get('/drivers/driver/'.$empty->id)->assertOk()->assertSee('No factory specs yet');
});

it('draws the measured response from the frequency files without adding them up', function () {
    $driver = documentedDriver();

    $this->get('/drivers/driver/'.$driver->id)
        ->assertOk()
        ->assertSee('data-response-chart', false)->assertSee('responseChart(', false)
        ->assertSee('on-axis')->assertSee('off-axis-30')
        ->assertSee('Midband')
        ->assertSee('the level is')->assertDontSee('the sum is')
        ->assertDontSee('No measurements yet');

    $this->get('/drivers/driver/'.Driver::factory()->create()->id)
        ->assertOk()->assertSee('No measurements yet')->assertDontSee('data-response-chart', false);
});

it('shows the published designs that use the driver as cards', function () {
    $driver = documentedDriver();
    $design = Design::factory()->create(['name' => 'Quadrant Tower']);
    $draft = Design::factory()->draft()->create(['name' => 'Secret Prototype']);
    DesignDriver::factory()->for($design)->for($driver)->create();
    DesignDriver::factory()->for($design)->for($driver)->create(['position' => 'MF']);
    DesignDriver::factory()->for($draft)->for($driver)->create();

    $this->get('/drivers/driver/'.$driver->id)
        ->assertOk()
        ->assertSee('data-driver-designs', false)
        ->assertSee('data-design-card="'.$design->id.'"', false)->assertSee('Quadrant Tower')
        ->assertDontSee('Secret Prototype')
        ->assertSee('1 design');

    $this->get('/drivers/driver/'.Driver::factory()->create()->id)->assertOk()->assertSee('No published design uses this driver yet');
});

it('lists the snapshots to download and names the measurement files without their paths', function () {
    $driver = documentedDriver();
    \App\Models\DriverSnapshot::factory()->for($driver)->withFile()->create(['version' => 'v2.1']);
    $snapshot = $driver->snapshots()->first();

    $this->get('/drivers/driver/'.$driver->id)
        ->assertOk()
        ->assertSee('data-driver-files', false)
        ->assertSee('v2.1')
        ->assertSee(route('driver-snapshots.download', $snapshot), false)
        ->assertSee('Frequency response files')->assertSee('on-axis.frd')->assertSee('FRD frequency response')
        ->assertSee('Impedance files')->assertSee('driver.zma')->assertSee('ZMA impedance')
        ->assertSee('Other measurement files')->assertSee('datasheet.pdf')->assertSee('PDF document')->assertSee('2 KB')
        ->assertDontSee('missing.frd')
        ->assertDontSee('drivers/page-test');

    $bare = Driver::factory()->create();
    $this->get('/drivers/driver/'.$bare->id)->assertOk()->assertSee('No files published yet')->assertSee('No measurement files are listed');
});

it('keeps the SDLabs buy box for a driver with an active store listing', function () {
    $driver = documentedDriver();
    storeListing([], $driver);

    $this->get('/drivers/driver/'.$driver->id)
        ->assertOk()
        ->assertSee('data-driver-store', false)->assertSee('data-buy-box', false)->assertSee('Sold by SDLabs')->assertSee('$129.00')
        ->assertSee('Manufacturer list price')
        ->assertDontSee('data-driver-msrp', false)->assertDontSee('Out of stock at SDLabs');
});

it('says when the store listing sold out and still shows the manufacturer price and the used ones', function () {
    $driver = documentedDriver();
    storeListing(['status' => \App\Enums\ListingStatus::SOLD_OUT, 'quantity' => 0], $driver);
    sellableListing(attributes: ['driver_id' => $driver->id, 'price_cents' => 9900]);

    $this->get('/drivers/driver/'.$driver->id)
        ->assertOk()
        ->assertSee('data-driver-sold-out', false)->assertSee('Out of stock at SDLabs')
        ->assertSee('data-driver-msrp', false)->assertSee('Buy from the manufacturer')->assertSee('https://maker.example/a16', false)
        ->assertSee('data-driver-listings', false)->assertSee('Used ones on the marketplace (1)')
        ->assertSee('data-driver-marketplace', false)->assertSee('On the marketplace')->assertSee('$99.00')
        ->assertDontSee('data-buy-box', false);
});

it('leaves the marketplace section out when nobody sells a used one', function () {
    $driver = documentedDriver();

    $this->get('/drivers/driver/'.$driver->id)
        ->assertOk()
        ->assertDontSee('data-driver-marketplace', false)->assertDontSee('data-driver-listings', false)->assertDontSee('Used ones on the marketplace');
});

it('hides unpublished drivers from the public', function () {
    $draft = Driver::factory()->draft()->create();

    $this->get('/drivers/driver/'.$draft->id)->assertNotFound();
    $this->actingAs(User::factory()->create())->get('/drivers/driver/'.$draft->id)->assertNotFound();
    $this->actingAs($draft->creator)->get('/drivers/driver/'.$draft->id)->assertOk();
});
