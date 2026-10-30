<?php

namespace App\Support;

/**
 * asset() with a short content hash as a query string, for files in public/ that are not built by Vite.
 *
 * nginx marks images, CSS and JS as cacheable for a year ("immutable"), so a browser keeps an old copy of
 * public/images/... after it changes. The hash changes with the content, which makes it a new URL.
 *
 *     VersionedAsset::url('images/blog/post-placeholder.svg')   // .../images/blog/post-placeholder.svg?v=1a2b3c4d
 */
class VersionedAsset
{
    /** @var array<string, string> */
    private static array $hashes = [];

    public static function url(string $path): string
    {
        $path = ltrim($path, '/');

        if (! array_key_exists($path, self::$hashes)) {
            $file = public_path($path);
            self::$hashes[$path] = is_file($file) ? substr(md5_file($file), 0, 8) : '';
        }

        return asset($path).(self::$hashes[$path] !== '' ? '?v='.self::$hashes[$path] : '');
    }
}
