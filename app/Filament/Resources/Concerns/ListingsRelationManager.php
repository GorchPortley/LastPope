<?php

namespace App\Filament\Resources\Concerns;

use App\Enums\ListingStatus;
use App\Filament\Resources\ListingResource;
use App\Models\Listing;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** Listings that point at a design, driver or seller, read-only with a link to the listing editor. */
abstract class ListingsRelationManager extends RelationManager
{
    protected static string $relationship = 'listings';

    protected static ?string $title = 'Listings';

    public function table(Table $table): Table
    {
        $status = fn ($state): ListingStatus => $state instanceof ListingStatus ? $state : ListingStatus::from((string) $state);

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('seller'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('title')->searchable()->url(fn (Listing $record): string => ListingResource::getUrl('edit', ['record' => $record])),
                Tables\Columns\TextColumn::make('channel')->badge()->formatStateUsing(fn ($state): string => $state instanceof \App\Enums\ListingChannel ? $state->label() : (string) $state),
                Tables\Columns\TextColumn::make('seller.name')->label('Seller'),
                Tables\Columns\TextColumn::make('status')->badge()->formatStateUsing(fn ($state): string => $status($state)->label()),
                Tables\Columns\TextColumn::make('price_cents')->label('Price')->formatStateUsing(fn ($state): string => money((int) $state)),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ]);
    }
}
