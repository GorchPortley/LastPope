<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Filament\Resources\DesignResource;
use App\Models\Design;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** The user's designs, read-only; open one to edit it. */
class DesignsRelationManager extends RelationManager
{
    protected static string $relationship = 'designs';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->url(fn (Design $record): string => DesignResource::getUrl('edit', ['record' => $record])),
                Tables\Columns\TextColumn::make('category'),
                Tables\Columns\TextColumn::make('price_cents')->label('Price')->formatStateUsing(fn ($state): string => (int) $state > 0 ? money((int) $state) : 'Free'),
                Tables\Columns\IconColumn::make('active')->label('Published')->boolean(),
                Tables\Columns\IconColumn::make('official')->boolean(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ]);
    }
}
