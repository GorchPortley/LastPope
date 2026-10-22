<?php

use App\Support\BrandColors;

/** The default palette lives in app.css as RGB channels; the PDF and the mail theme need hex values. */
function cssPalette(string $name): array
{
    $css = file_get_contents(resource_path('themes/blank/assets/css/app.css'));
    preg_match_all('/--'.$name.'-(\d+):\s*(\d+ \d+ \d+);/', $css, $matches, PREG_SET_ORDER);

    return collect($matches)->mapWithKeys(fn ($m) => [(int) $m[1] => BrandColors::hexFromChannels($m[2])])->all();
}

it('matches the site palette in app.css', function () {
    expect(cssPalette('brand'))->toBe(BrandColors::BRAND)
        ->and(cssPalette('accent'))->toBe(BrandColors::ACCENT)
        ->and(cssPalette('link'))->toBe(BrandColors::LINK);
});

it('turns CSS channels into hex', function () {
    expect(BrandColors::hexFromChannels('147 51 234'))->toBe('#9333ea')
        ->and(BrandColors::hexFromChannels(' 59  7 100 '))->toBe('#3b0764');
});

it('is the palette of the mail theme: the brand band, buttons, links and the amber rule', function () {
    $css = strtolower(file_get_contents(resource_path('views/vendor/mail/html/themes/sdlabs.css')));

    foreach ([BrandColors::brand(50), BrandColors::brand(100), BrandColors::brand(200), BrandColors::brand(500), BrandColors::brand(700), BrandColors::brand(900), BrandColors::brand(950), BrandColors::accent(300)] as $hex) {
        expect($css)->toContain($hex);
    }

    // header band = the site header colour, buttons = brand-700
    expect($css)->toMatch('/\.header-band\s*\{[^}]*background-color:\s*'.preg_quote(BrandColors::brand(950), '/').'/')
        ->and($css)->toMatch('/\.button-primary\s*\{[^}]*background-color:\s*'.preg_quote(BrandColors::brand(700), '/').'/');
});

it('is plain CSS in the mail theme: no variables, no imports, no remote resources', function () {
    $css = file_get_contents(resource_path('views/vendor/mail/html/themes/sdlabs.css'));

    expect($css)->not->toContain('var(--')->not->toContain('@import')->not->toContain('url(')->not->toContain('@font-face');
});

it('has the same default header colour as the site header', function () {
    $css = file_get_contents(resource_path('themes/blank/assets/css/app.css'));

    // --header-bg is the darkest brand shade, which is what the PDF band and the mail band use
    expect($css)->toContain('--header-bg: var(--brand-950);')
        ->and(BrandColors::brand(950))->toBe('#3b0764');
});
