<?php

namespace App\Filament\Resources;

use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\SupplierResource\Pages;
use App\Models\Supplier;
use App\Services\Marketplace\SupplierFeedSync;
use App\Support\Countries;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The companies that dropship for the SDLabs store (docs/PLAN.md 8.1). Internal: buyers never see a supplier's name,
 * only the lead time and the parcels. Admins only (SupplierPolicy). The stock feed (a URL or an uploaded CSV on the
 * private disk) can be synced from here and a CSV template downloaded for the supplier.
 */
class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static ?string $navigationIcon = 'phosphor-truck-duotone';

    protected static ?string $navigationGroup = 'Store';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Supplier')
                    ->description('Internal. Buyers never see the supplier\'s name, only the delivery time.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')->required()->maxLength(255),
                        Forms\Components\Toggle::make('active')
                            ->default(true)
                            ->helperText('An inactive supplier\'s products count as out of stock and its stock feed is not read.')
                            ->inline(false),
                        Forms\Components\TextInput::make('website')->maxLength(255)->url()->placeholder('https://')->helperText('Their web site or ordering portal.'),
                        Forms\Components\TextInput::make('account_number')->label('Our account number')->maxLength(255),
                        Forms\Components\Select::make('ships_from_country')
                            ->label('Ships from')
                            ->options(Countries::all())
                            ->in(array_keys(Countries::all()))
                            ->searchable()
                            ->required()
                            ->default(strtoupper((string) config('marketplace.default_country', 'US')))
                            ->helperText('Buyers in this country pay the domestic shipping price below, everybody else the international one.'),
                        Forms\Components\Textarea::make('notes')->rows(3)->maxLength(5000)->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Contact')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('contact_name')->maxLength(255),
                        Forms\Components\TextInput::make('contact_email')->email()->maxLength(255),
                        Forms\Components\TextInput::make('contact_phone')->maxLength(255)->extraInputAttributes(['inputmode' => 'tel']),
                    ]),

                Forms\Components\Section::make('Purchase orders')
                    ->description('What happens when a buyer pays for one of this supplier\'s products.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('order_email')
                            ->label('Order e-mail')
                            ->email()
                            ->maxLength(255)
                            ->required(fn (Forms\Get $get): bool => (bool) $get('auto_send_purchase_orders'))
                            ->helperText('Purchase orders are e-mailed here. Leave empty for a web portal: you place the order there and mark it as placed.'),
                        Forms\Components\Toggle::make('auto_send_purchase_orders')
                            ->label('Send purchase orders automatically')
                            ->live()
                            ->inline(false)
                            ->helperText('Off: new purchase orders wait in the queue until an admin sends them.'),
                        Forms\Components\Toggle::make('blind_dropship')
                            ->label('Blind dropship')
                            ->default(true)
                            ->inline(false)
                            ->helperText('The parcel goes out without supplier branding, invoices or prices. The purchase order says so.'),
                        MoneyInput::make('dropship_fee_cents')
                            ->label('Dropship fee per order')
                            ->default(0)
                            ->helperText('What the supplier charges us for each purchase order (counted in the margin report).'),
                        Forms\Components\TextInput::make('lead_time_min_days')
                            ->label('Delivery time, from (business days)')
                            ->numeric()->integer()->minValue(0)->maxValue(365)->required()->default(3),
                        Forms\Components\TextInput::make('lead_time_max_days')
                            ->label('Delivery time, to (business days)')
                            ->numeric()->integer()->minValue(0)->maxValue(365)->required()->default(7)->gte('lead_time_min_days')
                            ->helperText('Buyers read "Ships in 3–7 business days".'),
                    ]),

                Forms\Components\Section::make('Shipping the buyer pays')
                    ->description('What we charge the buyer for this supplier\'s parcel. Not what the supplier charges us (enter that on the purchase order).')
                    ->columns(2)
                    ->schema([
                        MoneyInput::make('shipping_domestic_cents')->label('Domestic shipping')->required()->default(0),
                        MoneyInput::make('shipping_international_cents')->label('International shipping')->nullable()
                            ->helperText('Leave empty if this supplier only ships domestically.'),
                        MoneyInput::make('additional_item_shipping_cents')->label('Each additional item')->required()->default(0),
                        MoneyInput::make('free_shipping_over_cents')->label('Free shipping over')->nullable()
                            ->helperText('Leave empty for never. Counts the items in this supplier\'s parcel.'),
                    ]),

                Forms\Components\Section::make('Stock feed')
                    ->description('A CSV with the supplier\'s SKU, stock and optionally cost. Read every hour (and with "Sync stock now"). Use a URL or upload a file.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('feed_url')
                            ->label('Feed URL')
                            ->url()
                            ->maxLength(500)
                            ->placeholder('https://')
                            ->helperText('A public http(s) address that returns the CSV.'),
                        Forms\Components\FileUpload::make('feed_file_path')
                            ->label('Or upload a CSV')
                            ->disk((string) config('marketplace.private_disk', 'private'))
                            ->directory('suppliers/feeds')
                            ->visibility('private')
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel', 'text/x-csv'])
                            ->maxSize(max(1, (int) config('marketplace.supplier_feed_max_kb', 5120)))
                            ->helperText('Kept on the private disk. Upload a new file whenever the supplier sends one; the URL is used first when both are set.'),
                        Forms\Components\TextInput::make('feed_sku_column')->label('SKU column')->required()->default('sku')->maxLength(255),
                        Forms\Components\TextInput::make('feed_quantity_column')->label('Quantity column')->required()->default('quantity')->maxLength(255),
                        Forms\Components\TextInput::make('feed_cost_column')->label('Cost column (optional)')->maxLength(255)
                            ->helperText('Map it to keep each product\'s cost up to date from the feed. Leave empty if the feed has no prices.'),
                        Forms\Components\Placeholder::make('feed_state')
                            ->label('Last sync')
                            ->visibleOn('edit')
                            ->content(fn (?Supplier $record): string => static::feedStatusText($record)),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('listings'))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (Supplier $record): ?string => $record->contact_name ?: $record->contact_email),
                Tables\Columns\IconColumn::make('active')->boolean()->sortable(),
                Tables\Columns\TextColumn::make('feed_status')
                    ->label('Feed')
                    ->state(fn (Supplier $record): string => static::feedStatus($record))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Error' => 'danger',
                        'Synced' => 'success',
                        'Not synced yet' => 'warning',
                        default => 'gray',
                    })
                    ->description(fn (Supplier $record): ?string => $record->hasFeed() ? (filled($record->feed_url) ? 'URL' : 'Uploaded file') : null),
                Tables\Columns\TextColumn::make('feed_last_synced_at')->label('Last sync')->since()->sortable()->placeholder('never'),
                Tables\Columns\TextColumn::make('feed_last_error')->label('Last error')->limit(60)->tooltip(fn (Supplier $record): ?string => $record->feed_last_error)->placeholder('-')->color('danger'),
                Tables\Columns\TextColumn::make('listings_count')->label('Listings')->alignCenter()->sortable(),
                Tables\Columns\TextColumn::make('lead_time')
                    ->label('Delivery')
                    ->state(fn (Supplier $record): string => $record->leadTimeText())
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('order_email')->label('Order e-mail')->placeholder('web portal')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('auto_send_purchase_orders')->label('Auto-send')->boolean()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->filters([
                Tables\Filters\TernaryFilter::make('active')->placeholder('All suppliers')->trueLabel('Active')->falseLabel('Inactive'),
                Tables\Filters\Filter::make('feed_errors')
                    ->label('Feed errors')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->whereNotNull('feed_last_error')->where('feed_last_error', '!=', '')),
            ])
            ->actions([
                Tables\Actions\Action::make('syncNow')
                    ->label('Sync stock now')
                    ->icon('heroicon-m-arrow-path')
                    ->visible(fn (Supplier $record): bool => $record->hasFeed())
                    ->action(fn (Supplier $record) => static::syncNow($record)),
                Tables\Actions\Action::make('feedTemplate')
                    ->label('Download feed template')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->color('gray')
                    ->action(fn (Supplier $record): StreamedResponse => static::downloadTemplate($record)),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Supplier $record): bool => static::canBeDeleted($record))
                    ->modalDescription('The supplier is removed for good.'),
            ]);
    }

    /** A supplier with listings or purchase orders stays (set it inactive instead); the database would refuse anyway. */
    public static function canBeDeleted(Supplier $supplier): bool
    {
        return ! $supplier->listings()->exists() && ! $supplier->purchaseOrders()->exists();
    }

    /** Read the supplier's feed now and tell the admin what happened. */
    public static function syncNow(Supplier $supplier): void
    {
        $result = app(SupplierFeedSync::class)->sync($supplier);

        $result->ok
            ? Notification::make()->success()->title('Stock synced')->body($result->summary())->send()
            : Notification::make()->danger()->title('The stock feed could not be read')->body((string) $result->error)->persistent()->send();
    }

    /** The CSV template with the configured column headers. */
    public static function downloadTemplate(Supplier $supplier): StreamedResponse
    {
        $csv = app(SupplierFeedSync::class)->templateCsv($supplier);

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, Str::slug($supplier->name ?: 'supplier').'-stock-feed-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** No feed / Not synced yet / Synced / Error. */
    public static function feedStatus(Supplier $supplier): string
    {
        return match (true) {
            filled($supplier->feed_last_error) => 'Error',
            ! $supplier->hasFeed() => 'No feed',
            $supplier->feed_last_synced_at === null => 'Not synced yet',
            default => 'Synced',
        };
    }

    public static function feedStatusText(?Supplier $supplier): string
    {
        if ($supplier === null || ! $supplier->hasFeed()) {
            return 'No stock feed set up.';
        }

        $when = $supplier->feed_last_synced_at ? 'Last synced '.$supplier->feed_last_synced_at->diffForHumans().'.' : 'Not synced yet.';

        return filled($supplier->feed_last_error) ? $when.' Last error: '.$supplier->feed_last_error : $when;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSuppliers::route('/'),
            'create' => Pages\CreateSupplier::route('/create'),
            'edit' => Pages\EditSupplier::route('/{record}/edit'),
        ];
    }
}
