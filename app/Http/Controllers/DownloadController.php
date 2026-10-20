<?php

namespace App\Http\Controllers;

use App\Models\DesignSnapshot;
use App\Models\DriverSnapshot;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves snapshot ZIPs from the private disk. Nothing under the private disk has a public URL;
 * access is decided by the `download` policy ability of the parent Design/Driver, which asks
 * User::hasAccessTo() (owner, admin, entitlement, or a free item).
 */
class DownloadController extends Controller
{
    public function designSnapshot(DesignSnapshot $snapshot): StreamedResponse
    {
        $this->authorize('download', $snapshot->design);

        return $this->stream($snapshot->download_path, $snapshot->snapshot_name);
    }

    public function driverSnapshot(DriverSnapshot $snapshot): StreamedResponse
    {
        $this->authorize('download', $snapshot->driver);

        return $this->stream($snapshot->download_path, $snapshot->snapshot_name);
    }

    private function stream(string $path, string $filename): StreamedResponse
    {
        $disk = Storage::disk('private');

        abort_unless($disk->exists($path), 404, 'This file is no longer available.');

        return $disk->download($path, $filename, [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
