<?php

use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

beforeEach(function () {
    $this->user = User::factory()->create();
});

function addressesPage(object $test, User $user): Livewire\Features\SupportTesting\Testable
{
    return voltPage($test, $user, '/settings/addresses', 'settings.addresses');
}

function fillAddress(Livewire\Features\SupportTesting\Testable $page, array $overrides = []): Livewire\Features\SupportTesting\Testable
{
    $values = $overrides + [
        'name' => 'Ada Builder', 'line1' => '12 Speaker Street', 'line2' => '', 'city' => 'Austin', 'state' => 'TX',
        'postal_code' => '78701', 'country' => 'US', 'phone' => '', 'is_default' => false,
    ];

    foreach ($values as $field => $value) {
        $page->set('form.'.$field, $value);
    }

    return $page;
}

it('is linked from the settings sidebar', function () {
    $this->actingAs($this->user)->get('/settings/profile')->assertOk()->assertSee(route('settings.addresses'), false)->assertSee('Addresses');
    $this->actingAs($this->user)->get('/settings/addresses')->assertOk()->assertSee('Shipping addresses')->assertSee('No addresses yet');
});

it('adds addresses and makes the first one the default', function () {
    $page = addressesPage($this, $this->user)->call('add');
    fillAddress($page)->call('save')->assertHasNoErrors()->assertNotified('Address added')->assertSet('showForm', false);

    $first = $this->user->addresses()->sole();
    expect($first->is_default)->toBeTrue()->and($first->city)->toBe('Austin');

    $page->call('add');
    fillAddress($page, ['name' => 'Second', 'city' => 'Berlin', 'country' => 'DE'])->call('save');

    expect($this->user->addresses()->count())->toBe(2)
        ->and($this->user->addresses()->where('name', 'Second')->first()->is_default)->toBeFalse()
        ->and($first->fresh()->is_default)->toBeTrue();

    $this->actingAs($this->user)->get('/settings/addresses')->assertSee('Ada Builder')->assertSee('Second')->assertSee('Germany');
});

it('validates the address form', function () {
    $page = addressesPage($this, $this->user)->call('add');

    fillAddress($page, ['name' => '', 'line1' => '', 'city' => '', 'postal_code' => '', 'country' => 'ZZ', 'phone' => str_repeat('1', 40)])
        ->call('save')
        ->assertHasErrors(['form.name' => 'required', 'form.line1' => 'required', 'form.city' => 'required', 'form.postal_code' => 'required', 'form.country', 'form.phone']);

    expect($this->user->addresses()->count())->toBe(0);
});

it('edits an address and switches the default', function () {
    $a = addressFor($this->user, 'US', true);
    $b = addressFor($this->user, 'DE', false);

    $page = addressesPage($this, $this->user)->call('edit', $b->id)->assertSet('editingId', $b->id)->assertSet('form.city', $b->city);
    $page->set('form.city', 'Hamburg')->set('form.is_default', true)->call('save')->assertHasNoErrors()->assertNotified('Address updated');

    expect($b->fresh()->city)->toBe('Hamburg')->and($b->fresh()->is_default)->toBeTrue()->and($a->fresh()->is_default)->toBeFalse();

    $page->call('makeDefault', $a->id)->assertNotified('Default address changed');
    expect($a->fresh()->is_default)->toBeTrue()->and($b->fresh()->is_default)->toBeFalse();
});

it('deletes an address and passes the default on', function () {
    $a = addressFor($this->user, 'US', true);
    $b = addressFor($this->user, 'DE', false);

    addressesPage($this, $this->user)->call('delete', $a->id)->assertNotified('Address deleted');

    expect(Address::find($a->id))->toBeNull()->and($b->fresh()->is_default)->toBeTrue();
});

it('never touches somebody elses address', function () {
    $foreign = addressFor(User::factory()->create(), 'US', true);
    $page = addressesPage($this, $this->user);

    foreach (['edit', 'delete', 'makeDefault'] as $method) {
        expect(fn () => $page->call($method, $foreign->id))->toThrow(ModelNotFoundException::class);
    }

    expect($foreign->fresh())->not->toBeNull()->and($this->user->addresses()->count())->toBe(0);
});

it('offers the saved addresses at checkout', function () {
    $address = addressFor($this->user, 'US', true);
    fillCart($this->user, [sellableListing()]);

    $this->actingAs($this->user)->get('/checkout')->assertOk()->assertSee($address->line1)->assertSee('Default');
});
