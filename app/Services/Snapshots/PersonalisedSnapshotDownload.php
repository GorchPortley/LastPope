<?php

namespace App\Services\Snapshots;

use App\Models\DesignSnapshot;
use App\Models\User;
use DateTimeImmutable;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use ZipArchive;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

/**
 * Serves a design snapshot ZIP with the PDF personalised for the person who downloads it.
 *
 * The stored ZIP keeps the generic PDF (owner previews, fallbacks). A download renders the PDF again from the
 * snapshot's own data with the downloader's name in the footer (DesignPdf + PdfLicense) and streams a new ZIP to the
 * client: every other entry is copied over from the stored ZIP, entry by entry, through ZipStream (decompressed and
 * recompressed in small chunks, so memory stays flat however large the files are; no temporary file is written).
 * Only the PDF is held in memory.
 *
 * A personalised PDF is cached on the private disk, next to the snapshot ZIP:
 *   {snapshots dir}/personalised/{snapshot id}/u{user id}-{snapshot updated_at}-{footer fingerprint}.pdf
 * so a repeat download is just the copy. The key covers the snapshot version (its updated_at) and everything printed
 * in the footer (name, username, date, order), so a changed name or a new day renders a fresh PDF and replaces the
 * user's older one. forget() drops them all when the snapshot is rebuilt or deleted.
 *
 * Anything that cannot be personalised (no PDF in the ZIP, a non-local disk, a PDF that fails to render) falls back
 * to streaming the stored ZIP unchanged.
 */
class PersonalisedSnapshotDownload
{
    public function __construct(private readonly DesignPdf $pdf)
    {
    }

    /** Where the personalised PDFs of $snapshot are cached. */
    public static function cacheDirectory(DesignSnapshot $snapshot): string
    {
        return dirname((string) $snapshot->download_path).'/personalised/'.$snapshot->getKey();
    }

    /** Drop every cached personalised PDF of $snapshot (it was rebuilt or deleted). */
    public static function forget(DesignSnapshot $snapshot): void
    {
        if (blank($snapshot->download_path) || ! $snapshot->getKey()) {
            return;
        }

        try {
            Storage::disk('private')->deleteDirectory(self::cacheDirectory($snapshot));
        } catch (Throwable $e) {
            Log::warning('Personalised PDFs could not be cleared', ['snapshot_id' => $snapshot->getKey(), 'error' => $e->getMessage()]);
        }
    }

    /** The ZIP for $user. The caller has already authorised the download. */
    public function response(DesignSnapshot $snapshot, User $user): StreamedResponse
    {
        $disk = Storage::disk('private');
        $local = $this->localPath($disk, (string) $snapshot->download_path);
        $entry = $local !== null ? $this->pdfEntry($local) : null;

        if ($entry === null) {
            return $this->unchanged($snapshot);
        }

        try {
            $pdf = $this->personalisedPdf($snapshot, PdfLicense::for($user, $snapshot->design));
        } catch (Throwable $e) {
            report($e);

            return $this->unchanged($snapshot);
        }

        return response()->streamDownload(
            fn () => $this->streamZip($local, $entry, $pdf),
            $snapshot->snapshot_name,
            ['Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store'],
        );
    }

    /** The personalised PDF (from the cache when this user already has it for this version of the snapshot). */
    public function personalisedPdf(DesignSnapshot $snapshot, PdfLicense $license): string
    {
        $disk = Storage::disk('private');
        $directory = self::cacheDirectory($snapshot);
        $prefix = 'u'.$license->userId.'-';
        $path = $directory.'/'.$prefix.($snapshot->updated_at?->getTimestamp() ?? 0).'-'.$license->fingerprint().'.pdf';

        if ($disk->exists($path) && str_starts_with((string) ($cached = $disk->get($path)), '%PDF')) {
            return $cached;
        }

        $pdf = $this->pdf->render((array) $snapshot->stashed_data, $license);

        try {
            $this->store($disk, $directory, $prefix, $path, $pdf);
        } catch (Throwable $e) {
            // not caching is not a reason to fail the download
            Log::warning('A personalised PDF could not be cached', ['snapshot_id' => $snapshot->getKey(), 'error' => $e->getMessage()]);
        }

        return $pdf;
    }

