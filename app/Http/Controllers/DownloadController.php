<?php

namespace App\Http\Controllers;

use App\Models\DesignSnapshot;
use App\Models\DriverSnapshot;
use App\Services\Snapshots\PersonalisedSnapshotDownload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves snapshot ZIPs from the private disk. Nothing under the private disk has a public URL;
 * access is decided by the `download` policy ability of the parent Design/Driver.
 *
 *  - designs (logged in): User::hasAccessTo() - owner, admin, entitlement, or a free design. The ZIP is streamed with
 *    the PDF inside it rendered for the downloader (their name in the footer), see PersonalisedSnapshotDownload
 *  - drivers (no login): the measurement data is free, so anybody may download the snapshots of an
 *    active driver; an inactive driver's files are only for its owner and admins (everybody else
 *    gets a 404, as if it did not exist)
 */
class DownloadController extends Controller
{
    public function designSnapshot(Request $request, DesignSnapshot $snapshot, PersonalisedSnapshotDownload $personalised): StreamedResponse
    {
        $this->authorize('download', $snapshot->design);

        abort_unless(Storage::disk('private')->exists($snapshot->download_path), 404, 'This file is no longer available.');

        return $personalised->response($snapshot, $request->user());
    }

    public function driverSnapshot(DriverSnapshot $snapshot): StreamedResponse
    {
        abort_unless(Gate::allows('download', $snapshot->driver), 404);

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
