import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import preset from './vendor/filament/support/tailwind.config.preset';
import fs from 'fs';
import path from 'path';

const themeFilePath = path.resolve(__dirname, 'theme.json');
const activeTheme = fs.existsSync(themeFilePath) ? JSON.parse(fs.readFileSync(themeFilePath, 'utf8')).name : 'blank';

// Site palettes are CSS custom properties holding RGB channels (see resources/themes/blank/assets/css/app.css),
// so an admin-selected season (config/seasons.php) can re-colour the public site without a rebuild.
const shades = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];
const palette = (name) => Object.fromEntries(shades.map((s) => [s, `rgb(var(--${name}-${s}) / <alpha-value>)`]));

/** @type {import('tailwindcss').Config} */
export default {
    presets: [preset],
    content: [
        './app/Filament/**/*.php',
        './resources/views/filament/**/*.blade.php',
        './vendor/filament/**/*.blade.php',
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/views/components/**/*.blade.php',
        './resources/views/components/blade.php',
        './wave/resources/views/**/*.blade.php',
        './resources/themes/' + activeTheme + '/**/*.blade.php',
        './resources/plugins/**/*.php',
        './config/*.php'
    ],

    theme: {
        extend: {
            // Datasheet look (docs/STYLE.md): Archivo (narrowed with `font-condensed` for headings) and Azeret Mono for
            // numbers. Served from the build (npm @fontsource), so no font request leaves the site.
            fontFamily: {
                sans: ['"Archivo Variable"', 'Archivo', ...defaultTheme.fontFamily.sans],
                mono: ['"Azeret Mono"', ...defaultTheme.fontFamily.mono],
            },
            colors: {
                // neutrals of the datasheet look: ink text, paper page, rules between things, trace for chart lines
                ink: { DEFAULT: '#161b26', 2: '#4a5366', 3: '#7a8396' },
                paper: '#f2f4f7',
                rule: '#cfd5df',
                hair: '#e5e9ef',
                trace: '#7c3fc2',
                brand: palette('brand'),   // purple: header, footer, buttons, badges, links
                accent: palette('accent'), // amber: hover highlights on the dark header/footer, cart badge
                link: palette('link'),     // blue: link hover, focus rings, carousel dots
                header: 'rgb(var(--header-bg) / <alpha-value>)',
                footer: 'rgb(var(--footer-bg) / <alpha-value>)',
            },
            animation: {
                'marquee': 'marquee 25s linear infinite',
            },
            keyframes: {
                'marquee': {
                    from: { transform: 'translateX(0)' },
                    to: { transform: 'translateX(-100%)' },
                }
            } 
        },
    },

    plugins: [
        forms,
        require('@tailwindcss/typography'),
        // Narrow Archivo for headings and labels: `font-condensed` (Archivo's width axis, see docs/STYLE.md).
        ({ addUtilities }) => addUtilities({ '.font-condensed': { 'font-stretch': '72%' } }),
    ],
};