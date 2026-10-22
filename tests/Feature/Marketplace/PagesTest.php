<?php

use App\Models\Design;
use App\Models\Driver;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('private');
});

it('shows prices from the cent columns on the design page and cards', function () {
    $paid = Design::factory()->paid(4999)->create(['build_cost_cents' => 123456]);
    $free = Design::factory()->create(['name' => 'Gratis Monitor']);

    $this->get('/designs/design/'.$paid->id)->assertOk()->assertSee('$49.99')->assertSee('$1,234.56');
    $this->get('/designs/design/'.$free->id)->assertOk()->assertSee('Free');
    $this->get('/designs')->assertOk()->assertSee('$49.99')->assertSee('Gratis Monitor');
});

it('filters and sorts the design list by price in dollars', function () {
    Design::factory()->paid(1000)->create(['name' => 'Cheap Design']);
    Design::factory()->paid(9000)->create(['name' => 'Pricey Design']);

    $this->get('/designs?min_price=50&max_price=100')->assertOk()->assertSee('Pricey Design')->assertDontSee('Cheap Design');
    $this->get('/designs?max_price=20')->assertOk()->assertSee('Cheap Design')->assertDontSee('Pricey Design');
    $this->get('/designs?sort=price&direction=asc')->assertOk();
});

it('only links the downloads a visitor may actually use on a design page', function () {
    $design = Design::factory()->paid(1500)->withSnapshot('v1.0', true)->for(onboardedSeller(), 'designer')->create();
    $snapshot = $design->snapshots->first();
    $url = route('design-snapshots.download', $snapshot);

    $this->get('/designs/design/'.$design->id)->assertOk()->assertDontSee($url, false);

    $stranger = User::factory()->create();
    $this->actingAs($stranger)->get('/designs/design/'.$design->id)->assertOk()->assertDontSee($url, false);

    $stranger->grantEntitlement($design);
    $this->actingAs($stranger)->get('/designs/design/'.$design->id)->assertOk()->assertSee($url, false);

    $this->actingAs($design->designer)->get('/designs/design/'.$design->id)->assertOk()->assertSee($url, false);
});

it('links downloads of a free design to every signed-in user without handing out an entitlement for a page view', function () {
    $design = Design::factory()->withSnapshot('v1.0', true)->create();
    $user = User::factory()->create();

    $this->actingAs($user)->get('/designs/design/'.$design->id)
        ->assertOk()
        ->assertSee(route('design-snapshots.download', $design->snapshots->first()), false);

    expect($user->entitlements()->count())->toBe(0);
});

it('shows the MSRP and links the measurement downloads on the driver page to everybody', function () {
    $driver = Driver::factory()->withSnapshot('v1.0', true)->create(['msrp_cents' => 12900]);
    $url = route('driver-snapshots.download', $driver->snapshots->first());

    $this->get('/drivers/driver/'.$driver->id)->assertOk()->assertSee('$129.00')->assertSee($url, false);

    $this->actingAs(User::factory()->create())->get('/drivers/driver/'.$driver->id)->assertOk()->assertSee($url, false);
});

it('shows the cart with prices from the cent columns and keeps guests out', function () {
    $this->get('/cart')->assertRedirect();

    $user = User::factory()->create();
    $listing = Listing::factory()->create(['title' => 'Cart Woofer', 'price_cents' => 4500]);
    $user->cart()->create()->items()->create([
        'purchasable_type' => 'listing', 'purchasable_id' => $listing->id, 'quantity' => 2, 'unit_price_cents' => 4500,
    ]);

    $this->actingAs($user)->get('/cart')->assertOk()->assertSee('Cart Woofer')->assertSee('$90.00');
});

it('renders the design snapshot PDF view without a network', function () {
    $design = Design::factory()->create(['build_cost_cents' => 50000]);

    $pdf = app(App\Services\Snapshots\DesignPdf::class)->viewData(['name' => $design->name, 'version' => 'v1', 'build_cost_cents' => 50000]);
    $html = view('pdf.Design', ['pdf' => $pdf, 'license' => null])->render();

    // nothing is fetched: no remote image, stylesheet or font (the footer only prints the site address as text)
    expect($html)->toContain('$500.00')->not->toMatch('/(src|href)=["\']https?:/')->not->toContain('url(http')->not->toContain('@import');
});
