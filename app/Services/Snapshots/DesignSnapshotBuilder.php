<?php

namespace App\Services\Snapshots;

use App\Models\Design;
use App\Models\DesignSnapshot;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds a versioned design snapshot: a ZIP on the private disk with every uploaded file of the
 * design (and of its components), a PDF of the design page, manifest.json and README.txt, plus the
 * DesignSnapshot row that records the version, the manifest and the file size.
 *
 * The PDF in the ZIP is the generic copy. Every download of it renders a personalised one from the manifest instead
 * (see PersonalisedSnapshotDownload), so a snapshot is the single source of what its PDF shows.
 *
 * Path: designs/{storage_key}/snapshots/{slug}-{version}.zip
 */
class DesignSnapshotBuilder extends SnapshotBuilder
{
    /** design column => folder inside the ZIP */
    private const DESIGN_FILES = [
        'frd_files' => 'response-previews',
        'enclosure_files' => 'enclosure',
        'electronic_files' => 'electronics',
        'design_other_files' => 'other',
    ];

    /** DesignDriver column => folder inside the component's folder */
    private const COMPONENT_FILES = [
        'frequency_files' => 'frequency',
        'impedance_files' => 'impedance',
        'other_files' => 'other',
    ];

    public function __construct(private readonly DesignPdf $pdf = new DesignPdf())
    {
    }

    public function build(Design $design, string $version): DesignSnapshot
    {
        $version = trim($version);
        $design->loadMissing(['designer', 'components.driver']);

        $slug = $this->slug((string) $design->name, 'design-'.$design->getKey());
        $baseName = $slug.'-'.$this->versionLabel($version);
        $filename = $this->uniqueFilename($design, $baseName, $version);
        $path = $design->snapshotDirectory().'/'.$filename;

        $archive = new SnapshotArchive($this->disk());
        $warnings = [];
        $createdAt = now()->toIso8601String();
        $content = $this->content($design, $version);

        try {
            foreach (self::DESIGN_FILES as $column => $folder) {
                foreach ($this->paths($design->{$column}) as $stored) {
                    $archive->addStored($folder, $stored);
                }
            }

            foreach ($design->components as $component) {
                $folder = 'components/'.$this->componentFolder($component);

                foreach (self::COMPONENT_FILES as $column => $sub) {
                    foreach ($this->paths($component->{$column}) as $stored) {
                        $archive->addStored($folder.'/'.$sub, $stored);
                    }
                }
            }

            try {
                $archive->addString($baseName.'.pdf', $this->pdf->render($content + ['created_at' => $createdAt]));
            } catch (Throwable $e) {
                Log::warning('Design snapshot PDF could not be generated', ['design_id' => $design->getKey(), 'error' => $e->getMessage()]);
                $warnings[] = 'The PDF could not be generated: '.$e->getMessage();
            }

            $manifest = $this->manifest($content, $filename, $createdAt, $archive, $warnings);
            $archive->addString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $archive->addString('README.txt', $this->readme(
                (string) $design->purchasableTitle(),
                'By '.($design->designer?->name ?? 'unknown designer'),
                $version,
                $this->describe($archive),
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

        $existing = DesignSnapshot::where('design_id', $design->getKey())->where('version', $version)->first();
        if ($existing && $existing->download_path !== $path) {
            $this->disk()->delete($existing->download_path);
        }

        $snapshot = DesignSnapshot::updateOrCreate(
            ['design_id' => $design->getKey(), 'version' => $version],
            [
                'snapshot_name' => $filename,
                'stashed_data' => $manifest,
                'file_size' => $size,
                'download_path' => $path,
            ],
        );

        // personalised PDFs were made from the previous build of this version
        PersonalisedSnapshotDownload::forget($snapshot);

        return $snapshot;
    }

    /**
     * What the design looked like at this version: the manifest without its archive details, and everything the PDF
     * is rendered from (now and for every personalised download later).
     *
     * @return array<string, mixed>
     */
    private function content(Design $design, string $version): array
    {
        return [
            'name' => $design->name,
            'designer' => $design->designer?->name,
            'version' => $version,
            'tag' => $design->tag,
            'images' => $design->card_image,
            'category' => $design->category,
            'price_cents' => $design->price_cents,
            'build_cost_cents' => $design->build_cost_cents,
            'impedance' => $design->impedance,
            'power' => $design->power,
            'summary' => $design->summary,
            'description' => $design->description,
            'bom' => $design->bill_of_materials,
            'forum_link' => $design->forum_slug ? url('forum/'.$design->forum_slug) : null,
            'components' => $design->components->map(fn ($component) => [
                'position' => $component->position,
                'driver' => $component->driver ? trim($component->driver->brand.' '.$component->driver->model) : null,
                'quantity' => $component->quantity,
                'low_frequency' => $component->low_frequency,
                'high_frequency' => $component->high_frequency,
                'air_volume' => $component->air_volume,
                'description' => $component->description,
                'specifications' => $component->specifications,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $content  see content()
     * @return array<string, mixed> the manifest (also stored as the snapshot's stashed_data)
     */
    private function manifest(array $content, string $filename, string $createdAt, SnapshotArchive $archive, array $warnings): array
    {
        return [
            ...Arr::only($content, ['name', 'designer', 'version']),
            'archive' => $filename,
            'created_at' => $createdAt,
            ...Arr::except($content, ['name', 'designer', 'version']),
            // Entries added so far; manifest.json and README.txt themselves are not listed.
            'files' => array_map(fn (array $entry) => ['path' => $entry['path'], 'size' => $entry['size']], $archive->entries()),
            'missing_files' => $archive->missing(),
            'warnings' => $warnings,
        ];
    }

    /** @return array<int, string> */
    private function describe(SnapshotArchive $archive): array
    {
        $lines = [];
        foreach ($archive->entries() as $entry) {
            $lines[] = $entry['path'];
        }

        return array_merge($lines, ['manifest.json', 'README.txt']);
    }

    private function componentFolder($component): string
    {
        $driver = $component->driver ? trim($component->driver->brand.' '.$component->driver->model) : 'driver';

        return strtoupper((string) $component->position).'-'.$this->slug($driver, 'driver');
    }

    /** "{slug}-{version}.zip", made unique if another version already uses the same file name. */
    private function uniqueFilename(Design $design, string $baseName, string $version): string
    {
        $candidate = $baseName.'.zip';
        $i = 2;

        while (DesignSnapshot::where('design_id', $design->getKey())
            ->where('version', '!=', $version)
            ->where('download_path', $design->snapshotDirectory().'/'.$candidate)
            ->exists()) {
            $candidate = $baseName.'-'.$i++.'.zip';
        }

        return $candidate;
    }
}
