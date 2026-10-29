<?php

use App\Jobs\BuildDesignSnapshot;
use App\Jobs\BuildDriverSnapshot;
use App\Models\Design;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('private');
});

function createDesignData(array $overrides = []): array
{
    return $overrides + [
        'name' => 'Test Monitor',
        'card_image' => [UploadedFile::fake()->image('front.jpg')],
        'category' => 'Two-Way',
        'price_cents' => '12.50',
        'build_cost_cents' => '200',
        'active' => false,
    ];
}

it('creates a design with the price typed in dollars and stored as cents', function () {
    $user = User::factory()->create();

    dashboardPage($this, $user, '/dashboard/designs')
        ->callTableAction('create', data: createDesignData())
        ->assertHasNoTableActionErrors();

    $design = Design::where('name', 'Test Monitor')->firstOrFail();

    expect($design->user_id)->toBe($user->id)
        ->and($design->price_cents)->toBe(1250)
        ->and($design->build_cost_cents)->toBe(20000)
        ->and($design->storage_key)->toMatch('/^[0-9a-f-]{36}$/')
        ->and($design->active)->toBeFalse()
        // the picture is public and lives under the design's storage key
        ->and($design->card_image[0])->toStartWith('designs/'.$design->storage_key.'/Attachments/Display Images/');

    Storage::disk('public')->assertExists($design->card_image[0]);
});

it('stores uploaded source files on the private disk under the storage key', function () {
    $user = User::factory()->create();

    dashboardPage($this, $user, '/dashboard/designs')
        ->callTableAction('create', data: createDesignData([
            'enclosure_files' => [UploadedFile::fake()->create('cabinet.pdf', 10)],
            'electronic_files' => [UploadedFile::fake()->create('crossover.pdf', 10)],
            'design_other_files' => [UploadedFile::fake()->create('notes.txt', 1)],
            'frd_files' => [UploadedFile::fake()->create('lf.frd', 1)],
        ]))
        ->assertHasNoTableActionErrors();

    $design = Design::where('name', 'Test Monitor')->firstOrFail();
    $key = $design->storage_key;

    expect($design->enclosure_files)->toBe(["designs/{$key}/Enclosure/cabinet.pdf"])
        ->and($design->electronic_files)->toBe(["designs/{$key}/Electronics/crossover.pdf"])
        ->and($design->design_other_files)->toBe(["designs/{$key}/Other Files/notes.txt"])
        ->and($design->frd_files)->toBe(["designs/{$key}/Attachments/Display Responses/lf.frd"]);

    foreach ([...$design->enclosure_files, ...$design->electronic_files, ...$design->design_other_files, ...$design->frd_files] as $path) {
        Storage::disk('private')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
    }
});

it('stores the files of a design component on the private disk under the design storage key', function () {
    $user = User::factory()->create();
    $driver = Driver::factory()->create();

    dashboardPage($this, $user, '/dashboard/designs')
        ->callTableAction('create', data: createDesignData([
            'components' => [[
                'driver_id' => $driver->id,
                'position' => 'LF',
                'quantity' => 1,
                'frequency_files' => [UploadedFile::fake()->create('lf.frd', 1)],
                'impedance_files' => [UploadedFile::fake()->create('lf.zma', 1)],
                'other_files' => [UploadedFile::fake()->create('lf.pdf', 1)],
            ]],
        ]))
        ->assertHasNoTableActionErrors();

    $design = Design::where('name', 'Test Monitor')->firstOrFail();
    $component = $design->components()->firstOrFail();
    $key = $design->storage_key;

    expect($component->frequency_files)->toBe(["designs/{$key}/LF/Frequency/lf.frd"])
        ->and($component->impedance_files)->toBe(["designs/{$key}/LF/Impedance/lf.zma"])
        ->and($component->other_files)->toBe(["designs/{$key}/LF/Other/lf.pdf"]);

    foreach ([...$component->frequency_files, ...$component->impedance_files, ...$component->other_files] as $path) {
        Storage::disk('private')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
    }
});

