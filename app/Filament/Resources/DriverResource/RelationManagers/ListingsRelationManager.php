<?php

namespace App\Filament\Resources\DriverResource\RelationManagers;

use App\Filament\Resources\Concerns\ListingsRelationManager as BaseListings;

class ListingsRelationManager extends BaseListings
{
    protected static ?string $title = 'Listings (store and used)';
}
