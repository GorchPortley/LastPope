<?php

namespace App\Filament\Resources\DesignResource\RelationManagers;

use App\Enums\EntitlementSource;
use App\Filament\Resources\Concerns\CatalogueActions;
use App\Filament\Resources\UserResource;
use App\Models\Entitlement;
use App\Models\User;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** Who has access to the design's files: buyers, free downloads, admin grants. Grant, revoke and restore here. */
class EntitlementsRelationManager extends RelationManager
{
    protected static string $relationship = 'entitlements';

    protected static ?string $title = 'Access';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $source = fn ($state): EntitlementSource => $state instanceof EntitlementSource ? $state : EntitlementSource::from((string) $state);

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->defaultSort('granted_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('User')
                    ->searchable()
                    ->url(fn (Entitlement $record): ?string => $record->user_id ? UserResource::getUrl('edit', ['record' => $record->user_id]) : null),
                Tables\Columns\TextColumn::make('source')->badge()->formatStateUsing(fn ($state): string => $source($state)->label()),
                Tables\Columns\TextColumn::make('granted_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('revoked_at')->dateTime()->placeholder('Active'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('grant')
                    ->label('Grant access')
                    ->icon('heroicon-m-gift')
                    ->form([
                        Forms\Components\Select::make('user_id')
                            ->label('User')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => CatalogueActions::userOptions($search))
                            ->getOptionLabelUsing(fn ($value): ?string => User::find($value)?->name)
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $user = User::findOrFail($data['user_id']);
                        $user->grantEntitlement($this->getOwnerRecord(), EntitlementSource::ADMIN_GRANT);
                        Notification::make()->success()->title('Access granted')->body($user->name.' can now download it.')->send();
                    }),
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