it('stores uploaded driver files on the private disk under the storage key', function () {
    $user = User::factory()->create();

    dashboardPage($this, $user, '/dashboard/drivers')
        ->callTableAction('create', data: [
            'brand' => 'Aurelia', 'model' => 'T-3', 'category' => 'Tweeter', 'size' => 1, 'impedance' => 4, 'power' => 50,
            'active' => false,
            'frequency_files' => [UploadedFile::fake()->create('t3.frd', 1)],
            'impedance_files' => [UploadedFile::fake()->create('t3.zma', 1)],
            'other_files' => [UploadedFile::fake()->create('t3.pdf', 1)],
            'card_image' => [UploadedFile::fake()->image('t3.jpg')],
        ])
        ->assertHasNoTableActionErrors();

    $driver = Driver::where('model', 'T-3')->firstOrFail();
    $key = $driver->storage_key;

    expect($driver->frequency_files)->toBe(["drivers/{$key}/Frequency/t3.frd"])
        ->and($driver->impedance_files)->toBe(["drivers/{$key}/Impedance/t3.zma"])
        ->and($driver->other_files)->toBe(["drivers/{$key}/Other/t3.pdf"])
        ->and($driver->card_image[0])->toStartWith("drivers/{$key}/Attachments/Display Images/");

    foreach ([...$driver->frequency_files, ...$driver->impedance_files, ...$driver->other_files] as $path) {
        Storage::disk('private')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
    }
    Storage::disk('public')->assertExists($driver->card_image[0]);
    Storage::disk('private')->assertMissing($driver->card_image[0]);
});

it('saves a paid design as a draft when it cannot be sold yet, and publishes free ones', function () {
    $user = User::factory()->create(); // no payout account

    $page = dashboardPage($this, $user, '/dashboard/designs');
    $page->callTableAction('create', data: createDesignData(['name' => 'Paid one', 'active' => true]));
    $page->callTableAction('create', data: createDesignData(['name' => 'Free one', 'price_cents' => '0', 'active' => true]));

    expect(Design::where('name', 'Paid one')->first()->active)->toBeFalse()
        ->and(Design::where('name', 'Free one')->first()->active)->toBeTrue();
});

it('lets a seller with a payout account publish a paid design once it has a snapshot', function () {
    $seller = onboardedSeller();
    $design = Design::factory()->for($seller, 'designer')->paid()->draft()->create();

    $page = dashboardPage($this, $seller, '/dashboard/designs');

    // no snapshot yet: the toggle refuses
    $page->call('updateTableColumnState', 'active', $design->getKey(), true);
    expect($design->refresh()->active)->toBeFalse();

    $design->snapshots()->create(['version' => 'v1', 'snapshot_name' => 'x.zip', 'stashed_data' => [], 'download_path' => 'x.zip']);
    $page->call('updateTableColumnState', 'active', $design->getKey(), true);
    expect($design->refresh()->active)->toBeTrue();
});

it('queues a snapshot instead of building it in the request', function () {
    Queue::fake();
    $user = User::factory()->create();
    $design = Design::factory()->for($user, 'designer')->create();

    dashboardPage($this, $user, '/dashboard/designs')
        ->callTableAction('createDesignSnapshot', $design, data: ['version' => 'v1.0'])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Snapshot queued');

    Queue::assertPushed(BuildDesignSnapshot::class, fn ($job) => $job->version === 'v1.0' && $job->design->is($design) && $job->requestedBy->is($user));
});

it('edits a design and keeps its storage key and files location', function () {
    $user = User::factory()->create();
    $design = Design::factory()->for($user, 'designer')->create(['name' => 'Before', 'price_cents' => 0]);
    $key = $design->storage_key;
    Storage::disk('public')->put($design->card_image[0], 'image'); // the edit form needs the stored picture

    dashboardPage($this, $user, '/dashboard/designs')
        ->callTableAction('edit', $design, data: ['name' => 'After', 'category' => 'Two-Way', 'price_cents' => '9.99', 'active' => false])
        ->assertHasNoTableActionErrors();

    expect($design->refresh()->name)->toBe('After')
        ->and($design->price_cents)->toBe(999)
        ->and($design->storage_key)->toBe($key);
});

