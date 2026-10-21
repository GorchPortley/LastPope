<?php

use App\Support\SeasonalTheme;

/** Season keys for the datasets below; datasets are built before the app boots, so read the config file directly. */
function seasonKeysFromConfig(): array
{
    return array_keys((require dirname(__DIR__, 3).'/config/seasons.php')['seasons']);
}

/**
 * Guards config/seasons.php: a new season entry is checked here automatically (colours, contrast, artwork).
 * Text colours used by the public theme: white on brand-600..950, header-bg and footer-bg; brand-700 and brand-800 as
 * text on white / brand-50 (links, badges); brand-100..300 on the footer; accent-200 as hover colour on header and
 * footer; brand-950 on accent-400 (cart badge); white on link-600 (buttons).
 */
function seasonConfig(string $key): array
{
    return app(SeasonalTheme::class)->get($key);
}

/** The effective palette of a season: its own colours over the default ones. */
function effectivePalette(string $key): array
{
    $default = seasonConfig('none')['colors'];
    $colors = seasonConfig($key)['colors'];
    $merged = [];

    foreach (['brand', 'accent', 'link'] as $name) {
        $merged[$name] = ($colors[$name] ?? []) + $default[$name];
    }

    $merged['header-bg'] = $colors['header-bg'] ?? $merged['brand'][950];
    $merged['footer-bg'] = $colors['footer-bg'] ?? $merged['brand'][950];

    return $merged;
}

it('has the seasons the owner asked for', function () {
    expect(array_keys(config('seasons.seasons')))->toContain('none', 'winter_holidays', 'halloween', 'spring', 'summer', 'valentines', 'independence_day');
});

it('defines every season completely and with valid values', function (string $key) {
    $season = seasonConfig($key);

    expect($season['label'])->toBeString()->not->toBeEmpty()
        ->and($season['description'])->toBeString()
        ->and($season['effect'])->toBeIn(['none', 'snow']);

    foreach ($season['colors'] as $name => $value) {
        expect($name)->toBeIn(['brand', 'accent', 'link', 'header-bg', 'footer-bg']);

        foreach ((array) $value as $shade => $hex) {
            expect($hex)->toMatch('/^#[0-9a-f]{6}$/i');
            if (is_array($value)) {
                expect($shade)->toBeIn(SeasonalTheme::SHADES);
            }
        }

        if (is_array($value)) {
            expect(array_keys($value))->toBe(SeasonalTheme::SHADES);
        }
    }

    if ($season['garnish'] !== null) {
        expect(view()->exists('theme::components.seasonal.garnish.'.$season['garnish']))->toBeTrue("garnish view [{$season['garnish']}] is missing");
    }
})->with(fn () => seasonKeysFromConfig());

it('keeps white text readable (WCAG AA, 4.5:1) wherever the theme puts it on a season colour', function (string $key) {
    $p = effectivePalette($key);
    $white = '#ffffff';
    $ratio = fn (string $a, string $b) => round(SeasonalTheme::contrast($a, $b), 2);

    foreach ([600, 700, 800, 900, 950] as $shade) {
        expect($ratio($white, $p['brand'][$shade]))->toBeGreaterThanOrEqual(4.5, "white on brand-{$shade}");
    }

    expect($ratio($white, $p['header-bg']))->toBeGreaterThanOrEqual(4.5, 'white on the header')
        ->and($ratio($white, $p['footer-bg']))->toBeGreaterThanOrEqual(4.5, 'white on the footer')
        ->and($ratio($white, $p['link']['600']))->toBeGreaterThanOrEqual(4.5, 'white on link-600 (buttons)');
})->with(fn () => seasonKeysFromConfig());

it('keeps the rest of the theme text readable', function (string $key) {
    $p = effectivePalette($key);
    $white = '#ffffff';
    $ratio = fn (string $a, string $b) => round(SeasonalTheme::contrast($a, $b), 2);

    expect($ratio($p['brand'][700], $white))->toBeGreaterThanOrEqual(4.5, 'links: brand-700 on white')
        ->and($ratio($p['brand'][800], $p['brand'][50]))->toBeGreaterThanOrEqual(4.5, 'badge: brand-800 on brand-50')
        ->and($ratio($p['brand'][950], $p['accent'][400]))->toBeGreaterThanOrEqual(4.5, 'cart badge: brand-950 on accent-400');

    foreach ([100, 200, 300] as $shade) {
        expect($ratio($p['brand'][$shade], $p['footer-bg']))->toBeGreaterThanOrEqual(4.5, "footer text: brand-{$shade} on the footer");
    }

    expect($ratio($p['accent'][200], $p['header-bg']))->toBeGreaterThanOrEqual(4.5, 'header hover: accent-200 on the header')
        ->and($ratio($p['accent'][200], $p['footer-bg']))->toBeGreaterThanOrEqual(4.5, 'footer hover: accent-200 on the footer');
})->with(fn () => seasonKeysFromConfig());

it('keeps the default palette in config in step with the :root variables of app.css', function () {
    $css = file_get_contents(resource_path('themes/blank/assets/css/app.css'));
    preg_match('/:root\s*\{(.*?)\n\}/s', $css, $root);
    preg_match_all('/--(brand|accent|link)-(\d+):\s*(\d+ \d+ \d+);/', $root[1], $matches, PREG_SET_ORDER);

    expect($matches)->toHaveCount(33);

    foreach ($matches as [, $name, $shade, $channels]) {
        expect(SeasonalTheme::channels(seasonConfig('none')['colors'][$name][(int) $shade]))->toBe($channels, "--{$name}-{$shade}");
    }
});

it('defines the Tailwind palettes the templates use', function () {
    $tailwind = file_get_contents(base_path('tailwind.config.js'));

    expect($tailwind)->toContain("brand: palette('brand')", "accent: palette('accent')", "link: palette('link')", 'var(--header-bg)', 'var(--footer-bg)');
});

it('leaves no hard-coded purple in the public theme', function () {
    $files = array_merge(
        themeFilesMatching(resource_path('themes/blank'), '/\.(php|css)$/'),
        themeFilesMatching(resource_path('views/components'), '/\.php$/'),
        themeFilesMatching(resource_path('views/livewire'), '/\.php$/', exclude: 'wave'),
        themeFilesMatching(resource_path('views/marketplace'), '/\.php$/'),
    );

    foreach ($files as $file) {
        expect(preg_match('/\bpurple-\d{2,3}\b/', file_get_contents($file)))->toBe(0, "hard-coded purple in {$file}");
    }
});

function themeFilesMatching(string $dir, string $pattern, ?string $exclude = null): array
{
    if (! is_dir($dir)) {
        return [];
    }

    $found = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($exclude !== null && str_contains($file->getPathname(), DIRECTORY_SEPARATOR.$exclude.DIRECTORY_SEPARATOR)) {
            continue;
        }
        if (preg_match($pattern, $file->getFilename())) {
            $found[] = $file->getPathname();
        }
    }

    return $found;
}
