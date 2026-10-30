<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Filament\Resources\Concerns\ListingsRelationManager as BaseListings;

class ListingsRelationManager extends BaseListings
{
    protected static ?string $title = 'Listings for sale';
}
