<?php

namespace App\Filament\Resources\StripeEventResource\Pages;

use App\Filament\Resources\StripeEventResource;
use Filament\Resources\Pages\ListRecords;

class ListStripeEvents extends ListRecords
{
    protected static string $resource = StripeEventResource::class;
}
