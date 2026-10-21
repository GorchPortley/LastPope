<?php

use App\Filament\Pages\Appearance;
use App\Models\User;
use App\Support\SeasonalTheme;
use Livewire\Livewire;
use Wave\Setting;

it('opens for admins and lists every season with its swatches', function () {
    $this->actingAs(adminUser())
        ->get('/admin/appearance')
        ->assertOk()
        ->assertSee('Seasonal theme')
        ->assertSee('Winter holidays')
        ->assertSee('Halloween')
        ->assertSee('Independence Day')
        ->assertSee('preview_season=halloween', false);
});

it('is not available to regular users or guests', function () {
    $this->actingAs(User::factory()->create())->get('/admin/appearance')->assertForbidden();

    auth()->logout();
    $this->get('/admin/appearance')->assertRedirect();

    expect(Appearance::canAccess())->toBeFalse();
});

it('starts on the season that is live and saves a new one', function () {
    app(SeasonalTheme::class)->set('spring');

    Livewire::actingAs(adminUser())
        ->test(Appearance::class)
        ->assertSet('season', 'spring')
        ->set('season', 'halloween')
        ->call('save')
        ->assertHasNoErrors()
        ->assertNotified();

    expect(Setting::where('key', 'site.season')->value('value'))->toBe('halloween')
        ->and(app(SeasonalTheme::class)->configuredKey())->toBe('halloween');
});

it('can switch back to the default look', function () {
    app(SeasonalTheme::class)->set('summer');

    Livewire::actingAs(adminUser())
        ->test(Appearance::class)
        ->set('season', 'none')
        ->call('save');

    expect(app(SeasonalTheme::class)->configuredKey())->toBe('none');
});

it('rejects a season that does not exist', function () {
    Livewire::actingAs(adminUser())
        ->test(Appearance::class)
        ->set('season', 'groundhog_day')
        ->call('save')
        ->assertHasErrors(['season' => 'in']);

    expect(Setting::where('key', 'site.season')->exists())->toBeFalse();
});

it('previews the selected season on the home page', function () {
    Livewire::actingAs(adminUser())
        ->test(Appearance::class)
        ->set('season', 'valentines')
        ->assertSet('season', 'valentines')
        ->assertSee('preview_season=valentines', false);

    expect(app(SeasonalTheme::class)->previewUrl('valentines'))->toEndWith('/?preview_season=valentines');
});

it('does not let a regular user save a season', function () {
    // The page route is behind the admin panel; calling the component directly is refused as well.
    Livewire::actingAs(User::factory()->create())
        ->test(Appearance::class)
        ->assertForbidden();
});
