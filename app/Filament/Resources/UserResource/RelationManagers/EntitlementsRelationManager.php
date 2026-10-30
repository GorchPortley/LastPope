<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Enums\EntitlementSource;
use App\Models\Entitlement;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** What the user may download (bought, free, granted); revoke or restore here, grant from the design. */
class EntitlementsRelationManager extends RelationManager
{
    protected static string $relationship = 'entitlements';

    protected static ?string $title = 'Library (access)';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $source = fn ($state): EntitlementSource => $state instanceof EntitlementSource ? $state : EntitlementSource::from((string) $state);

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('entitleable'))
            ->defaultSort('granted_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('entitleable')
                    ->label('Item')
                    ->state(fn (Entitlement $record): string => $record->entitleable?->purchasableTitle() ?? 'Deleted item'),
                Tables\Columns\TextColumn::make('source')->badge()->formatStateUsing(fn ($state): string => $source($state)->label()),
                Tables\Columns\TextColumn::make('granted_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('revoked_at')->dateTime()->placeholder('Active'),
            ])
            ->actions([
                Tables\Actions\Action::make('revoke')
                    ->icon('heroicon-m-no-symbol')
                    ->color('danger')
                    ->visible(fn (Entitlement $record): bool => $record->isActive())
                    ->requiresConfirmation()
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
}
