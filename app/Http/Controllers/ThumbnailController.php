<?php

namespace App\Http\Controllers;

use App\Support\Thumbnails;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * First request for a thumbnail that does not exist yet (nginx found no file at /storage/thumbs/...): make it, store
 * it where nginx will find it next time, and send it. See App\Support\Thumbnails.
 */
class ThumbnailController
{
    public function __invoke(int $width, string $path): Response
    {
        $stored = Thumbnails::generate($width, $path);

        abort_if($stored === null, 404);

        return response(Storage::disk('public')->get($stored), 200, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
