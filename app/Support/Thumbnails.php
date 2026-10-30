<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManagerStatic as Image;

/**
 * Resized WebP copies of public-disk pictures (design, driver and listing photos, blog images), made on first use.
 *
 * A thumbnail URL points at the file it will be stored as, /storage/thumbs/{width}/{original path}.{mtime}.webp.
 * The first request finds no file there, nginx hands it to Laravel (ThumbnailController), which writes the file and
 * returns it; every later request is a static file with nginx's one-year cache header. The original's modification
 * time is part of the name, so a replaced picture gets a new URL, and only that exact name is ever generated.
 *
 *     Thumbnails::url($design->card_image[0], 480)        // card
 *     Thumbnails::srcset($path, [480, 960])                // "…480… 480w, …960… 960w"
 */
class Thumbnails
{
    /** The only widths that are generated (anything else is a 404, so the disk cannot be filled with sizes). */
    public const WIDTHS = [160, 480, 960, 1600];

    public const DIRECTORY = 'thumbs';

    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    /** The public-disk path of a stored picture value ("/demo/x.jpg" and "demo/x.jpg" are the same file). */
    public static function normalize(?string $path): ?string
    {
        if (blank($path) || Str::startsWith($path, ['http://', 'https://', 'data:'])) {
            return null;
        }

        $path = ltrim($path, '/');

        return Str::startsWith($path, 'storage/') ? Str::after($path, 'storage/') : $path;
    }

    /** The original's public URL (what the pages used before), or null for an empty value. */
    public static function original(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        $normalized = self::normalize($path);

        return $normalized === null ? $path : Storage::disk('public')->url($normalized);
    }

    /** A thumbnail URL $width pixels wide; the original's URL when it cannot be resized (missing, not an image). */
    public static function url(?string $path, int $width): ?string
    {
        $normalized = self::normalize($path);

        if ($normalized === null || ! in_array($width, self::WIDTHS, true) || ! self::resizable($normalized)) {
            return self::original($path);
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($normalized)) {
            return self::original($path);
        }

        return $disk->url(self::thumbPath($normalized, $width, $disk->lastModified($normalized)));
    }

    /** srcset value for the given widths (empty when the picture cannot be resized). */
    public static function srcset(?string $path, array $widths): string
    {
        $normalized = self::normalize($path);

        if ($normalized === null || ! self::resizable($normalized) || ! Storage::disk('public')->exists($normalized)) {
            return '';
        }

        return collect($widths)->map(fn (int $width): string => self::url($path, $width).' '.$width.'w')->implode(', ');
    }

    public static function thumbPath(string $original, int $width, int $mtime): string
    {
        return self::DIRECTORY.'/'.$width.'/'.$original.'.'.$mtime.'.webp';
    }

    /**
     * Make the thumbnail stored at thumbs/{width}/{$thumb}. Null (= 404) unless $thumb is exactly the name url() hands
     * out for an existing picture.
     */
    public static function generate(int $width, string $thumb): ?string
    {
        if (! in_array($width, self::WIDTHS, true) || str_contains($thumb, '..') || ! preg_match('/^(.+)\.(\d+)\.webp$/', $thumb, $match)) {
            return null;
        }

        [, $original, $mtime] = $match;
        $disk = Storage::disk('public');

        if (Str::startsWith($original, self::DIRECTORY.'/') || ! self::resizable($original) || ! $disk->exists($original) || $disk->lastModified($original) !== (int) $mtime) {
            return null;
        }

        // A large camera photo needs more than the default memory to decode.
        @ini_set('memory_limit', '512M');

        $image = Image::make($disk->path($original))->orientate();

        if ($image->width() > $width) {
            $image->widen($width);
        }

        $path = self::thumbPath($original, $width, (int) $mtime);
        $disk->put($path, (string) $image->encode('webp', 80));

        return $path;
    }

    /** Remove every thumbnail of the pictures under $directory (e.g. "designs/{storage_key}"); they are made again on use. */
    public static function forget(string $directory): void
    {
        foreach (self::WIDTHS as $width) {
            Storage::disk('public')->deleteDirectory(self::DIRECTORY.'/'.$width.'/'.trim($directory, '/'));
        }
    }

    private static function resizable(string $path): bool
    {
        return in_array(Str::lower(pathinfo($path, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }
}