    /** Write via a temporary name and rename, so a parallel download never reads half a file; replace the user's older PDFs. */
    private function store(Filesystem $disk, string $directory, string $prefix, string $path, string $pdf): void
    {
        $temporary = $directory.'/.writing-'.bin2hex(random_bytes(6));

        try {
            $disk->put($temporary, $pdf);
            $disk->move($temporary, $path);
        } finally {
            if ($disk->exists($temporary)) {
                $disk->delete($temporary);
            }
        }

        foreach ($disk->files($directory) as $file) {
            if ($file !== $path && str_starts_with(basename($file), $prefix)) {
                $disk->delete($file);
            }
        }
    }

    /** The stored ZIP as it is. */
    private function unchanged(DesignSnapshot $snapshot): StreamedResponse
    {
        abort_unless(Storage::disk('private')->exists($snapshot->download_path), 404, 'This file is no longer available.');

        return Storage::disk('private')->download($snapshot->download_path, $snapshot->snapshot_name, [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** The file system path of a stored file, or null when the disk is not a local one (ZipArchive needs a real file). */
    private function localPath(Filesystem $disk, string $path): ?string
    {
        if ($path === '' || ! method_exists($disk, 'path')) {
            return null;
        }

        $local = $disk->path($path);

        return is_file($local) ? $local : null;
    }

    /** The index of the generated PDF in the ZIP (a PDF at its top level), or null when there is none or the ZIP is unreadable. */
    private function pdfEntry(string $local): ?int
    {
        $zip = new ZipArchive();

        if ($zip->open($local, ZipArchive::RDONLY) !== true) {
            return null;
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);

                if (! str_contains($name, '/') && strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'pdf') {
                    return $i;
                }
            }

            return null;
        } finally {
            $zip->close();
        }
    }

    /** Copy $local to the output, with entry number $pdfEntry replaced by $pdf. */
    private function streamZip(string $local, int $pdfEntry, string $pdf): void
    {
        $source = new ZipArchive();

        if ($source->open($local, ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('The snapshot archive could not be opened.');
        }

        $output = new ZipStream(
            sendHttpHeaders: false,
            flushOutput: true,
            comment: (string) $source->getArchiveComment(),
        );

        try {
            for ($i = 0; $i < $source->numFiles; $i++) {
                $stat = $source->statIndex($i);
                $name = (string) $stat['name'];
                // the DOS timestamps of a ZIP start in 1980
                $modified = (new DateTimeImmutable())->setTimestamp(max((int) $stat['mtime'], 315532800));

                if (str_ends_with($name, '/')) {
                    $output->addDirectory($name, lastModificationDateTime: $modified);
                } elseif ($i === $pdfEntry) {
                    $output->addFile($name, $pdf, lastModificationDateTime: new DateTimeImmutable());
                } else {
                    $stream = $source->getStreamIndex($i);

                    if ($stream === false) {
                        throw new \RuntimeException("The entry {$name} could not be read.");
                    }

                    // through a PSR-7 stream: reads come in small chunks (a zip:// stream would hand over up to 16 MB at once)
                    $psr = Utils::streamFor($stream);

                    try {
                        $output->addFileFromPsr7Stream(
                            $name,
                            $psr,
                            compressionMethod: ($stat['comp_method'] ?? ZipArchive::CM_DEFLATE) === ZipArchive::CM_STORE ? CompressionMethod::STORE : CompressionMethod::DEFLATE,
                            lastModificationDateTime: $modified,
                            exactSize: (int) $stat['size'],
                        );
                    } finally {
                        $psr->close();
                    }
                }
            }

            $output->finish();
        } finally {
            $source->close();
        }
    }
}
