<?php

namespace App\Services\Snapshots;

use App\Models\Driver;
use App\Models\DriverSnapshot;

/**
 * Builds a versioned driver measurement snapshot: a ZIP on the private disk with the driver's
 * frequency, impedance and other files, manifest.json and README.txt, plus the DriverSnapshot row.
 *
 * Path: drivers/{storage_key}/snapshots/{slug}-{version}.zip
 */
class DriverSnapshotBuilder extends SnapshotBuilder
{
    /** driver column => folder inside the ZIP */
    private const FILES = [
        'frequency_files' => 'frequency',
        'impedance_files' => 'impedance',
        'other_files' => 'other',
    ];

    public function build(Driver $driver, string $version): DriverSnapshot
    {
        $version = trim($version);
        $driver->loadMissing('creator');

        $baseName = $this->slug($driver->fullName(), 'driver-'.$driver->getKey()).'-'.$this->versionLabel($version);
        $filename = $this->uniqueFilename($driver, $baseName, $version);
        $path = $driver->snapshotDirectory().'/'.$filename;

        $archive = new SnapshotArchive($this->disk());

        try {
            foreach (self::FILES as $column => $folder) {
                foreach ($this->paths($driver->{$column}) as $stored) {
                    $archive->addStored($folder, $stored);
                }
            }

            $manifest = [
                'brand' => $driver->brand,
                'model' => $driver->model,
                'version' => $version,
                'archive' => $filename,
                'created_at' => now()->toIso8601String(),
                'contributor' => $driver->creator?->name,
                'category' => $driver->category,
                'size' => $driver->size,
                'impedance' => $driver->impedance,
                'power' => $driver->power,
                'msrp_cents' => $driver->msrp_cents,
                'link' => $driver->link,
                'summary' => $driver->summary,
                'description' => $driver->description,
                'factory_specs' => $driver->factory_specs,
                'files' => array_map(fn (array $entry) => ['path' => $entry['path'], 'size' => $entry['size']], $archive->entries()),
                'missing_files' => $archive->missing(),
            ];

            $archive->addString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $archive->addString('README.txt', $this->readme(
                $driver->fullName().' - measurement data',
                'Contributed by '.($driver->creator?->name ?? 'unknown'),
                $version,
                array_merge(array_map(fn (array $entry) => $entry['path'], $archive->entries()), ['manifest.json', 'README.txt']),
                $archive->missing(),
            ));

            $zip = $archive->close();
            $size = (int) filesize($zip);

            $stream = fopen($zip, 'rb');
            try {
                $this->disk()->put($path, $stream);
            } finally {
                is_resource($stream) && fclose($stream);
            }
        } finally {
            $archive->cleanup();
        }

        $existing = DriverSnapshot::where('driver_id', $driver->getKey())->where('version', $version)->first();
        if ($existing && $existing->download_path !== $path) {
            $this->disk()->delete($existing->download_path);
        }

        return DriverSnapshot::updateOrCreate(
            ['driver_id' => $driver->getKey(), 'version' => $version],
            [
                'snapshot_name' => $filename,
                'stashed_data' => $manifest,
                'file_size' => $size,
                'download_path' => $path,
            ],
        );
    }

    private function uniqueFilename(Driver $driver, string $baseName, string $version): string
    {
        $candidate = $baseName.'.zip';
        $i = 2;

        while (DriverSnapshot::where('driver_id', $driver->getKey())
            ->where('version', '!=', $version)
            ->where('download_path', $driver->snapshotDirectory().'/'.$candidate)
            ->exists()) {
            $candidate = $baseName.'-'.$i++.'.zip';
        }

        return $candidate;
    }
}
