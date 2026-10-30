<?php

namespace App\Filament\Resources\DriverResource\RelationManagers;

use App\Filament\Resources\DesignResource;
use App\Models\DesignDriver;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** The designs that use this driver (their `design_driver` rows), read-only; edit them on the design. */
class DesignsRelationManager extends RelationManager
{
    protected static string $relationship = 'designs';

    protected static ?string $title = 'Used in designs';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('design.designer'))
            ->columns([
                Tables\Columns\TextColumn::make('design.name')
                    ->label('Design')
                    ->url(fn (DesignDriver $record): ?string => $record->design_id ? DesignResource::getUrl('edit', ['record' => $record->design_id]) : null),
                Tables\Columns\TextColumn::make('design.designer.name')->label('Designer'),
                Tables\Columns\IconColumn::make('design.active')->label('Published')->boolean(),
                Tables\Columns\TextColumn::make('position'),
                Tables\Columns\TextColumn::make('quantity'),
                Tables\Columns\TextColumn::make('range')
                    ->label('Range')
                    ->state(fn (DesignDriver $record): string => ($record->low_frequency ?? '?').' - '.($record->high_frequency ?? '?').' Hz'),
                Tables\Columns\TextColumn::make('air_volume')->label('Air volume'),
            ]);
    }
}
