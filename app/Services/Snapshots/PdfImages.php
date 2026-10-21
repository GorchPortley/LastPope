<?php

namespace App\Services\Snapshots;

use Illuminate\Support\Facades\Storage;

/**
 * dompdf must never fetch images over HTTP (remote access is off): images that appear in the PDF are
 * read from the public disk and embedded as data URIs, downscaled so the PDF stays small.
 */
class PdfImages
{
    private const MAX_WIDTH = 1200;

    private const MAX_BYTES = 1_500_000;

    private const ALLOWED = ['image/jpeg', 'image/png', 'image/gif'];

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

    /** Public-disk path (or "/storage/..." URL path) to a data URI, or null if it cannot be read. */
    public function embed(string $path): ?string
    {
        $path = $this->publicPath($path);
        $disk = Storage::disk('public');

        if ($path === null || ! $disk->exists($path)) {
            return null;
        }

        $bytes = (string) $disk->get($path);
        $info = @getimagesizefromstring($bytes);

        if ($info === false || ! in_array($info['mime'], self::ALLOWED, true)) {
            return null;
        }

        if ($info[0] > self::MAX_WIDTH || strlen($bytes) > self::MAX_BYTES) {
            $bytes = $this->downscale($bytes) ?? $bytes;
            $mime = 'image/jpeg';
        } else {
            $mime = $info['mime'];
        }

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    /** Replace <img src="http://site/storage/..."> in rich-text HTML with embedded data URIs. */
    public function inlineHtml(string $html): string
    {
        return (string) preg_replace_callback(
            '/(<img\b[^>]*?\bsrc\s*=\s*)(["\'])(.*?)\2/is',
            function (array $m): string {
                $uri = str_starts_with($m[3], 'data:') ? null : $this->embed(html_entity_decode($m[3]));

                return $uri === null ? $m[0] : $m[1].$m[2].$uri.$m[2];
            },
            $html,
        );
    }

    /** "http://host/storage/designs/x/a.png", "/storage/designs/x/a.png" or "designs/x/a.png" => "designs/x/a.png" */
    private function publicPath(string $src): ?string
    {
        $path = parse_url($src, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return null;
        }

        $path = ltrim(rawurldecode($path), '/');
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        // never let a crafted path escape the disk
        return str_contains($path, '..') ? null : $path;
    }

    private function downscale(string $bytes): ?string
    {
        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            return null;
        }

        $scaled = imagescale($source, min(self::MAX_WIDTH, imagesx($source)));
        if ($scaled === false) {
            return null;
        }

        // flatten transparency onto white so PNG alpha does not turn black in the JPEG
        $canvas = imagecreatetruecolor(imagesx($scaled), imagesy($scaled));
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $scaled, 0, 0, 0, 0, imagesx($scaled), imagesy($scaled));

        ob_start();
        imagejpeg($canvas, null, 82);

        return (string) ob_get_clean();
    }
}
