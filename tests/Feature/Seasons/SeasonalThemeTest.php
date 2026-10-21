<?php

use App\Support\SeasonalTheme;
use Illuminate\Http\Request;
use Wave\Setting;

function seasonalTheme(): SeasonalTheme
{
    return app(SeasonalTheme::class);
}

function previewRequest(?string $season): Request
{
    return Request::create('/', 'GET', $season === null ? [] : ['preview_season' => $season]);
}

it('defaults to no season', function () {
    expect(seasonalTheme()->configuredKey())->toBe('none')
        ->and(seasonalTheme()->css('none'))->toBe('');
});

it('uses the season stored in the site.season setting', function () {
    Setting::create(['key' => 'site.season', 'display_name' => 'Season', 'value' => 'halloween', 'type' => 'text', 'order' => 1]);

    expect(seasonalTheme()->configuredKey())->toBe('halloween');
});

it('falls back to none for an unknown or empty setting value', function (?string $value) {
    Setting::create(['key' => 'site.season', 'display_name' => 'Season', 'value' => $value, 'type' => 'text', 'order' => 1]);

    expect(seasonalTheme()->configuredKey())->toBe('none');
})->with(['an unknown key' => 'groundhog_day', 'empty' => '', 'null' => null]);

it('set() stores the setting and the change is visible straight away (cache busted)', function () {
    expect(seasonalTheme()->configuredKey())->toBe('none'); // warms Wave's cached settings and the memo

    seasonalTheme()->set('spring');
    expect(seasonalTheme()->configuredKey())->toBe('spring')
        ->and(Setting::where('key', 'site.season')->value('value'))->toBe('spring');

    seasonalTheme()->set('none');
    expect(seasonalTheme()->configuredKey())->toBe('none');
});

it('notices a change made directly on the Setting model in the same request', function () {
    seasonalTheme()->set('summer');
    expect(seasonalTheme()->configuredKey())->toBe('summer');

    Setting::where('key', 'site.season')->first()->update(['value' => 'valentines']);

    expect(seasonalTheme()->configuredKey())->toBe('valentines');
});

it('refuses to switch on an unknown season', function () {
    expect(fn () => seasonalTheme()->set('groundhog_day'))->toThrow(InvalidArgumentException::class);
});

it('lets admins preview a season with ?preview_season and ignores it for everybody else', function () {
    seasonalTheme()->set('winter_holidays');
    $admin = adminUser();
    $member = App\Models\User::factory()->create();

    expect(seasonalTheme()->keyFor(previewRequest('halloween'), $admin))->toBe('halloween')
        ->and(seasonalTheme()->previewKey(previewRequest('halloween'), $admin))->toBe('halloween')
        ->and(seasonalTheme()->keyFor(previewRequest('halloween'), $member))->toBe('winter_holidays')
        ->and(seasonalTheme()->previewKey(previewRequest('halloween'), $member))->toBeNull()
        ->and(seasonalTheme()->keyFor(previewRequest('halloween'), null))->toBe('winter_holidays');
});

it('ignores an unknown preview key, and a preview of none shows the default look to an admin', function () {
    seasonalTheme()->set('winter_holidays');
    $admin = adminUser();

    expect(seasonalTheme()->keyFor(previewRequest('nope'), $admin))->toBe('winter_holidays')
        ->and(seasonalTheme()->keyFor(previewRequest('none'), $admin))->toBe('none')
        ->and(seasonalTheme()->keyFor(previewRequest(null), $admin))->toBe('winter_holidays');
});

it('prints CSS variable overrides only for the active season and keeps the defaults untouched', function () {
    $css = seasonalTheme()->css('halloween');

    expect($css)->toStartWith('html[data-season="halloween"]{')
        ->and($css)->toContain('--brand-950:67 20 7')   // #431407 as RGB channels
        ->and($css)->toContain('--header-bg:27 16 48')  // #1b1030
        ->and($css)->toContain('--footer-bg:20 12 36')
        ->and(seasonalTheme()->css('unknown'))->toBe('');

    // a season that only re-colours header/footer and brand leaves `accent` alone
    expect(seasonalTheme()->css('winter_holidays'))->not->toContain('--accent-');
});

it('turns hex colours into the RGB channels the CSS variables hold', function () {
    expect(SeasonalTheme::channels('#3b0764'))->toBe('59 7 100')
        ->and(SeasonalTheme::channels('fff'))->toBe('255 255 255');
});

it('computes WCAG contrast ratios', function () {
    expect(SeasonalTheme::contrast('#000000', '#ffffff'))->toEqualWithDelta(21.0, 0.01)
        ->and(SeasonalTheme::contrast('#ffffff', '#ffffff'))->toEqualWithDelta(1.0, 0.001)
        ->and(SeasonalTheme::contrast('#777777', '#ffffff'))->toEqualWithDelta(4.48, 0.02);
});

it('lists every configured season with a label for selects', function () {
    expect(seasonalTheme()->options())->toHaveKeys(['none', 'winter_holidays', 'halloween', 'spring', 'summer', 'valentines', 'independence_day'])
        ->and(seasonalTheme()->options()['none'])->toBe('Default');
});
