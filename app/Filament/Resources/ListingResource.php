<?php

namespace App\Filament\Resources;

use App\Enums\ListingCategory;
use App\Enums\ListingChannel;
use App\Enums\ListingCondition;
use App\Enums\ListingStatus;
use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\ListingResource\Pages;
use App\Models\Design;
use App\Models\Listing;
use App\Support\Countries;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ListingResource extends Resource
{
    protected static ?string $model = Listing::class;

    protected static ?string $navigationIcon = 'phosphor-tag-duotone';

    protected static ?string $navigationGroup = 'Marketplace';

    protected static ?int $navigationSort = 2;

    /**
     * The channel a new listing starts in. Admins mostly create store listings here (members create their own
     * marketplace listings from the dashboard), so the form starts as a store listing: category Driver,
     * condition New, seller the signed-in admin. `?channel=marketplace` starts a marketplace one.
     */
    public static function defaultChannel(): ListingChannel
    {
        return request()->query('channel') === ListingChannel::MARKETPLACE->value
            ? ListingChannel::MARKETPLACE
            : ListingChannel::STORE;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Listing')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('seller_id')
                            ->label('Seller')
                            ->relationship('seller', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            // A new listing is the signed-in admin's own: SDLabs sells it as the platform.
                            ->default(fn (): ?int => auth()->id()),
                        Forms\Components\Select::make('status')
                            ->options(ListingStatus::options())
                            ->required()
                            ->default(ListingStatus::DRAFT->value)
                            ->helperText('Activating a listing whose seller cannot be paid is allowed for admins, with a warning.'),
                        Forms\Components\Select::make('channel')
                            ->options(ListingChannel::options())
                            ->required()
                            ->default(fn (): string => static::defaultChannel()->value)
                            ->live()
                            ->afterStateUpdated(fn (Forms\Set $set, ?string $state) => $set('category', $state === ListingChannel::STORE->value ? ListingCategory::DRIVER->value : null))
                            ->helperText('Store: SDLabs sells a new catalogue driver from its driver page (seller must be an admin). Marketplace: kits, finished speakers and used gear.'),
                        Forms\Components\Select::make('category')
                            ->options(fn (Forms\Get $get): array => $get('channel') === ListingChannel::STORE->value
                                ? [ListingCategory::DRIVER->value => ListingCategory::DRIVER->label()]
                                : ListingCategory::marketplaceOptions())
                            ->required()
                            ->default(fn (): ?string => static::defaultChannel() === ListingChannel::STORE ? ListingCategory::DRIVER->value : null)
                            ->live(),
                        Forms\Components\TextInput::make('title')->required()->maxLength(160)->columnSpanFull(),
                        Forms\Components\Select::make('condition')
                            ->options(ListingCondition::options())
                            ->in(array_keys(ListingCondition::options()))
                            ->required()
                            ->default(ListingCondition::NEW->value),
                        Forms\Components\Select::make('driver_id')
                            ->label('Catalogue driver')
                            ->relationship('driver', 'model')
                            ->getOptionLabelFromRecordUsing(fn ($record): string => $record->fullName())
                            ->searchable(['brand', 'model'])
                            ->preload()
                            ->required(fn (Forms\Get $get): bool => $get('channel') === ListingChannel::STORE->value),
                        Forms\Components\Select::make('design_id')
                            ->label('Built from design')
                            ->relationship('design', 'name')
                            ->getOptionLabelFromRecordUsing(fn (Design $record): string => $record->name)
                            ->searchable()
                            ->preload()
                            ->helperText('Optional: the site design a kit or completed speaker is built from.'),
                        Forms\Components\Textarea::make('description')->rows(6)->columnSpanFull(),
                        Forms\Components\FileUpload::make('photos')
                            ->image()
                            ->multiple()
                            ->maxFiles(8)
                            ->reorderable()
                            ->disk('public')
                            ->visibility('public')
                            ->directory(fn (Forms\Get $get): string => 'listings/'.($get('uuid') ?: 'unassigned').'/photos')
                            ->columnSpanFull(),
                        // The photo folder is listings/{uuid}: the uuid exists before the first upload.
                        Forms\Components\Hidden::make('uuid')->default(fn (): string => (string) \Illuminate\Support\Str::uuid())->visibleOn('create'),
                        Forms\Components\TextInput::make('uuid')->disabled()->dehydrated(false)->visibleOn('edit'),
                    ]),
                Forms\Components\Section::make('Price and stock')
                    ->columns(3)
                    ->schema([
                        MoneyInput::make('price_cents')->label('Price')->required(),
                        Forms\Components\TextInput::make('quantity')->numeric()->integer()->minValue(0)->required()->default(1),
                        Forms\Components\TextInput::make('units_per_lot')->numeric()->integer()->minValue(1)->required()->default(1),
                    ]),
                Forms\Components\Section::make('Shipping and returns')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('ships_from_country')->options(Countries::all())->in(array_keys(Countries::all()))->searchable()->required(),
                        Forms\Components\TextInput::make('handling_days')->numeric()->integer()->minValue(0)->required()->default(3),
                        MoneyInput::make('shipping_domestic_cents')->label('Domestic shipping')->required(),
                        MoneyInput::make('shipping_international_cents')->label('International shipping')->nullable(),
                        MoneyInput::make('additional_item_shipping_cents')->label('Each additional item')->required(),
                        Forms\Components\Toggle::make('returns_accepted')->live(),
                        Forms\Components\Textarea::make('return_policy')->rows(3)->visible(fn (Forms\Get $get): bool => (bool) $get('returns_accepted'))->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('cover')
                    ->label('')
                    ->state(fn (Listing $record): ?string => $record->photos[0] ?? null)
                    ->disk('public')
                    ->square(),
                Tables\Columns\TextColumn::make('channel')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => ($state instanceof ListingChannel ? $state : ListingChannel::from((string) $state))->label())
                    ->color(fn ($state): string => ($state instanceof ListingChannel ? $state : ListingChannel::from((string) $state))->color()),
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->limit(50)
                    ->description(fn (Listing $record): string => $record->category->label().' - '.$record->condition->label()),
                Tables\Columns\TextColumn::make('seller.name')
                    ->label('Seller')
                    ->searchable()
                    ->description(fn (Listing $record): ?string => $record->seller?->sellerProfile?->shop_name),
                Tables\Columns\SelectColumn::make('status')
                    ->options(ListingStatus::options())
                    ->selectablePlaceholder(false)
                    ->afterStateUpdated(fn (Listing $record, $state) => static::warnAboutBlockers($record, (string) $state)),
                Tables\Columns\TextColumn::make('price_cents')
                    ->label('Price')
                    ->formatStateUsing(fn ($state): string => money((int) $state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('quantity')->label('Stock')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(ListingStatus::options()),
                Tables\Filters\SelectFilter::make('channel')->options(ListingChannel::options()),
                Tables\Filters\SelectFilter::make('category')->options(ListingCategory::options()),
                Tables\Filters\SelectFilter::make('condition')->options(ListingCondition::options()),
                Tables\Filters\SelectFilter::make('seller_id')->label('Seller')->relationship('seller', 'name')->searchable()->preload(),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('Public page')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->url(fn (Listing $record): string => route('listing', ['uuid' => $record->uuid]))
                    ->openUrlInNewTab(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\RestoreAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Admins may switch a listing on whatever its publishBlockers() say, but they are told what is wrong
     * (a seller without payouts, no stock, no price).
     */
    public static function warnAboutBlockers(Listing $listing, string $status): void
    {
        if ($status !== ListingStatus::ACTIVE->value || ($blockers = $listing->publishBlockers()) === []) {
            return;
        }

        Notification::make()
            ->warning()
            ->title('Activated despite open issues')
            ->body(implode(' ', $blockers))
            ->persistent()
            ->send();
    }

    /**
     * The store / marketplace rule violations of the form data (see Listing::channelRuleViolations()), as a
     * list of messages. Empty = the listing may be saved.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    public static function channelViolations(array $data, ?Listing $record = null): array
    {
        $probe = $record ? clone $record : new Listing;
        $probe->fill(array_intersect_key($data, array_flip(['seller_id', 'channel', 'category', 'condition', 'driver_id', 'design_id', 'status'])));

        return array_values($probe->channelRuleViolations());
    }

    /** Tells the admin why the data breaks the store / marketplace rules. Returns true when it is fine to save. */
    public static function passesChannelRules(array $data, ?Listing $record = null): bool
    {
        if (($violations = static::channelViolations($data, $record)) === []) {
            return true;
        }

        Notification::make()->danger()->title('This listing breaks a rule')->body(implode(' ', $violations))->persistent()->send();

        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListListings::route('/'),
            'create' => Pages\CreateListing::route('/create'),
            'edit' => Pages\EditListing::route('/{record}/edit'),
        ];
    }
}
