<?php

namespace App\Services\Snapshots;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * dompdf must never fetch images over HTTP (remote access is off): every image that appears in the PDF is read from
 * the public disk and embedded as a data URI, downscaled so the PDF stays small.
 *
 * Two kinds of source: the design's card images (public-disk paths) and the <img> tags inside rich text, which the editor
 * saves with the URL of the upload ("/storage/designs/..." or "https://site/storage/designs/...", spaces encoded).
 * What cannot be read from the public disk (a missing file, another site's URL, an image type dompdf cannot draw, a
 * photo too large to decode) is left out of the PDF: an <img> without a usable source is removed, not left to fail.
 *
 * JPEGs from phones carry their rotation in EXIF, which browsers apply and PDFs do not: those are rotated upright. WebP
 * (an allowed upload) is converted, dompdf cannot draw it.
 */
class PdfImages
{
    /** Widest image kept (the page is 174 mm wide: this is 175 dpi). */
    private const MAX_WIDTH = 1200;

    /** Larger files are re-encoded as JPEG. */
    private const MAX_BYTES = 1_500_000;

    /** Files over this are not read at all. */
    private const MAX_FILE_BYTES = 30_000_000;

    /** What fits on a page (A4 less its margins, 174 x 245 mm) in CSS pixels. */
    private const PAGE_WIDTH = 650;

    private const PAGE_HEIGHT = 880;

    private const ALLOWED = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * @param  array<int, string>  $paths  public-disk paths
     * @return array<int, string> data URIs of the images that could be read
     */
    public function embedAll(array $paths, int $max = 4): array
    {
        $uris = [];

        foreach ($paths as $path) {
            if (count($uris) >= $max) {
                break;
            }

            if (is_string($path) && ($uri = $this->embed($path)) !== null) {
                $uris[] = $uri;
            }
        }

        return $uris;
    }

    /** Public-disk path (or "/storage/..." URL) to a data URI, or null if it cannot be read. */
    public function embed(string $path): ?string
    {
        return $this->load($path)['uri'] ?? null;
    }

