<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Wave\Setting;

/**
 * Resolves which seasonal theme (config/seasons.php) the public site shows and turns it into CSS.
 *
 * The active season is a Wave setting (`site.season`, see config('seasons.setting')). Wave's Setting model
 * keeps all settings in one cached array and forgets it whenever a setting is saved or deleted, so a change
 * is visible on the next request; this class adds no cache of its own beyond a per-instance memo that
 * set() / flush() reset. An unknown or empty value means the default look ("none").
 *
 * Admins may preview a season on any public page with `?preview_season=<key>`; for anyone else the
 * parameter is ignored. The user is passed in (or taken from the request), never from auth().
 */
class SeasonalTheme
{
    public const DEFAULT = 'none';

    /** Shades every palette (brand, accent, link) is defined in; matches tailwind.config.js. */
    public const SHADES = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];

    private ?string $memo = null;

    /** @return array<string, array<string, mixed>> all seasons by key, each with its own `key` */
    public function all(): array
    {
        $seasons = [];

        foreach ((array) config('seasons.seasons', []) as $key => $season) {
            $seasons[(string) $key] = array_merge(
                ['label' => (string) $key, 'description' => '', 'colors' => [], 'garnish' => null, 'effect' => 'none'],
                (array) $season,
                ['key' => (string) $key],
            );
        }

        // The default look always exists, even if the config forgot it.
        return $seasons + [self::DEFAULT => ['key' => self::DEFAULT, 'label' => 'Default', 'description' => '', 'colors' => [], 'garnish' => null, 'effect' => 'none']];
    }

    public function has(?string $key): bool
    {
        return is_string($key) && array_key_exists($key, $this->all());
    }

    /** @return array<string, mixed> */
    public function get(string $key): array
    {
        $all = $this->all();

        return $all[$key] ?? $all[self::DEFAULT];
    }

    /** @return array<string, string> key => label, for selects */
    public function options(): array
    {
        return array_map(fn (array $season) => (string) $season['label'], $this->all());
    }

    /** The season an admin switched on (the setting), never an unknown key. */
    public function configuredKey(): string
    {
        return $this->memo ??= $this->normalize(Setting::get((string) config('seasons.setting', 'site.season')));
    }

    /** Switch the season on for everybody. Throws for a key that is not in config/seasons.php. */
    public function set(string $key): void
    {
        if (! $this->has($key)) {
            throw new \InvalidArgumentException("Unknown season [{$key}].");
        }

        // The Setting model's saved event forgets Wave's cached settings; flush() resets our memo too.
        Setting::updateOrCreate(
            ['key' => (string) config('seasons.setting', 'site.season')],
            ['display_name' => 'Season', 'value' => $key, 'details' => 'Active seasonal theme (Admin > Appearance).', 'type' => 'text', 'order' => 99, 'group' => 'Site'],
        );

        $this->flush();
    }

    public function flush(): void
    {
        $this->memo = null;
    }

    /** The preview key from the request when the user is an admin and the key exists, else null. */
    public function previewKey(?Request $request, ?Authenticatable $user): ?string
    {
        $requested = $request?->query((string) config('seasons.preview_param', 'preview_season'));

        if (! is_string($requested) || ! $this->has($requested)) {
            return null;
        }

        return $user && method_exists($user, 'hasRole') && $user->hasRole('admin') ? $requested : null;
    }

    /** The season to render for this request: an admin's preview, else the active one. */
    public function keyFor(?Request $request, ?Authenticatable $user): string
    {
        return $this->previewKey($request, $user) ?? $this->configuredKey();
    }

    /** Same as keyFor() for a request (the user comes from the request), memoised on it. */
    public function forRequest(Request $request): string
    {
        return $request->attributes->get('season.key')
            ?? tap($this->keyFor($request, $request->user()), fn ($key) => $request->attributes->set('season.key', $key));
    }

    public function isPreview(Request $request): bool
    {
        return $this->previewKey($request, $request->user()) !== null;
    }

    /** URL of $path (the home page by default) that previews $key; only admins see it take effect. */
    public function previewUrl(string $key, string $path = '/'): string
    {
        return url($path).'?'.http_build_query([(string) config('seasons.preview_param', 'preview_season') => $key]);
    }

    /**
     * The CSS that applies a season: variable overrides on `html[data-season="key"]`. Empty for the default
     * look (the defaults live in app.css) and for unknown keys.
     */
    public function css(string $key): string
    {
        if ($key === self::DEFAULT || ! $this->has($key)) {
            return '';
        }

        $declarations = [];

        foreach ($this->get($key)['colors'] as $name => $value) {
            if (is_array($value)) {
                foreach (self::SHADES as $shade) {
                    if (isset($value[$shade])) {
                        $declarations[] = "--{$name}-{$shade}:".self::channels($value[$shade]);
                    }
                }
            } elseif (in_array($name, ['header-bg', 'footer-bg'], true)) {
                $declarations[] = "--{$name}:".self::channels($value);
            }
        }

        return $declarations === [] ? '' : 'html[data-season="'.$key.'"]{'.implode(';', $declarations).'}';
    }

    /**
     * Colours that characterise a season, for the admin swatches: header, main colour, link text, highlight.
     *
     * @return list<string> hex colours
     */
    public function swatches(string $key): array
    {
        $colors = $this->get($key)['colors'];
        $default = $this->get(self::DEFAULT)['colors'];

        return [
            $colors['header-bg'] ?? $colors['brand'][950] ?? $default['brand'][950],
            $colors['brand'][600] ?? $default['brand'][600],
            $colors['brand'][100] ?? $default['brand'][100],
            $colors['accent'][400] ?? $default['accent'][400],
        ];
    }

    /** "#3b0764" => "59 7 100" (the RGB channels the CSS variables hold). */
    public static function channels(string $hex): string
    {
        [$r, $g, $b] = self::rgb($hex);

        return "{$r} {$g} {$b}";
    }

    /** WCAG 2.x contrast ratio between two hex colours (1 to 21). */
    public static function contrast(string $hexA, string $hexB): float
    {
        $a = self::luminance($hexA);
        $b = self::luminance($hexB);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    private function normalize(mixed $value): string
    {
        return is_string($value) && $this->has($value) ? $value : self::DEFAULT;
    }

    /** @return array{int, int, int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            throw new \InvalidArgumentException("Not a hex colour: [{$hex}].");
        }

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(function (int $channel): float {
            $c = $channel / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }
}
