<?php

/*
|--------------------------------------------------------------------------
| Seasonal themes
|--------------------------------------------------------------------------
|
| An admin switches the season by hand (Admin > Settings > Appearance; stored in the Wave setting
| `site.season`). Nothing here switches by date. See "Seasonal themes" in docs/DEPLOY.md.
|
| To add a season: add one entry below. Each entry has
|
|   label        shown in the admin and on the preview banner
|   description  one line for the admin
|   colors       overrides of the site colours (hex). Anything left out keeps the default look.
|                  brand      shades 50..950, the main colour (Tailwind `brand-*`: buttons, badges, links, header/footer)
|                  accent     shades 50..950, the highlight (`accent-*`: hover colour on the dark header/footer, cart badge)
|                  link       shades 50..950, link hover, focus rings, carousel dots (`link-*`)
|                  header-bg   header background (default: brand-950)
|                  footer-bg   footer background (default: brand-950)
|   garnish      name of a header decoration, a Blade view at
|                resources/themes/blank/components/seasonal/garnish/{name}.blade.php (inline SVG), or null
|   effect       'snow' (CSS-only falling snow, see seasons.css) or 'none'
|
| `none` is the default look. Its colors repeat the :root values in resources/themes/blank/assets/css/app.css (only used
| to draw the swatches; a test keeps both in step).
|
| Keep text readable: white text sits on brand-600..950, header-bg and footer-bg, and brand-100..300 sits on the footer.
| tests/Feature/Seasons/SeasonConfigTest.php checks WCAG AA (4.5:1) for every season, so a new entry is checked for you.
|
*/

$shades = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];

/** Build a shade => hex map from eleven hex colours, lightest (50) first. */
$palette = fn (string $hexes): array => array_combine($shades, preg_split('/\s+/', trim($hexes)));

// Tailwind-derived scales. Where Tailwind's 600 would not carry white text (4.5:1), 600 is a darker pick.
$purple = $palette('#faf5ff #f3e8ff #e9d5ff #d8b4fe #c084fc #a855f7 #9333ea #7e22ce #6b21a8 #581c87 #3b0764');
$amber = $palette('#fffbeb #fef3c7 #fde68a #fcd34d #fbbf24 #f59e0b #d97706 #b45309 #92400e #78350f #451a03');
$blue = $palette('#eff6ff #dbeafe #bfdbfe #93c5fd #60a5fa #3b82f6 #2563eb #1d4ed8 #1e40af #1e3a8a #172554');
$red = $palette('#fef2f2 #fee2e2 #fecaca #fca5a5 #f87171 #ef4444 #dc2626 #b91c1c #991b1b #7f1d1d #450a0a');
$orange = $palette('#fff7ed #ffedd5 #fed7aa #fdba74 #fb923c #f97316 #c2410c #9a3412 #7c2d12 #5c230f #431407');
$violet = $palette('#f5f3ff #ede9fe #ddd6fe #c4b5fd #a78bfa #8b5cf6 #7c3aed #6d28d9 #5b21b6 #4c1d95 #2e1065');
$pink = $palette('#fdf2f8 #fce7f3 #fbcfe8 #f9a8d4 #f472b6 #ec4899 #db2777 #be185d #9d174d #831843 #500724');
$lime = $palette('#f7fee7 #ecfccb #d9f99d #bef264 #a3e635 #84cc16 #4d7c0f #3f6212 #365314 #2a410f #1a2e05');
$sky = $palette('#f0f9ff #e0f2fe #bae6fd #7dd3fc #38bdf8 #0ea5e9 #0369a1 #075985 #0c4a6e #0a3d5c #082f49');
$rose = $palette('#fff1f2 #ffe4e6 #fecdd3 #fda4af #fb7185 #f43f5e #e11d48 #be123c #9f1239 #881337 #4c0519');
$navy = $palette('#eff6ff #dbeafe #bfdbfe #93c5fd #60a5fa #3b82f6 #1d4ed8 #1e40af #1e3a8a #172554 #0b1740');

return [

    /*
    | The Wave setting (settings table, key) that holds the active season key.
    */
    'setting' => 'site.season',

    /*
    | Query parameter an admin can add to any public URL to preview a season without switching it on.
    */
    'preview_param' => 'preview_season',

    'seasons' => [

        'none' => [
            'label' => 'Default',
            'description' => 'The regular purple SDLabs look, no decorations.',
            'colors' => ['brand' => $purple, 'accent' => $amber, 'link' => $blue],
            'garnish' => null,
            'effect' => 'none',
        ],

        'winter_holidays' => [
            'label' => 'Winter holidays',
            'description' => 'Holly red on an evergreen header, icicles along the header and gently falling snow.',
            'colors' => [
                'brand' => $red,
                'header-bg' => '#0b3d2e',
                'footer-bg' => '#08301f',
            ],
            'garnish' => 'winter',
            'effect' => 'snow',
        ],

        'halloween' => [
            'label' => 'Halloween',
            'description' => 'Pumpkin orange on a night-sky header, with bats and hanging pumpkins.',
            'colors' => [
                'brand' => $orange,
                'accent' => $violet,
                'link' => $orange,
                'header-bg' => '#1b1030',
                'footer-bg' => '#140c24',
            ],
            'garnish' => 'halloween',
            'effect' => 'none',
        ],

        'spring' => [
            'label' => 'Spring',
            'description' => 'Blossom pink and fresh lime on a teal header, with a flowering vine.',
            'colors' => [
                'brand' => $pink,
                'accent' => $lime,
                'link' => $pink,
                'header-bg' => '#0f766e',
                'footer-bg' => '#134e4a',
            ],
            'garnish' => 'spring',
            'effect' => 'none',
        ],

        'summer' => [
            'label' => 'Summer',
            'description' => 'Sea blue with a sunny yellow highlight, a sun and a wave line on the header.',
            'colors' => [
                'brand' => $sky,
                'header-bg' => '#0369a1',
                'footer-bg' => '#0c4a6e',
            ],
            'garnish' => 'summer',
            'effect' => 'none',
        ],

        'valentines' => [
            'label' => "Valentine's Day",
            'description' => 'Rose and blush pink on a burgundy header, with a garland of hearts.',
            'colors' => [
                'brand' => $rose,
                'accent' => $pink,
                'link' => $rose,
            ],
            'garnish' => 'valentines',
            'effect' => 'none',
        ],

        'independence_day' => [
            'label' => 'Independence Day',
            'description' => 'Navy, red and white, with bunting along the header.',
            'colors' => [
                'brand' => $navy,
                'accent' => $red,
                'header-bg' => '#0a1f4d',
                'footer-bg' => '#081840',
            ],
            'garnish' => 'independence',
            'effect' => 'none',
        ],

    ],

];
