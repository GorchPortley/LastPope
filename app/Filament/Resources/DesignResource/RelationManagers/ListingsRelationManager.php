<?php

namespace App\Filament\Resources\DesignResource\RelationManagers;

use App\Filament\Resources\Concerns\ListingsRelationManager as BaseListings;

class ListingsRelationManager extends BaseListings
{
    protected static ?string $title = 'Listings built from it';
}
