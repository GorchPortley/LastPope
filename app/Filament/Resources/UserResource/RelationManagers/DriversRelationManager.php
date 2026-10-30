<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Filament\Resources\DriverResource;
use App\Models\Driver;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** Drivers the user added, read-only; open one to edit it. */
class DriversRelationManager extends RelationManager
{
    protected static string $relationship = 'drivers';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('model')
                    ->label('Driver')
                    ->searchable(['brand', 'model'])
                    ->formatStateUsing(fn (Driver $record): string => $record->brand.' '.$record->model)
                    ->url(fn (Driver $record): string => DriverResource::getUrl('edit', ['record' => $record])),
                Tables\Columns\TextColumn::make('category'),
                Tables\Columns\IconColumn::make('active')->boolean(),
                Tables\Columns\IconColumn::make('official')->boolean(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ]);
    }
}
