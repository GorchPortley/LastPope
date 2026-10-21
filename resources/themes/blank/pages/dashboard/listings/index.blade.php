<?php

use App\Enums\ListingCondition;
use App\Enums\ListingStatus;
use App\Filament\Forms\MoneyInput;
use App\Models\Driver;
use App\Models\Listing;
use App\Services\Marketplace\ConnectOnboardingService;
use App\Support\Countries;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use FilamentTiptapEditor\Enums\TiptapOutput;
use FilamentTiptapEditor\TiptapEditor;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Livewire\Volt\Component;
use function Laravel\Folio\{middleware, name};

middleware('auth');
name('dashboard.listings');

new class extends Component implements HasForms, Tables\Contracts\HasTable {
    use InteractsWithForms, InteractsWithTable;

    // Photos, rich-text attachments: public disk under listings/{uuid}/... (the uuid is filled in when the
    // create form opens, so the folder is known while files upload).
    function listingdir($uuid, string $sub): string
    {
        return 'listings/'.($uuid ?: 'unassigned').'/'.$sub;
    }

    /**
     * The status a listing gets when the seller asks for $published: ACTIVE only when publishBlockers() is empty.
     * Otherwise it stays (or goes back to) a draft / paused and the reasons are shown.
     */
    function resolveStatus(bool $published, array $data, ?Listing $record = null): ListingStatus
    {
        $current = $record?->status ?? ListingStatus::DRAFT;
        $wasLive = in_array($current, [ListingStatus::ACTIVE, ListingStatus::SOLD_OUT], true);

        if (! $published) {
            return $wasLive ? ListingStatus::PAUSED : $current;
        }

        $probe = $record ? clone $record : new Listing(['seller_id' => auth()->id()]);
        $probe->fill(Arr::only($data, ['price_cents', 'quantity']));

        // A sold-out listing stays published; restocking it (quantity above zero) puts it back on sale.
        if ($current === ListingStatus::SOLD_OUT && (int) $probe->quantity < 1) {
            return ListingStatus::SOLD_OUT;
        }

        if ($blockers = $probe->publishBlockers()) {
            Notification::make()
                ->warning()
                ->title($wasLive ? 'Listing paused' : 'Saved as a draft')
                ->body(implode(' ', $blockers))
                ->send();

            return $wasLive ? ListingStatus::PAUSED : $current;
        }

        return ListingStatus::ACTIVE;
    }

    /** Form data to model attributes (create or edit). */
    function prepare(array $data, ?Listing $record = null): array
    {
        $published = (bool) Arr::pull($data, 'published', false);

        if ($record) {
            unset($data['uuid'], $data['seller_id']);
        } else {
            $uuid = (string) ($data['uuid'] ?? '');
            $data['uuid'] = Str::isUuid($uuid) && ! Listing::withTrashed()->where('uuid', $uuid)->exists() ? $uuid : (string) Str::uuid();
            $data['seller_id'] = auth()->id();
            // Every seller gets a shop page (shop name = their name until they pick another one).
            app(ConnectOnboardingService::class)->ensureProfile(auth()->user());
        }

        $data['photos'] = array_values((array) ($data['photos'] ?? []));
        $data['ships_from_country'] = strtoupper((string) $data['ships_from_country']);
        $data['status'] = $this->resolveStatus($published, $data, $record)->value;

        return $data;
    }

    function listingSchema(): array
    {
        return [
            Toggle::make('published')
                ->label('Published')
                ->onColor('success')
                ->afterStateHydrated(function (Toggle $component) {
                    $record = $component->getRecord();
                    $component->state($record ? $record->status === ListingStatus::ACTIVE : false);
                })
                ->helperText(function (): ?string {
                    $user = auth()->user();

                    return $user->isPlatformSeller() || $user->canReceivePayouts()
                        ? 'Shoppers can find and buy it while it is published and in stock.'
                        : 'Connect Stripe to publish paid listings. Until then it is saved as a draft.';
                }),

            Hidden::make('uuid')->default(fn () => (string) Str::uuid()),

            Section::make('What are you selling?')
                ->schema([
                    TextInput::make('title')
                        ->required()
                        ->maxLength(160)
                        ->placeholder('Dayton Audio RS225-8 8" reference woofer'),
                    Select::make('driver_id')
                        ->label('Driver from the catalogue (optional)')
                        ->helperText('Link a driver from the database so shoppers see its specs and measurements.')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => Driver::query()->active()
                            ->where(fn ($q) => $q->where('brand', 'like', "%{$search}%")->orWhere('model', 'like', "%{$search}%"))
                            ->orderBy('brand')->orderBy('model')->limit(50)->get()
                            ->mapWithKeys(fn (Driver $driver) => [$driver->id => $driver->fullName().' ('.$driver->category.')'])->all())
                        ->getOptionLabelUsing(fn ($value): ?string => Driver::find($value)?->fullName())
                        ->exists(table: 'drivers', column: 'id')
                        ->nullable(),
                    Select::make('condition')
                        ->options(ListingCondition::options())
                        ->in(array_keys(ListingCondition::options()))
                        ->required()
                        ->default(ListingCondition::NEW->value)
                        ->native(false),
                    FileUpload::make('photos')
                        ->label('Photos')
                        ->helperText('Up to 8 pictures; the first one is the cover. Drag to reorder.')
                        ->image()
                        ->multiple()
                        ->maxFiles(8)
                        ->maxSize(8192)
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->reorderable()
                        ->disk('public')
                        ->visibility('public')
                        ->directory(fn (Get $get) => $this->listingdir($get('uuid'), 'photos'))
                        ->required(),
                    TiptapEditor::make('description')
                        ->profile('simple')
                        ->hint('Condition details, what is included, history, measurements.')
                        ->disk('public')
                        ->directory(fn (Get $get) => $this->listingdir($get('uuid'), 'description'))
                        ->output(TiptapOutput::Html)
                        ->extraInputAttributes(['style' => 'min-height: 12rem;']),
                ]),

            Section::make('Price and stock')
                ->columns(3)
                ->schema([
                    MoneyInput::make('price_cents')
                        ->label('Price')
                        ->helperText('For one lot (see "units per lot").')
                        ->required()
                        ->default(0),
                    TextInput::make('quantity')
                        ->label('Quantity in stock')
                        ->numeric()->integer()->minValue(0)->maxValue(10000)
                        ->required()
                        ->default(1),
                    TextInput::make('units_per_lot')
                        ->label('Units per lot')
                        ->helperText('2 = sold as a pair.')
                        ->numeric()->integer()->minValue(1)->maxValue(24)
                        ->required()
                        ->default(1),
                ]),

            Section::make('Shipping')
                ->columns(2)
                ->schema([
                    Select::make('ships_from_country')
                        ->label('Ships from')
                        ->options(Countries::all())
                        ->in(array_keys(Countries::all()))
                        ->searchable()
                        ->required()
                        ->default(fn () => auth()->user()->sellerProfile?->country ?? strtoupper((string) config('marketplace.default_country', 'US'))),
                    TextInput::make('handling_days')
                        ->label('Handling time')
                        ->numeric()->integer()->minValue(0)->maxValue(30)
                        ->suffix('days')
                        ->required()
                        ->default(3),
                    MoneyInput::make('shipping_domestic_cents')
                        ->label('Shipping within your country')
                        ->required()
                        ->default(0),
                    MoneyInput::make('shipping_international_cents')
                        ->label('International shipping')
                        ->helperText('Leave empty to ship only within your country.')
                        ->nullable(),
                    MoneyInput::make('additional_item_shipping_cents')
                        ->label('Each additional item')
                        ->helperText('Added per extra unit in the same order.')
                        ->required()
                        ->default(0),
                ]),

            Section::make('Returns')
                ->schema([
                    Toggle::make('returns_accepted')->label('I accept returns')->live(),
                    Textarea::make('return_policy')
                        ->label('Return policy')
                        ->rows(3)
                        ->maxLength(1000)
                        ->visible(fn (Get $get): bool => (bool) $get('returns_accepted')),
                ]),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Listing::query()->where('seller_id', auth()->id()))
            ->heading('Listings')
            ->description('New and used drivers and gear you sell in the marketplace.')
            ->headerActions([
                CreateAction::make()
                    ->label('New listing')
                    ->createAnother(false)
                    ->modalHeading('New listing')
                    ->modalWidth('4xl')
                    ->slideOver()
                    ->form($this->listingSchema())
                    ->mutateFormDataUsing(fn (array $data): array => $this->prepare($data)),
            ])
            ->columns([
                ToggleColumn::make('published')
                    ->label('Published')
                    ->onColor('success')
                    ->getStateUsing(fn (Listing $record): bool => $record->status === ListingStatus::ACTIVE)
                    ->updateStateUsing(function (Listing $record, $state): bool {
                        $status = $this->resolveStatus((bool) $state, [], $record);
                        $record->update(['status' => $status->value]);

                        return $status === ListingStatus::ACTIVE;
                    }),
                ImageColumn::make('cover')
                    ->label('')
                    ->visibleFrom('md')
                    ->state(fn (Listing $record): ?string => $record->photos[0] ?? null)
                    ->disk('public')
                    ->square()
                    ->size(48),
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->limit(48)
                    ->description(fn (Listing $record): string => $record->condition->label().($record->isLot() ? ' - lot of '.$record->units_per_lot : '')),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => ($state instanceof ListingStatus ? $state : ListingStatus::from((string) $state))->label())
                    ->color(fn ($state): string => ($state instanceof ListingStatus ? $state : ListingStatus::from((string) $state))->color()),
                TextColumn::make('price_cents')
                    ->label('Price')
                    ->formatStateUsing(fn ($state): string => money((int) $state))
                    ->sortable(),
                TextColumn::make('quantity')
                    ->label('In stock')
                    ->visibleFrom('md')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(10)
            ->filters([
                SelectFilter::make('status')->options(ListingStatus::options()),
                SelectFilter::make('condition')->options(ListingCondition::options()),
            ])
            ->actions([
                ActionGroup::make([
                    Action::make('view')
                        ->label('View')
                        ->icon('heroicon-m-eye')
                        ->color('gray')
                        ->url(fn (Listing $record): string => route('listing', ['uuid' => $record->uuid])),
                    EditAction::make()
                        ->modalHeading(fn (Listing $record): string => 'Edit '.$record->title)
                        ->modalWidth('4xl')
                        ->slideOver()
                        ->form($this->listingSchema())
                        ->mutateFormDataUsing(fn (array $data, Listing $record): array => $this->prepare($data, $record)),
                    DeleteAction::make()
                        ->modalHeading('Delete this listing?')
                        ->modalDescription('It disappears from the marketplace. Existing orders keep their record of it.'),
                ]),
            ])
            ->emptyStateHeading('No listings yet')
            ->emptyStateDescription('Create your first listing to start selling.');
    }

    public function with(): array
    {
        $user = auth()->user();

        return [
            'needsStripe' => ! $user->isPlatformSeller() && ! $user->canReceivePayouts(),
        ];
    }
}; ?>

<x-layouts.app>
    @volt('dashboard.listings')
    <div class="space-y-4">
        @if ($needsStripe)
            <x-marketplace.notice level="warning">
                <p class="font-medium">Connect Stripe to publish paid listings.</p>
                <p>You can prepare drafts now; they go live as soon as your payout account is active.
                    <a href="{{ route('selling.dashboard') }}">Set up payouts</a>.</p>
            </x-marketplace.notice>
        @endif

        {{ $this->table }}
    </div>
    @endvolt
</x-layouts.app>
