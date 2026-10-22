<?php

namespace App\Filament\Resources;

use App\Enums\FulfillmentMode;
use App\Enums\ListingCategory;
use App\Enums\ListingChannel;
use App\Enums\ListingCondition;
use App\Enums\ListingStatus;
use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\ListingResource\Pages;
use App\Models\Design;
use App\Models\Listing;
use App\Services\Marketplace\MarginReport;
use App\Support\Countries;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\HtmlString;

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
                        MoneyInput::make('price_cents')->label('Price')->required()->live(onBlur: true),
                        Forms\Components\Placeholder::make('unit_margin')
                            ->label('Margin per unit')
                            ->visible(fn (Forms\Get $get): bool => static::isStoreForm($get))
                            ->content(fn (Forms\Get $get): HtmlString => static::marginHtml(
                                MoneyInput::toCents($get('price_cents'), true),
                                MoneyInput::toCents($get('cost_cents'), true),
                            ))
                            ->columnSpan(2),
                        Forms\Components\TextInput::make('quantity')
                            ->label(fn (Forms\Get $get): string => $get('fulfillment') === FulfillmentMode::STOCK_THEN_DROPSHIP->value ? 'Own quantity' : 'Quantity')
                            ->numeric()->integer()->minValue(0)->required()->default(1)
                            // A product the supplier always ships has no stock of its own.
                            ->visible(fn (Forms\Get $get): bool => ! static::isPureDropship($get))
                            ->helperText(fn (Forms\Get $get): ?string => $get('fulfillment') === FulfillmentMode::STOCK_THEN_DROPSHIP->value
                                ? 'Sold from our own stock first; larger orders go to the supplier.' : null),
                        Forms\Components\TextInput::make('units_per_lot')->numeric()->integer()->minValue(1)->required()->default(1),
                    ]),
                Forms\Components\Section::make('Fulfilment and cost')
                    ->description('Who ships this product and what one unit costs us. Buyers never see the supplier or the cost.')
                    ->visible(fn (Forms\Get $get): bool => static::isStoreForm($get))
                    ->columns(2)
                    ->schema([
                        Forms\Components\Radio::make('fulfillment')
                            ->label('Fulfilment')
                            ->options(FulfillmentMode::options())
                            ->descriptions([
                                FulfillmentMode::STOCK->value => 'SDLabs ships it from its own inventory (the quantity above).',
                                FulfillmentMode::DROPSHIP->value => 'A supplier ships it straight to the buyer. No stock of our own; available while the supplier has it.',
                                FulfillmentMode::STOCK_THEN_DROPSHIP->value => 'Own stock first. An order the own stock cannot cover completely is shipped by the supplier instead.',
                            ])
                            ->default(FulfillmentMode::STOCK->value)
                            ->required()
                            ->live()
                            ->columnSpanFull(),
                        Forms\Components\Select::make('supplier_id')
                            ->label('Supplier')
                            ->relationship('supplier', 'name')
                            ->searchable()
                            ->preload()
                            ->visible(fn (Forms\Get $get): bool => static::usesSupplier($get))
                            ->required(fn (Forms\Get $get): bool => static::usesSupplier($get))
                            ->helperText('Purchase orders for this product go to this supplier.'),
                        Forms\Components\TextInput::make('supplier_sku')
                            ->label('Supplier SKU')
                            ->maxLength(255)
                            ->visible(fn (Forms\Get $get): bool => static::usesSupplier($get))
                            ->helperText('As the supplier knows it: matches the stock feed and appears on purchase orders.'),
                        MoneyInput::make('cost_cents')
                            ->label('Cost per unit')
                            ->nullable()
                            ->live(onBlur: true)
                            ->helperText(fn (Forms\Get $get): string => static::usesSupplier($get) ? 'What the supplier charges us for one unit (a stock feed with a cost column keeps it up to date).' : 'What one unit cost us to buy.'),
                        Forms\Components\Placeholder::make('supplier_stock_info')
                            ->label('Supplier stock')
                            ->visible(fn (Forms\Get $get, string $operation): bool => $operation === 'edit' && static::usesSupplier($get))
                            ->content(fn (?Listing $record): string => static::supplierStockText($record)),
                        Forms\Components\Placeholder::make('shipping_note')
                            ->hiddenLabel()
                            ->visible(fn (Forms\Get $get): bool => static::isPureDropship($get))
                            ->content('Shipping price and delivery time come from the supplier\'s settings.')
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Shipping and returns')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('ships_from_country')->options(Countries::all())->in(array_keys(Countries::all()))->searchable()->required(),
                        Forms\Components\TextInput::make('handling_days')->numeric()->integer()->minValue(0)->required()->default(3)
                            ->visible(fn (Forms\Get $get): bool => ! static::isPureDropship($get)),
                        MoneyInput::make('shipping_domestic_cents')->label('Domestic shipping')->required()
                            ->visible(fn (Forms\Get $get): bool => ! static::isPureDropship($get)),
                        MoneyInput::make('shipping_international_cents')->label('International shipping')->nullable()
                            ->visible(fn (Forms\Get $get): bool => ! static::isPureDropship($get)),
                        MoneyInput::make('additional_item_shipping_cents')->label('Each additional item')->required()
                            ->visible(fn (Forms\Get $get): bool => ! static::isPureDropship($get)),
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
                Tables\Columns\TextColumn::make('fulfillment')
                    ->label('Fulfilment')
                    ->badge()
                    ->state(fn (Listing $record): ?string => $record->isStore() ? $record->fulfillmentMode()->label() : null)
                    ->color(fn (Listing $record): string => $record->fulfillmentMode()->color())
                    ->placeholder('-')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('quantity')->label('Stock')->sortable()->state(fn (Listing $record): string => static::stockText($record)),
                Tables\Columns\TextColumn::make('margin')
                    ->label('Margin')
                    ->state(fn (Listing $record): ?string => $record->isStore() && ($bps = $record->marginBps()) !== null ? number_format($bps / 100, 1).' %' : null)
                    ->description(fn (Listing $record): ?string => $record->isStore() && ($cents = $record->unitMarginCents()) !== null ? money($cents).' per unit' : null)
                    ->color(fn (Listing $record): ?string => $record->isStore() ? static::marginColor($record->price_cents, $record->cost_cents) : null)
                    ->placeholder('-')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(ListingStatus::options()),
                Tables\Filters\SelectFilter::make('channel')->options(ListingChannel::options()),
                Tables\Filters\SelectFilter::make('category')->options(ListingCategory::options()),
                Tables\Filters\SelectFilter::make('condition')->options(ListingCondition::options()),
                Tables\Filters\SelectFilter::make('fulfillment')->options(FulfillmentMode::options()),
                Tables\Filters\SelectFilter::make('supplier_id')->label('Supplier')->relationship('supplier', 'name')->searchable()->preload(),
                Tables\Filters\Filter::make('low_margin')
                    ->label('Low or negative margin')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->where('channel', ListingChannel::STORE->value)
                        ->whereNotNull('cost_cents')
                        ->where('price_cents', '>', 0)
                        ->whereRaw('(price_cents - cost_cents) * 10000 < ? * price_cents', [max(0, (int) config('marketplace.min_margin_bps', 1500))])),
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
        $probe->fill(array_intersect_key($data, array_flip(['seller_id', 'channel', 'category', 'condition', 'driver_id', 'design_id', 'status', 'fulfillment', 'supplier_id'])));

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

    // --- Fulfilment (docs/PLAN.md 8.1) -----------------------------------------------------------------------------

    /** The form is for a store listing (the channel select says so). */
    public static function isStoreForm(Forms\Get $get): bool
    {
        return $get('channel') === ListingChannel::STORE->value;
    }

    /** A store listing whose mode needs a supplier (dropship, or own stock then dropship). */
    public static function usesSupplier(Forms\Get $get): bool
    {
        return static::isStoreForm($get) && (FulfillmentMode::tryFrom((string) $get('fulfillment'))?->usesSupplier() ?? false);
    }

    /** A store listing that the supplier always ships: no own quantity, no own shipping or handling time. */
    public static function isPureDropship(Forms\Get $get): bool
    {
        return static::isStoreForm($get) && $get('fulfillment') === FulfillmentMode::DROPSHIP->value;
    }

    /**
     * Fix the fulfilment fields of the saved form data: only store listings are dropshipped, a mode without a supplier
     * keeps none, and a product the supplier always ships has no own quantity.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeFulfilment(array $data): array
    {
        $store = ($data['channel'] ?? null) === ListingChannel::STORE->value;
        $mode = $store ? (FulfillmentMode::tryFrom((string) ($data['fulfillment'] ?? '')) ?? FulfillmentMode::STOCK) : FulfillmentMode::STOCK;

        $data['fulfillment'] = $mode->value;

        if (! $mode->usesSupplier()) {
            $data['supplier_id'] = null;
            $data['supplier_sku'] = null;
            $data['supplier_stock'] = null;
            $data['supplier_stock_synced_at'] = null;
        }

        if (! $mode->usesStock()) {
            $data['quantity'] = 0;
        }

        return $data;
    }

    /** The unit margin at this price and cost, for the form: coloured, with a warning below marketplace.min_margin_bps. */
    public static function marginHtml(?int $priceCents, ?int $costCents): HtmlString
    {
        $margin = $priceCents !== null ? MarginReport::unitMargin($priceCents, $costCents) : null;

        if ($margin === null) {
            return new HtmlString('<span class="text-sm text-gray-500 dark:text-gray-400">Enter the price and the cost per unit to see the margin.</span>');
        }

        $color = static::marginColor($priceCents, $costCents);
        $class = match ($color) {
            'danger' => 'text-danger-600 dark:text-danger-400',
            'warning' => 'text-warning-600 dark:text-warning-400',
            default => 'text-success-600 dark:text-success-400',
        };
        $warning = MarginReport::lowMarginWarning($priceCents, $costCents);

        return new HtmlString(
            '<span class="text-sm font-semibold '.$class.'" data-unit-margin>'.e(money($margin['cents'])).' ('.e(number_format($margin['bps'] / 100, 1)).' %)</span>'
            .($warning ? '<span class="mt-1 block text-sm '.$class.'" data-margin-warning>'.e($warning).'</span>' : '')
        );
    }

    /** success, warning (below the minimum margin) or danger (a loss); null when the cost is not known. */
    public static function marginColor(?int $priceCents, ?int $costCents): ?string
    {
        $margin = $priceCents !== null ? MarginReport::unitMargin($priceCents, $costCents) : null;

        return match (true) {
            $margin === null => null,
            $margin['cents'] < 0 => 'danger',
            $margin['bps'] < max(0, (int) config('marketplace.min_margin_bps', 1500)) => 'warning',
            default => 'success',
        };
    }

    /** What the supplier's feed last said about this product. */
    public static function supplierStockText(?Listing $listing): string
    {
        if ($listing === null || $listing->supplier_stock === null) {
            return 'Unknown (counts as available). The stock feed sets it.'.($listing?->supplier_stock_synced_at ? ' Last sync '.$listing->supplier_stock_synced_at->diffForHumans().'.' : '');
        }

        return $listing->supplier_stock.' in stock at the supplier'.($listing->supplier_stock_synced_at ? ', synced '.$listing->supplier_stock_synced_at->diffForHumans() : '').'.';
    }

    /** The Stock column: own units, the supplier's, or both. */
    public static function stockText(Listing $listing): string
    {
        if (! $listing->isStore()) {
            return (string) $listing->quantity;
        }

        $supplier = $listing->supplier_stock !== null ? (string) $listing->supplier_stock : '?';

        return match ($listing->fulfillmentMode()) {
            FulfillmentMode::STOCK => (string) $listing->quantity,
            FulfillmentMode::DROPSHIP => 'Supplier: '.$supplier,
            FulfillmentMode::STOCK_THEN_DROPSHIP => $listing->quantity.' + supplier '.$supplier,
        };
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
