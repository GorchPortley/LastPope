<?php

namespace App\Filament\Resources\DriverResource\RelationManagers;

use App\Filament\Resources\Concerns\SnapshotsRelationManager as BaseSnapshots;

class SnapshotsRelationManager extends BaseSnapshots
{
    protected function downloadRoute(): string
    {
        return 'driver-snapshots.download';
    }
}
