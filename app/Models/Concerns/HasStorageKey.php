<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Designs and drivers keep their files under a folder named by a UUID column, so renaming the
 * item never breaks stored paths: {prefix}/{storage_key}/... on the private and public disks.
 */
trait HasStorageKey
{
    protected static function bootHasStorageKey(): void
    {
        static::creating(function ($model) {
            if (blank($model->storage_key)) {
                $model->storage_key = (string) Str::uuid();
            }
        });

        static::deleted(function ($model) {
            if (blank($model->storage_key)) {
                return;
            }

            foreach (['private', 'public'] as $disk) {
                Storage::disk($disk)->deleteDirectory($model->storageDirectory());
            }

            \App\Support\Thumbnails::forget($model->storageDirectory());
        });
    }

    /** "designs/{storage_key}" or "drivers/{storage_key}" */
    abstract public static function storagePrefix(): string;

    public function storageDirectory(string $subPath = ''): string
    {
        $base = static::storagePrefix().'/'.$this->storage_key;

        return $subPath === '' ? $base : $base.'/'.ltrim($subPath, '/');
    }

    /** Where ZIP snapshots are stored (private disk). */
    public function snapshotDirectory(): string
    {
        return $this->storageDirectory('snapshots');
    }
}
