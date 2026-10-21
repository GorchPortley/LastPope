<?php

namespace App\Filament\Resources;

use App\Exceptions\Marketplace\ConnectException;
use App\Filament\Resources\SellerProfileResource\Pages;
use App\Models\SellerProfile;
use App\Services\Marketplace\ConnectOnboardingService;
use App\Support\Countries;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SellerProfileResource extends Resource
{
    protected static ?string $model = SellerProfile::class;

    protected static ?string $navigationIcon = 'phosphor-storefront-duotone';

    protected static ?string $navigationGroup = 'Marketplace';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'shop_name';

    protected static ?string $modelLabel = 'seller profile';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Shop')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('user.name')->label('Owner')->disabled()->dehydrated(false)
                            ->formatStateUsing(fn (?SellerProfile $record): ?string => $record?->user?->name.' ('.$record?->user?->email.')'),
                        Forms\Components\TextInput::make('shop_name')->disabled()->dehydrated(false),
                        Forms\Components\TextInput::make('slug')->disabled()->dehydrated(false),
                        Forms\Components\Select::make('country')->options(Countries::all())->disabled()->dehydrated(false),
                        Forms\Components\Textarea::make('bio')->disabled()->dehydrated(false)->rows(3)->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Stripe')
                    ->description('Set by Stripe through the Connect webhook; use "Refresh from Stripe" to pull the current values.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('stripe_account_id')->label('Connected account')->disabled()->dehydrated(false)->placeholder('not connected'),
                        Forms\Components\Placeholder::make('flags')
                            ->label('Status')
                            ->content(fn (?SellerProfile $record): string => $record === null ? '' : implode(' / ', [
                                'charges '.($record->charges_enabled ? 'on' : 'off'),
                                'payouts '.($record->payouts_enabled ? 'on' : 'off'),
                                'details '.($record->details_submitted ? 'submitted' : 'missing'),
                            ])),
                        Forms\Components\Toggle::make('charges_enabled')->disabled()->dehydrated(false),
                        Forms\Components\Toggle::make('payouts_enabled')->disabled()->dehydrated(false),
                        Forms\Components\Toggle::make('details_submitted')->disabled()->dehydrated(false),
                    ]),
                Forms\Components\Section::make('Commission')
                    ->schema([
                        Forms\Components\TextInput::make('commission_bps')
                            ->label('Commission override (basis points)')
                            ->helperText(fn (): string => 'Empty = the marketplace default of '.config('marketplace.fee_bps').' bps ('.rtrim(rtrim(number_format(config('marketplace.fee_bps') / 100, 2), '0'), '.').' %). 1000 bps = 10 %; 0 = no commission.')
                            ->numeric()->integer()->minValue(0)->maxValue(10000)
                            ->nullable(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->columns([
                Tables\Columns\TextColumn::make('shop_name')->searchable()->sortable()->description(fn (SellerProfile $record): string => '/sellers/'.$record->slug),
                Tables\Columns\TextColumn::make('user.name')->label('Owner')->searchable(['name', 'email'])->description(fn (SellerProfile $record): ?string => $record->user?->email),
                Tables\Columns\TextColumn::make('country')->formatStateUsing(fn ($state): string => Countries::name($state)),
                Tables\Columns\TextColumn::make('stripe_account_id')->label('Stripe account')->placeholder('not connected')->copyable()->toggleable(),
                Tables\Columns\IconColumn::make('charges_enabled')->label('Charges')->boolean(),
                Tables\Columns\IconColumn::make('payouts_enabled')->label('Payouts')->boolean(),
                Tables\Columns\IconColumn::make('details_submitted')->label('Details')->boolean()->toggleable(),
                Tables\Columns\TextColumn::make('commission_bps')->label('Commission')
                    ->formatStateUsing(fn ($state): string => $state === null ? 'default' : number_format($state / 100, 2).' %')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('payouts_enabled')->label('Payouts'),
                Tables\Filters\TernaryFilter::make('connected')
                    ->label('Stripe account')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('stripe_account_id'),
                        false: fn ($query) => $query->whereNull('stripe_account_id'),
                    ),
            ])
            ->actions([
                static::refreshAction(),
                Tables\Actions\EditAction::make(),
            ]);
    }

    /** Pull charges/payouts/details flags from Stripe into the profile (table row action). */
    public static function refreshAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('refreshFromStripe')
            ->label('Refresh from Stripe')
            ->icon('heroicon-m-arrow-path')
            ->color('gray')
            ->visible(fn (SellerProfile $record): bool => filled($record->stripe_account_id))
            ->action(fn (SellerProfile $record) => static::refreshFromStripe($record));
    }

    public static function refreshFromStripe(SellerProfile $record): bool
    {
        try {
            $profile = app(ConnectOnboardingService::class)->refreshStatus($record);
        } catch (ConnectException $e) {
            Notification::make()->danger()->title('Could not refresh from Stripe')->body($e->getMessage())->send();

            return false;
        }

        Notification::make()
            ->success()
            ->title('Refreshed from Stripe')
            ->body('Charges '.($profile->charges_enabled ? 'enabled' : 'disabled').', payouts '.($profile->payouts_enabled ? 'enabled' : 'disabled').'.')
            ->send();

        return true;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSellerProfiles::route('/'),
            'edit' => Pages\EditSellerProfile::route('/{record}/edit'),
        ];
    }
}
