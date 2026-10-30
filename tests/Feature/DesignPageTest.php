<?php

use App\Models\Design;
use App\Models\DesignDriver;
use App\Models\Driver;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('private');
});

/** A design with two bands, measurement files and source files on the (fake) private disk. */
function documentedDesign(array $attributes = []): Design
{
    $disk = Storage::disk('private');
    $dir = 'designs/page-test';

    $frd = fn (float $level) => collect([20, 50, 100, 300, 1000, 3000, 10000, 20000])->map(fn ($f) => "$f $level 0")->implode("\n");
    $disk->put("$dir/Display/LF-Woofer.frd", $frd(84));
    $disk->put("$dir/Display/HF-Tweeter.frd", $frd(80));
    $disk->put("$dir/Enclosure/cut-list.pdf", str_repeat('x', 2048));
    $disk->put("$dir/Enclosure/box.dxf", 'dxf');
    $disk->put("$dir/Electronics/crossover.xlsx", 'sheet');
    $disk->put("$dir/Other/notes.txt", 'notes');
    $disk->put("$dir/LF/lf-on-axis.frd", $frd(84));
    $disk->put("$dir/LF/lf.zma", '20 6 0');

    $design = Design::factory()->create($attributes + [
        'bill_of_materials' => ['Cabinet' => '18 mm birch plywood', 'Damping' => '25 mm foam'],
        'frd_files' => ["$dir/Display/LF-Woofer.frd", "$dir/Display/HF-Tweeter.frd", "$dir/Display/missing.frd"],
        'enclosure_files' => ["$dir/Enclosure/cut-list.pdf", "$dir/Enclosure/box.dxf"],
        'electronic_files' => ["$dir/Electronics/crossover.xlsx"],
        'design_other_files' => ["$dir/Other/notes.txt"],
    ]);

    DesignDriver::factory()->for($design)->for(Driver::factory()->create(['brand' => 'Aurelia', 'model' => 'A16-W']))->create([
        'position' => 'LF',
        'quantity' => 2,
        'low_frequency' => 38,
        'high_frequency' => 3400,
        'air_volume' => 12.5,
        'description' => '<p>Driver notes about the woofer.</p>',
        'specifications' => ['Fs' => '38.0', 'Qts' => '0.395', 'Vas' => '23.4'],
        'frequency_files' => ["$dir/LF/lf-on-axis.frd"],
        'impedance_files' => ["$dir/LF/lf.zma"],
    ]);

    return $design;
}

it('lists the parts list and the drivers with their air volume', function () {
    $design = documentedDesign();

    $this->get('/designs/design/'.$design->id)
        ->assertOk()
        ->assertSee('SDL-D-'.str_pad((string) $design->id, 4, '0', STR_PAD_LEFT))
        ->assertSee('data-design-bom', false)->assertSee('Cabinet')->assertSee('18 mm birch plywood')->assertSee('25 mm foam')
        ->assertSee('Aurelia A16-W')->assertSee('12.5 L')->assertSee('38 Hz')->assertSee('3.4 kHz')
        ->assertSee('Driver notes about the woofer.')
        ->assertSee('2 (1 band)')
        ->assertSee('sd-rich-'.$design->id, false);
});

it('names the files and never prints their storage paths', function () {
    $design = documentedDesign(['price_cents' => 2500]);

    $this->get('/designs/design/'.$design->id)
        ->assertOk()
        ->assertSee('Enclosure files')->assertSee('Crossover / electronics files')->assertSee('Other design files')->assertSee('Response previews')
        ->assertSee('cut-list.pdf')->assertSee('PDF document')->assertSee('2 KB')
        ->assertSee('box.dxf')->assertSee('DXF CAD drawing')
        ->assertSee('crossover.xlsx')->assertSee('Spreadsheet')
        ->assertSee('notes.txt')->assertSee('Text')
        ->assertSee('LF-Woofer.frd')->assertSee('FRD frequency response')
        ->assertSee('lf.zma')->assertSee('ZMA impedance')
        ->assertSee('Aurelia A16-W: impedance files')
        ->assertDontSee('missing.frd')
        ->assertSee('Build guide PDF with your name on every page')
        ->assertSee('Available after purchase')
        ->assertDontSee('designs/page-test');

    $this->get('/designs/design/'.documentedDesign(['price_cents' => 0])->id)->assertSee('Free with an account');
});

it('never shows the Thiele/Small parameters of a driver', function () {
    $design = documentedDesign();

    $this->get('/designs/design/'.$design->id)
        ->assertOk()
        ->assertSee('Aurelia A16-W')
        ->assertDontSee('0.395')->assertDontSee('23.4')->assertDontSee('Qts')->assertDontSee('Vas')->assertDontSee('Thiele');
});

it('draws the response chart with its midband level and -3 dB point', function () {
    $design = documentedDesign();

    $this->get('/designs/design/'.$design->id)
        ->assertOk()
        ->assertSee('data-response-chart', false)
        ->assertSee('responseChart(', false)
        ->assertSee('Midband')->assertSee('LF-Woofer')->assertSee('HF-Tweeter')
        ->assertDontSee('No measurement files');
});

it('says so when a design has no measurement files or parts list', function () {
    $design = Design::factory()->create();

    $this->get('/designs/design/'.$design->id)
        ->assertOk()
        ->assertSee('No measurement files')
        ->assertSee('No parts list yet')
        ->assertDontSee('data-response-chart', false);
});

it('prints the designer\'s own CSS limited to the text blocks of the design', function () {
    $design = documentedDesign(['description_css' => "h2 { color: #7c2d12 }\nbody { display: none }\n@import url(https://example.com/x.css);\np { background: url(x.png); color: red }\n</style><script>alert(1)</script>"]);

    $response = $this->get('/designs/design/'.$design->id)->assertOk();

    $response
        ->assertSee('data-design-css', false)
        ->assertSee('.sd-rich-'.$design->id.' h2 { color:#7c2d12; }', false)
        ->assertSee('.sd-rich-'.$design->id.' p { color:red; }', false)
        ->assertDontSee('example.com/x.css', false)->assertDontSee('x.png', false)
        ->assertDontSee('<script>alert(1)', false);

    // one style element for the designer's CSS, closed by our own tag only
    preg_match('~<style data-design-css>(.*?)</style>~s', $response->getContent(), $m);
    expect($m[1] ?? '')->not->toContain('<')->not->toContain('body')->not->toContain('display');
});

it('adds no style block for a design without its own CSS', function () {
    $design = documentedDesign();

    $this->get('/designs/design/'.$design->id)->assertOk()->assertDontSee('data-design-css', false);
});

it('stores the raw CSS and cleans it when drawn', function () {
    $design = Design::factory()->create(['description_css' => 'body { color: red } h2 { color: blue }']);

    expect($design->fresh()->description_css)->toBe('body { color: red } h2 { color: blue }')
        ->and($design->descriptionStyles())->toBe('.sd-rich-'.$design->id.' h2 { color:blue; }')
        ->and(Design::factory()->create()->descriptionStyles())->toBe('');
});

it('lets an admin save a design\'s own CSS from the Advanced section', function () {
    Storage::fake('public');
    $design = Design::factory()->create();

    Livewire\Livewire::actingAs(adminUser())->test(App\Filament\Resources\DesignResource\Pages\EditDesign::class, ['record' => $design->getRouteKey()])
        ->fillForm(['description_css' => 'h2 { color: red }'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($design->fresh()->description_css)->toBe('h2 { color: red }');
});