it('creates a driver with an MSRP', function () {
    $user = User::factory()->create();

    dashboardPage($this, $user, '/dashboard/drivers')
        ->callTableAction('create', data: [
            'brand' => 'Aurelia',
            'model' => 'T-1',
            'category' => 'Tweeter',
            'size' => 1,
            'impedance' => 4,
            'power' => 50,
            'msrp_cents' => '45',
            'active' => false,
        ])
        ->assertHasNoTableActionErrors();

    $driver = Driver::where('model', 'T-1')->firstOrFail();

    expect($driver->msrp_cents)->toBe(4500)
        ->and($driver->storage_key)->toMatch('/^[0-9a-f-]{36}$/')
        ->and($driver->user_id)->toBe($user->id);
});

it('leaves the MSRP empty when it is not filled in', function () {
    $user = User::factory()->create();

    dashboardPage($this, $user, '/dashboard/drivers')
        ->callTableAction('create', data: [
            'brand' => 'Aurelia', 'model' => 'T-2', 'category' => 'Tweeter', 'size' => 1, 'impedance' => 4, 'power' => 50,
            'msrp_cents' => '', 'active' => false,
        ])
        ->assertHasNoTableActionErrors();

    $driver = Driver::where('model', 'T-2')->firstOrFail();
    expect($driver->msrp_cents)->toBeNull();
});

it('queues a driver snapshot', function () {
    Queue::fake();
    $user = User::factory()->create();
    $driver = Driver::factory()->for($user, 'creator')->create();

    dashboardPage($this, $user, '/dashboard/drivers')
        ->callTableAction('createDriverSnapshot', $driver, data: ['version' => 'v1'])
        ->assertHasNoTableActionErrors();

    Queue::assertPushed(BuildDriverSnapshot::class, fn ($job) => $job->driver->is($driver));
});

it('lists the digital products a user got in the library', function () {
    $user = User::factory()->create();
    $mine = Design::factory()->withSnapshot()->create(['name' => 'Library Design']);
    $revoked = Design::factory()->withSnapshot()->create(['name' => 'Revoked Design']);
    $user->grantEntitlement($mine);
    $user->grantEntitlement($revoked)->revoke();

    $this->actingAs($user)->get('/dashboard/library')->assertOk();

    dashboardPage($this, $user, '/dashboard/library')
        ->assertSee('Library Design')
        ->assertDontSee('Revoked Design');
});

it('restores an unsaved design edit, driver rows included, and saves it without duplicating the rows', function () {
    $user = User::factory()->create();
    $design = Design::factory()->for($user, 'designer')->create(['name' => 'Before', 'price_cents' => 0]);
    Storage::disk('public')->put($design->card_image[0], 'image'); // the edit form needs the stored picture
    $component = \App\Models\DesignDriver::factory()->for($design)->create(['quantity' => 1, 'driver_id' => Driver::factory()->create(['active' => true])->id]);

    $page = dashboardPage($this, $user, '/dashboard/designs')
        ->mountTableAction('edit', $design)
        ->set('mountedTableActionsData.0.name', 'Half-edited')
        ->set('mountedTableActionsData.0.components.record-'.$component->id.'.quantity', 3)
        ->call('saveFormDraft')
        ->call('unmountTableAction');

    $page->mountTableAction('edit', $design)
        ->assertTableActionDataSet(['name' => 'Half-edited', 'components.record-'.$component->id.'.quantity' => 3])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($design->refresh()->name)->toBe('Half-edited')
        ->and($design->components()->count())->toBe(1)
        ->and($component->refresh()->quantity)->toBe(3);

    // saved, so the draft is gone
    expect(\Illuminate\Support\Facades\Cache::get('form-draft:'.$user->id.':'.$page->instance()->getName().':edit:'.$design->id))->toBeNull();
});
