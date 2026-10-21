import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import fs from 'fs';
import path from 'path';

const themeFilePath = path.resolve(__dirname, 'theme.json');
const activeTheme = fs.existsSync(themeFilePath) ? JSON.parse(fs.readFileSync(themeFilePath, 'utf8')).name : 'blank';
console.log(`Active theme: ${activeTheme}`);

// Flowbite's compiled stylesheet is Tailwind v4 output (`@layer base`, ...), which this project's Tailwind v3 PostCSS
// pass rejects, so Vite cannot process it as an entry. Copy it untouched to public/build/assets/flowbite.min.css;
// resources/themes/blank/partials/head.blade.php links it after app.css (as the CDN link used to be).
const flowbiteCssSource = path.resolve(__dirname, 'node_modules/flowbite/dist/flowbite.min.css');
const flowbiteCss = () => ({
    name: 'copy-flowbite-css',
    configureServer() {
        // `npm run dev` writes no bundle, so place the file where the layout looks for it
        const dest = path.resolve(__dirname, 'public/build/assets/flowbite.min.css');
        fs.mkdirSync(path.dirname(dest), { recursive: true });
        fs.copyFileSync(flowbiteCssSource, dest);
    },
    generateBundle() {
        this.emitFile({ type: 'asset', fileName: 'assets/flowbite.min.css', source: fs.readFileSync(flowbiteCssSource) });
    },
});

export default defineConfig({
    plugins: [
        flowbiteCss(),
        laravel({
            input: [
                `resources/themes/${activeTheme}/assets/css/app.css`,
                `resources/themes/${activeTheme}/assets/css/seasons.css`,
                `resources/themes/${activeTheme}/assets/js/app.js`,
                'resources/css/filament/admin/theme.css',
            ],
            refresh: [
                `resources/themes/${activeTheme}/**/*`,
            ],
        }),
    ],
});