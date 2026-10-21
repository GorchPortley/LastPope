<?php

namespace App\Services\Snapshots;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Shared helpers of the design and driver snapshot builders.
 *
 * Snapshots are built from the file-path JSON fields stored on the model (never by walking a
 * directory), read from the private disk and written back to it.
 */
abstract class SnapshotBuilder
{
    protected function disk(): Filesystem
    {
        return Storage::disk('private');
    }

    /**
     * Normalise a stored file field (array, JSON string or null) to a list of paths.
     *
     * @return array<int, string>
     */
    protected function paths(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return collect(is_array($value) ? $value : [])
            ->flatten()
            ->filter(fn ($path) => is_string($path) && $path !== '')
            ->values()
            ->all();
    }

    /** A filesystem- and URL-safe slug, never empty. */
    protected function slug(string $value, string $fallback): string
    {
        return Str::slug($value) ?: $fallback;
    }

    /** The version as it appears in file names: letters, digits, dots, dashes ("v1.0" stays "v1.0"). */
    protected function versionLabel(string $version): string
    {
        $label = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $version), '-._');

        return $label === '' ? 'snapshot' : $label;
    }

    /**
     * The README.txt that goes into every snapshot.
     *
     * @param  array<int, string>  $contents  human-readable lines describing the archive
     */
    protected function readme(string $title, string $byline, string $version, array $contents, array $missing): string
    {
        $lines = [
            $title,
            str_repeat('=', max(8, strlen($title))),
            $byline,
            'Version: '.$version,
            'Created: '.now()->toDayDateTimeString(),
            '',
            'Contents',
            '--------',
            ...array_map(fn (string $line) => '- '.$line, $contents),
            '',
            'manifest.json describes this version of the data in full.',
        ];

        if ($missing !== []) {
            $lines[] = '';
            $lines[] = 'Note: the following files were listed but could not be found when this snapshot was created:';
            foreach ($missing as $path) {
                $lines[] = '  '.$path;
            }
        }

        $lines[] = '';
        $lines[] = 'Please respect the creator\'s work and do not redistribute these files without their permission.';

        return implode("\n", $lines)."\n";
    }
}
