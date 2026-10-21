<?php

namespace App\Filament\Resources;

use App\Enums\EntitlementSource;
use App\Filament\Resources\EntitlementResource\Pages;
use App\Models\Design;
use App\Models\Driver;
use App\Models\Entitlement;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EntitlementResource extends Resource
{
    protected static ?string $model = Entitlement::class;

    protected static ?string $navigationIcon = 'phosphor-key-duotone';

    protected static ?string $navigationGroup = 'Marketplace';

    protected static ?int $navigationSort = 4;

    public static function canCreate(): bool
    {
        // Grants go through the "Grant access" action on the list page (it creates the row the right way).
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'entitleable']))
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('User')
                    ->searchable(['name', 'email'])
                    ->description(fn (Entitlement $record): ?string => $record->user?->email),
                Tables\Columns\TextColumn::make('entitleable_type')
                    ->label('Type')
                    ->formatStateUsing(fn ($state): string => $state === 'driver' || $state === Driver::class ? 'Driver data pack' : 'Design')
                    ->badge(),
                Tables\Columns\TextColumn::make('item')
                    ->label('Item')
                    ->state(fn (Entitlement $record): string => $record->entitleable?->purchasableTitle() ?? 'Deleted item'),
                Tables\Columns\TextColumn::make('source')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => ($state instanceof EntitlementSource ? $state : EntitlementSource::from((string) $state))->label()),
                Tables\Columns\TextColumn::make('granted_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('revoked_at')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Entitlement $record): string => $record->isActive() ? 'Active' : 'Revoked '.$record->revoked_at->format('M j, Y'))
                    ->color(fn (Entitlement $record): string => $record->isActive() ? 'success' : 'danger'),
            ])
            ->defaultSort('granted_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('source')->options(EntitlementSource::options()),
                Tables\Filters\TernaryFilter::make('active')
                    ->label('Access')
                    ->placeholder('All')
                    ->trueLabel('Active')
                    ->falseLabel('Revoked')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNull('revoked_at'),
                        false: fn (Builder $query) => $query->whereNotNull('revoked_at'),
                    ),
                Tables\Filters\SelectFilter::make('entitleable_type')
                    ->label('Type')
                    ->options(['design' => 'Design', 'driver' => 'Driver data pack']),
            ])
            ->actions([
                Tables\Actions\Action::make('revoke')
                    ->label('Revoke')
                    ->icon('heroicon-m-no-symbol')
                    ->color('danger')
                    ->visible(fn (Entitlement $record): bool => $record->isActive())
                    ->requiresConfirmation()
                    ->modalDescription('The user loses access to downloads of this item (the order, if any, is not refunded).')
                    ->action(fn (Entitlement $record) => $record->revoke()),
                Tables\Actions\Action::make('restore')
                    ->label('Restore access')
                    ->icon('heroicon-m-arrow-uturn-left')
                    ->color('success')
                    ->visible(fn (Entitlement $record): bool => ! $record->isActive())
                    ->requiresConfirmation()
                    ->action(fn (Entitlement $record) => $record->forceFill(['revoked_at' => null])->save()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEntitlements::route('/'),
        ];
    }

    /** @return class-string<Design|Driver> */
    public static function modelFor(string $type): string
    {
        return $type === 'driver' ? Driver::class : Design::class;
    }
}
