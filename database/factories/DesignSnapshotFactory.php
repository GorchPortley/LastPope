<?php

namespace Database\Factories;

use App\Models\Design;
use App\Models\DesignSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * @extends Factory<DesignSnapshot>
 */
class DesignSnapshotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'design_id' => Design::factory(),
            'version' => 'v1.0',
            'snapshot_name' => fn (array $attributes) => $this->snapshotName($attributes),
            'stashed_data' => fn (array $attributes) => ['name' => Design::find($attributes['design_id'])?->name, 'version' => $attributes['version']],
            'file_size' => 2048,
            'download_path' => fn (array $attributes) => Design::find($attributes['design_id'])
                ->storageDirectory('snapshots/'.$attributes['snapshot_name']),
        ];
    }

    /** Also write a small, valid ZIP to the private disk at download_path. */
    public function withFile(): static
    {
        return $this->afterCreating(function (DesignSnapshot $snapshot) {
            $temp = tempnam(sys_get_temp_dir(), 'snap');
            $zip = new ZipArchive();
            $zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $zip->addFromString('README.txt', 'Snapshot '.$snapshot->version." of design #{$snapshot->design_id}\n");
            $zip->addFromString('manifest.json', json_encode($snapshot->stashed_data));
            $zip->close();

            $contents = file_get_contents($temp);
            @unlink($temp);

            Storage::disk('private')->put($snapshot->download_path, $contents);
            $snapshot->update(['file_size' => strlen($contents)]);
        });
    }

    private function snapshotName(array $attributes): string
    {
        $design = Design::find($attributes['design_id']);

        return Str::slug($design?->name ?: 'design').'-'.Str::slug($attributes['version']).'.zip';
    }
}