    /** @return array{uri: string, width: int, height: int}|null the embedded image and its size in pixels */
    private function load(string $src): ?array
    {
        try {
            return $this->read($src);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Rich-text HTML with every <img> pointing at a stored upload replaced by one with the image embedded, capped to the
     * page width. An <img> that cannot be embedded (not on the public disk, missing, unreadable) is removed.
     */
    public function inlineHtml(string $html): string
    {
        return (string) preg_replace_callback('/<img\b[^>]*>/i', function (array $m): string {
            $tag = $m[0];

            if (! preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/is', $tag, $src)) {
                return '';
            }

            $image = preg_match('~^\s*data:image/(?:png|jpe?g|gif);base64,[A-Za-z0-9+/=\s]+$~i', $src[2])
                ? $this->dataUri(trim($src[2]))
                : $this->load(html_entity_decode($src[2]));

            if ($image === null) {
                return '';
            }

            // the editor's width if it set one, else the natural size; never wider or taller than the page (the height follows from the width)
            $width = preg_match('/\bwidth\s*=\s*(["\']?)(\d{1,5})\1/i', $tag, $w) && (int) $w[2] > 0 ? (int) $w[2] : $image['width'];
            $width = min($width, self::PAGE_WIDTH, (int) floor(self::PAGE_HEIGHT * $image['width'] / max(1, $image['height'])));
            $alt = preg_match('/\balt\s*=\s*(["\'])(.*?)\1/is', $tag, $a) ? htmlspecialchars(html_entity_decode($a[2]), ENT_QUOTES) : '';

            return '<img src="'.$image['uri'].'" alt="'.$alt.'" width="'.max(1, $width).'" style="max-width: 100%;">';
        }, $html);
    }

    /** @return array{uri: string, width: int, height: int}|null an image that is already a data URI */
    private function dataUri(string $uri): ?array
    {
        $bytes = base64_decode((string) substr($uri, (int) strpos($uri, ',') + 1), true);
        $info = $bytes === false ? false : @getimagesizefromstring($bytes);

        return $info === false ? null : ['uri' => $uri, 'width' => $info[0], 'height' => $info[1]];
    }

    /** @return array{uri: string, width: int, height: int}|null */
    private function read(string $src): ?array
    {
        $disk = Storage::disk('public');
        $path = null;

        foreach ($this->candidates($src) as $candidate) {
            if ($disk->exists($candidate)) {
                $path = $candidate;
                break;
            }
        }

        if ($path === null || $disk->size($path) > self::MAX_FILE_BYTES) {
            return null;
        }

        $bytes = (string) $disk->get($path);
        $info = @getimagesizefromstring($bytes);

        if ($info === false || ! in_array($info['mime'], self::ALLOWED, true)) {
            return null;
        }

        $orientation = $info['mime'] === 'image/jpeg' ? $this->orientation($bytes) : 1;
        $mime = $info['mime'];
        [$width, $height] = [$info[0], $info[1]];

        // as is, unless it is a type dompdf cannot draw, upside down, too wide or too heavy
        if ($mime === 'image/webp' || $orientation !== 1 || $info[0] > self::MAX_WIDTH || strlen($bytes) > self::MAX_BYTES) {
            if (! $this->canDecode($info[0], $info[1])) {
                return null;
            }

            $converted = $this->reencode($bytes, $orientation);

            if ($converted === null) {
                return null;
            }

            [$bytes, $width, $height] = $converted;
            $mime = 'image/jpeg';
        }

        return ['uri' => 'data:'.$mime.';base64,'.base64_encode($bytes), 'width' => $width, 'height' => $height];
    }

    /**
     * The public-disk paths $src can mean, best first. A stored card image is a disk path as it is ("designs/x/a b.png");
     * an upload in rich text is a URL: "http://host/storage/designs/x/a%20b.png", "/storage/designs/x/a b.png" or
     * "storage/designs/x/a.png". A URL with a host must point at /storage/, that is the public disk whatever the host is
     * called (a copy of the database from staging keeps its old host).
     *
     * @return array<int, string>
     */
    private function candidates(string $src): array
    {
        $src = trim($src);
        $candidates = [];

        if ($src !== '' && ! str_contains($src, '://') && ! str_starts_with($src, '/')) {
            $candidates[] = $src;
        }

        $url = parse_url($src);
        $path = is_array($url) ? ($url['path'] ?? null) : null;

        if (is_string($path) && $path !== '') {
            $path = ltrim(rawurldecode($path), '/');

            if (str_starts_with($path, 'storage/')) {
                $candidates[] = substr($path, strlen('storage/'));
            } elseif (! isset($url['host'])) {
                $candidates[] = $path;
            }
        }

        // never let a crafted path escape the disk
        return array_values(array_unique(array_filter(
            $candidates,
            fn (string $path) => $path !== '' && ! preg_match('~(^|/)\.\.(/|$)|\0~', $path),
        )));
    }

    /** The EXIF orientation (1 - 8) of a JPEG; 1 when it has none. */
    private function orientation(string $bytes): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }

        $stream = fopen('php://memory', 'r+');

        if ($stream === false) {
            return 1;
        }

        try {
            fwrite($stream, $bytes);
            rewind($stream);
            $exif = @exif_read_data($stream, 'IFD0');
            $value = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

            return $value >= 1 && $value <= 8 ? $value : 1;
        } catch (Throwable) {
            return 1;
        } finally {
            fclose($stream);
        }
    }

    /** Whether GD can hold an image of this size in the memory that is left (it needs about 5 bytes a pixel, twice with the scaled copy). */
    private function canDecode(int $width, int $height): bool
    {
        $limit = $this->memoryLimit();

        return $limit === null || $width * $height * 8 < ($limit - memory_get_usage(true)) * 0.8;
    }

    private function memoryLimit(): ?int
    {
        $value = trim((string) ini_get('memory_limit'));

        if ($value === '' || $value === '-1') {
            return null;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * Decode, turn upright, scale down to MAX_WIDTH, flatten transparency onto white, encode as JPEG.
     *
     * @return array{0: string, 1: int, 2: int}|null the JPEG and its size
     */
    private function reencode(string $bytes, int $orientation): ?array
    {
        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            return null;
        }

        $image = $this->upright($image, $orientation);

        if (imagesx($image) > self::MAX_WIDTH) {
            $scaled = imagescale($image, self::MAX_WIDTH);
            $image = $scaled === false ? $image : $scaled;
        }

        $canvas = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        ob_start();
        imagejpeg($canvas, null, 82);
        $jpeg = (string) ob_get_clean();

        return $jpeg === '' ? null : [$jpeg, imagesx($canvas), imagesy($canvas)];
    }

    /** Apply the EXIF orientation: what a browser shows for the file. */
    private function upright(\GdImage $image, int $orientation): \GdImage
    {
        if (in_array($orientation, [2, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        if ($orientation === 4) {
            imageflip($image, IMG_FLIP_VERTICAL);
        }

        // imagerotate turns counter-clockwise
        $angle = match ($orientation) {
            3 => 180,
            6, 7 => -90,
            5, 8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        return $rotated === false ? $image : $rotated;
    }
}
