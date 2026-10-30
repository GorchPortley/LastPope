<?php

namespace App\Filament\Resources\DesignResource\RelationManagers;

use App\Filament\Resources\Concerns\SnapshotsRelationManager as BaseSnapshots;

class SnapshotsRelationManager extends BaseSnapshots
{
    protected function downloadRoute(): string
    {
        return 'design-snapshots.download';
    }
}
