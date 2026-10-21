<?php

use App\Models\User;
use App\Support\SeasonalTheme;

function switchSeasonOn(string $key): void
{
    app(SeasonalTheme::class)->set($key);
}

it('renders no season markup by default', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->not->toContain('data-season')
        ->and($html)->not->toContain('season-decor')
        ->and($html)->not->toContain('season-snow')
        ->and($html)->not->toContain('--brand-');
});

it('sets data-season, the colour overrides and the decor when a season is active', function () {
    switchSeasonOn('halloween');

    $this->get('/')
        ->assertOk()
        ->assertSee('<html lang="en" class="" data-season="halloween">', false)
        ->assertSee('html[data-season="halloween"]{', false)
        ->assertSee('data-season-decor="halloween"', false)
        ->assertSee('season-garnish--halloween', false)
        ->assertSee('id="sg-halloween"', false)
        ->assertDontSee('season-snow', false);
});

it('adds the falling snow layer only for a season whose effect is snow', function () {
    switchSeasonOn('winter_holidays');

    $this->get('/')->assertOk()->assertSee('season-snow', false)->assertSee('id="sg-winter"', false);

    switchSeasonOn('summer');

    $this->get('/')->assertOk()->assertDontSee('season-snow', false)->assertSee('id="sg-summer"', false);
});

it('decorates the dashboard layout too, with the effect but no header garnish', function () {
    switchSeasonOn('winter_holidays');

    $html = $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk()->getContent();

    expect($html)->toContain('data-season="winter_holidays"')
        ->and($html)->toContain('season-snow')
        ->and($html)->not->toContain('season-garnish');
});

it('shows an admin the previewed season with a notice, without switching it on', function () {
    $this->actingAs(adminUser())
        ->get('/?preview_season=spring')
        ->assertOk()
        ->assertSee('data-season="spring"', false)
        ->assertSee('Previewing')
        ->assertSee('Leave preview');

    expect(app(SeasonalTheme::class)->configuredKey())->toBe('none');
});

it('ignores ?preview_season for guests and regular users', function () {
    $this->get('/?preview_season=spring')->assertOk()->assertDontSee('data-season', false)->assertDontSee('Previewing');

    $this->actingAs(User::factory()->create())
        ->get('/?preview_season=spring')
        ->assertOk()
        ->assertDontSee('data-season', false)
        ->assertDontSee('Previewing');
});

it('shows the live season to everybody, not just admins', function () {
    switchSeasonOn('valentines');

    $this->get('/')->assertOk()->assertSee('data-season="valentines"', false)->assertDontSee('Previewing');
});

it('falls back to the default look when the stored season is unknown', function () {
    Wave\Setting::create(['key' => 'site.season', 'display_name' => 'Season', 'value' => 'groundhog_day', 'type' => 'text', 'order' => 1]);

    $this->get('/')->assertOk()->assertDontSee('data-season', false)->assertDontSee('season-decor', false);
});

it('uses brand colour classes in the header and footer', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('class="bg-header"')
        ->and($html)->toContain('bg-footer')
        ->and($html)->not->toContain('bg-purple-');
});
