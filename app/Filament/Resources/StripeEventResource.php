<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StripeEventResource\Pages;
use App\Models\StripeEvent;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Read-only log of the Stripe webhook events we received (the de-duplication table). */
class StripeEventResource extends Resource
{
    protected static ?string $model = StripeEvent::class;

    protected static ?string $navigationIcon = 'phosphor-lightning-duotone';

    protected static ?string $navigationGroup = 'Marketplace';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'Stripe event';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('Event')->searchable()->copyable()->limit(32),
                Tables\Columns\TextColumn::make('type')->searchable()->sortable()->badge(),
                Tables\Columns\TextColumn::make('processed_at')
                    ->label('Processed')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('not processed')
                    ->color(fn (StripeEvent $record): string => $record->isProcessed() ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('created_at')->label('Received')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options(fn (): array => StripeEvent::query()->distinct()->orderBy('type')->pluck('type', 'type')->all()),
                Tables\Filters\TernaryFilter::make('processed')
                    ->label('State')
                    ->trueLabel('Processed')
                    ->falseLabel('Not processed')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('processed_at'),
                        false: fn ($query) => $query->whereNull('processed_at'),
                    ),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStripeEvents::route('/'),
        ];
    }
}
