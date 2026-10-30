<?php

use App\Enums\EntitlementSource;
use App\Filament\Resources\DesignResource;
use App\Filament\Resources\DriverResource;
use App\Filament\Resources\UserResource;
use App\Jobs\BuildDesignSnapshot;
use App\Models\Design;
use App\Models\DesignDriver;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('private');
    $this->admin = adminUser();
});

it('opens every catalogue and user admin page', function () {
    $design = Design::factory()->create();
    $driver = Driver::factory()->create();
    $user = User::factory()->create();

    foreach ([
        '/admin/designs', '/admin/designs/create', '/admin/designs/'.$design->id.'/edit',
        '/admin/drivers', '/admin/drivers/create', '/admin/drivers/'.$driver->id.'/edit',
        '/admin/users', '/admin/users/create', '/admin/users/'.$user->id.'/edit',
    ] as $url) {
        $this->actingAs($this->admin)->get($url)->assertOk();
    }

    $this->actingAs($user)->get('/admin/designs')->assertForbidden();
});

it('creates a design with every field from the admin panel', function () {
    $owner = User::factory()->create();

    Livewire::actingAs($this->admin)->test(DesignResource\Pages\CreateDesign::class)
        ->fillForm([
            'user_id' => $owner->id,
            'name' => 'Admin Monitor',
            'tag' => 'Made by an admin',
            'category' => 'Two-Way',
            'price_cents' => '0',
            'build_cost_cents' => '150',
            'impedance' => 8,
            'power' => 60,
            'official' => true,
            'active' => true,
            'bill_of_materials' => ['Woofer' => '2'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $design = Design::where('name', 'Admin Monitor')->firstOrFail();

    expect($design->user_id)->toBe($owner->id)
        ->and($design->category)->toBe('Two-Way')
        ->and($design->build_cost_cents)->toBe(15000)
        ->and($design->official)->toBeTrue()
        ->and($design->active)->toBeTrue()
        ->and($design->bill_of_materials)->toBe(['Woofer' => '2'])
        ->and($design->storage_key)->toMatch('/^[0-9a-f-]{36}$/');
});

it('edits a driver and keeps its owner and storage key', function () {
    $driver = Driver::factory()->create(['brand' => 'Old', 'official' => false]);
    $key = $driver->storage_key;
    $owner = $driver->user_id;

    Livewire::actingAs($this->admin)->test(DriverResource\Pages\EditDriver::class, ['record' => $driver->getRouteKey()])
        ->fillForm(['brand' => 'Aurelia', 'official' => true, 'msrp_cents' => '49.99', 'factory_specs' => ['Fs' => '38']])
        ->call('save')
        ->assertHasNoFormErrors();

    $driver->refresh();
    expect($driver->brand)->toBe('Aurelia')
        ->and($driver->official)->toBeTrue()
        ->and($driver->msrp_cents)->toBe(4999)
        ->and($driver->factory_specs['Fs'])->toBe('38')
        ->and($driver->storage_key)->toBe($key)
        ->and($driver->user_id)->toBe($owner);
});

it('transfers a paid design, keeps a copy for the old owner and marks it official for a manufacturer', function () {
    $old = User::factory()->create();
    $manufacturer = User::factory()->create();
    $manufacturer->assignRole('manufacturer');
    $design = Design::factory()->for($old, 'designer')->paid()->create(['official' => false]);

    Livewire::actingAs($this->admin)->test(DesignResource\Pages\ListDesigns::class)
        ->callTableAction('transferOwnership', $design, data: ['user_id' => $manufacturer->id, 'keep_copy' => true])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Ownership transferred');

    $design->refresh();
    expect($design->user_id)->toBe($manufacturer->id)
        ->and($design->official)->toBeTrue()
        ->and($old->entitlementFor($design)?->source)->toBe(EntitlementSource::ADMIN_GRANT);
});

it('transfers a driver from its edit page', function () {
    $driver = Driver::factory()->create();
    $new = User::factory()->create();

    Livewire::actingAs($this->admin)->test(DriverResource\Pages\EditDriver::class, ['record' => $driver->getRouteKey()])
        ->callAction('transferOwnership', data: ['user_id' => $new->id])
        ->assertHasNoActionErrors();

    expect($driver->refresh()->user_id)->toBe($new->id)->and($driver->official)->toBeFalse();
});

it('adds a driver to a design and queues a snapshot from the admin panel', function () {
    Queue::fake();
    $design = Design::factory()->create();
    $driver = Driver::factory()->create(['active' => true]);

    Livewire::actingAs($this->admin)->test(DesignResource\RelationManagers\ComponentsRelationManager::class, ['ownerRecord' => $design, 'pageClass' => DesignResource\Pages\EditDesign::class])
        ->callTableAction('create', data: ['driver_id' => $driver->id, 'position' => 'LF', 'quantity' => 2, 'low_frequency' => 40, 'high_frequency' => 2000])
        ->assertHasNoTableActionErrors();

    expect(DesignDriver::where('design_id', $design->id)->where('driver_id', $driver->id)->value('quantity'))->toBe(2);

    Livewire::actingAs($this->admin)->test(DesignResource\RelationManagers\SnapshotsRelationManager::class, ['ownerRecord' => $design, 'pageClass' => DesignResource\Pages\EditDesign::class])
        ->callTableAction('snapshot', data: ['version' => 'v2.0'])
        ->assertNotified('Snapshot queued');

    Queue::assertPushed(BuildDesignSnapshot::class, fn ($job) => $job->design->is($design) && $job->version === 'v2.0');
});

it('exports what designers measured for a driver, published designs only unless asked', function () {
    $driver = Driver::factory()->create();
    DesignDriver::factory()->for(Design::factory()->create(['active' => true]))->create(['driver_id' => $driver->id, 'specifications' => ['Fs' => '40']]);
    DesignDriver::factory()->for(Design::factory()->create(['active' => false]))->create(['driver_id' => $driver->id, 'specifications' => ['Fs' => '99']]);

    $count = fn (bool $all): int => count(\Maatwebsite\Excel\Facades\Excel::toArray(new \App\Exports\SpecificationsExport([]), DriverResource::specExport($driver, $all)->getFile()->getPathname())[0]) - 1;

    expect($count(false))->toBe(1)->and($count(true))->toBe(2);
});

it('creates and edits users including the columns that are not mass assignable', function () {
    Livewire::actingAs($this->admin)->test(UserResource\Pages\CreateUser::class)
        ->fillForm([
            'name' => 'Grace Admin-made',
            'username' => 'grace',
            'email' => 'grace@example.com',
            'password' => 'secret-password',
            'roles' => [\Spatie\Permission\Models\Role::findByName('registered')->id],
            'email_verified_at' => '2026-10-01 10:00:00',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::where('username', 'grace')->firstOrFail();
    expect($user->email_verified_at)->not->toBeNull()
        ->and($user->avatar)->toBe('demo/default.png')
        ->and(\Illuminate\Support\Facades\Hash::check('secret-password', $user->password))->toBeTrue();

    Livewire::actingAs($this->admin)->test(UserResource\Pages\EditUser::class, ['record' => $user->getRouteKey()])
        ->fillForm(['dark_mode' => true, 'messenger_color' => '#7e22ce', 'password' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    $user->refresh();
    expect((bool) $user->dark_mode)->toBeTrue()
        ->and($user->messenger_color)->toBe('#7e22ce')
        ->and(\Illuminate\Support\Facades\Hash::check('secret-password', $user->password))->toBeTrue();
});

it('resets two-factor and marks e-mail verified from the user page', function () {
    $user = User::factory()->create();
    $user->forceFill(['email_verified_at' => null, 'two_factor_secret' => 'x', 'two_factor_confirmed_at' => now()])->save();

    Livewire::actingAs($this->admin)->test(UserResource\Pages\EditUser::class, ['record' => $user->getRouteKey()])
        ->callAction('resetTwoFactor')
        ->callAction('verifyEmail');

    $user->refresh();
    expect($user->two_factor_secret)->toBeNull()->and($user->email_verified_at)->not->toBeNull();
});
