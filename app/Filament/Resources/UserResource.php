<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;

/**
 * Every column of `users` an admin can sensibly edit. Secrets (password hash, remember token, the encrypted forum
 * password, two-factor secrets) are never shown: the password can be replaced and two-factor reset. Several columns
 * are not mass assignable on the model, so the create and edit pages save with forceFill().
 * The user's designs, drivers, listings, orders and downloads are relation managers.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'phosphor-users-duotone';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Account')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')->required()->maxLength(191),
                        Forms\Components\TextInput::make('username')
                            ->required()
                            ->maxLength(191)
                            ->alphaDash()
                            ->unique(ignoreRecord: true),
                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(191)
                            ->unique(ignoreRecord: true),
                        Forms\Components\TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                            ->dehydrated(fn ($state) => filled($state))
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->helperText(fn (string $operation): ?string => $operation === 'edit' ? 'Leave empty to keep the current password.' : null),
                        Forms\Components\Select::make('roles')
                            ->multiple()
                            ->relationship('roles', 'name')
                            ->preload()
                            ->searchable()
                            ->required()
                            ->helperText('admin opens this panel; manufacturer marks their drivers official and unlocks "Collect data".'),
                        Forms\Components\FileUpload::make('avatar')
                            ->image()
                            ->avatar()
                            ->disk('public')
                            ->directory('avatars')
                            ->imageCropAspectRatio('1:1')
                            ->imageResizeMode('cover')
                            ->imageResizeTargetWidth((string) \App\Support\Avatars::SIZE)
                            ->imageResizeTargetHeight((string) \App\Support\Avatars::SIZE)
                            ->helperText('Empty = the default avatar.'),
                    ]),

                Forms\Components\Section::make('Verification and trial')
                    ->columns(2)
                    ->schema([
                        Forms\Components\DateTimePicker::make('email_verified_at')->label('E-mail verified at'),
                        Forms\Components\Toggle::make('verified')->helperText('Wave\'s own verification flag.'),
                        Forms\Components\TextInput::make('verification_code')->maxLength(191),
                        Forms\Components\DateTimePicker::make('trial_ends_at'),
                    ]),

                Forms\Components\Section::make('Chat')
                    ->columns(3)
                    ->collapsed()
                    ->schema([
                        Forms\Components\Toggle::make('active_status')->label('Shown as online'),
                        Forms\Components\Toggle::make('dark_mode')->label('Chat dark mode'),
                        Forms\Components\ColorPicker::make('messenger_color')->label('Chat colour'),
                    ]),

                Forms\Components\Section::make('Security and forum')
                    ->columns(3)
                    ->collapsed()
                    ->visibleOn('edit')
                    ->schema([
                        Forms\Components\Placeholder::make('two_factor')
                            ->label('Two-factor')
                            ->content(fn (?User $record): string => $record?->two_factor_confirmed_at ? 'On since '.$record->two_factor_confirmed_at : 'Off'),
                        Forms\Components\Placeholder::make('forum_user_id')
                            ->label('Forum user ID')
                            ->content(fn (?User $record): string => $record?->forum_user_id ? (string) $record->forum_user_id : 'Not linked yet'),
                        Forms\Components\Placeholder::make('id')->label('ID')->content(fn (?User $record): string => (string) $record?->id),
                        Forms\Components\Placeholder::make('created_at')->label('Joined')->content(fn (?User $record): string => (string) $record?->created_at),
                        Forms\Components\Placeholder::make('updated_at')->label('Updated')->content(fn (?User $record): string => (string) $record?->updated_at),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('roles')->withCount(['designs', 'drivers', 'listings', 'orders']))
            ->columns([
                Tables\Columns\ImageColumn::make('avatar')
                    ->circular()
                    ->state(fn (User $record): string => $record->avatar()),
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (User $record): string => '@'.$record->username),
                Tables\Columns\TextColumn::make('username')->searchable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('email')->searchable()->sortable()->copyable(),
                Tables\Columns\TextColumn::make('roles.name')->label('Roles')->badge(),
                Tables\Columns\IconColumn::make('email_verified_at')
                    ->label('Verified')
                    ->boolean()
                    ->state(fn (User $record): bool => $record->email_verified_at !== null),
                Tables\Columns\TextColumn::make('designs_count')->label('Designs')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('drivers_count')->label('Drivers')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('listings_count')->label('Listings')->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('orders_count')->label('Orders')->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')->label('Joined')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('roles')->relationship('roles', 'name')->multiple()->preload(),
                Tables\Filters\TernaryFilter::make('email_verified_at')->label('E-mail verified')->nullable(),
                Tables\Filters\Filter::make('sellers')
                    ->label('Has a shop')
                    ->query(fn (Builder $query) => $query->whereHas('sellerProfile')),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('Impersonate')
                        ->icon('heroicon-m-user')
                        ->url(fn ($record) => route('impersonate', $record))
                        ->visible(fn ($record) => auth()->user()->id !== $record->id),
                    static::verifyEmailAction(Tables\Actions\Action::class),
                    static::resetTwoFactorAction(Tables\Actions\Action::class),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /** @param  class-string<Tables\Actions\Action|\Filament\Actions\Action>  $class */
    public static function verifyEmailAction(string $class): Tables\Actions\Action|\Filament\Actions\Action
    {
        return $class::make('verifyEmail')
            ->label('Mark e-mail verified')
            ->icon('heroicon-m-check-badge')
            ->visible(fn (User $record): bool => $record->email_verified_at === null)
            ->requiresConfirmation()
            ->action(function (User $record): void {
                $record->forceFill(['email_verified_at' => now(), 'verified' => 1])->save();
                Notification::make()->success()->title('E-mail marked as verified')->send();
            });
    }

    /** @param  class-string<Tables\Actions\Action|\Filament\Actions\Action>  $class */
    public static function resetTwoFactorAction(string $class): Tables\Actions\Action|\Filament\Actions\Action
    {
        return $class::make('resetTwoFactor')
            ->label('Reset two-factor')
            ->icon('heroicon-m-shield-exclamation')
            ->color('danger')
            ->visible(fn (User $record): bool => filled($record->two_factor_secret))
            ->requiresConfirmation()
            ->modalDescription('Turns two-factor sign-in off for this user, e.g. after they lost their phone. They can set it up again.')
            ->action(function (User $record): void {
                $record->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
                Notification::make()->success()->title('Two-factor reset')->send();
            });
    }

    /** The avatar column is required; a user without a picture gets the site's default one. */
    public static function withDefaultAvatar(array $data): array
    {
        if (array_key_exists('avatar', $data) && blank($data['avatar'])) {
            $data['avatar'] = 'demo/default.png';
        }

        return $data;
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\DesignsRelationManager::class,
            RelationManagers\DriversRelationManager::class,
            RelationManagers\ListingsRelationManager::class,
            RelationManagers\OrdersRelationManager::class,
            RelationManagers\EntitlementsRelationManager::class,
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'username', 'email'];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
