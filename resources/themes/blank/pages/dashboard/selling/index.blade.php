<?php

use App\Enums\SellerOrderStatus;
use App\Exceptions\Marketplace\ConnectException;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Services\Marketplace\ConnectOnboardingService;
use App\Services\Marketplace\PayoutService;
use App\Support\Countries;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Table;
use Livewire\Volt\Component;
use function Laravel\Folio\{middleware, name};

middleware('auth');
name('selling.dashboard');

new class extends Component implements HasForms, Tables\Contracts\HasTable {
    use InteractsWithForms, InteractsWithTable;

    public ?array $data = [];

    public function mount(): void
    {
        $profile = auth()->user()->sellerProfile;

        $this->form->fill([
            'shop_name' => $profile?->shop_name ?? auth()->user()->name,
            'slug' => $profile?->slug,
            'bio' => $profile?->bio,
            'country' => $profile?->country ?? strtoupper((string) config('marketplace.default_country', 'US')),
        ]);
    }

    public function form(Form $form): Form
    {
        $profile = auth()->user()->sellerProfile;
        $locked = filled($profile?->stripe_account_id);

        return $form
            ->schema([
                TextInput::make('shop_name')
                    ->label('Shop name')
                    ->helperText('Shown on your listings and your public shop page.')
                    ->required()
                    ->maxLength(100),
                TextInput::make('slug')
                    ->label('Shop URL')
                    ->prefix(url('/sellers').'/')
                    ->helperText($profile ? 'Changing it breaks old links to your shop.' : 'Leave empty to build it from the shop name.')
                    ->alphaDash()
                    ->maxLength(80)
                    ->required(fn () => $profile !== null)
                    ->unique(table: 'seller_profiles', column: 'slug', ignorable: $profile),
                Textarea::make('bio')
                    ->label('About your shop')
                    ->rows(4)
                    ->maxLength(2000),
                Select::make('country')
                    ->label('Country')
                    ->options(Countries::all())
                    ->in(array_keys(Countries::all()))
                    ->searchable()
                    ->required()
                    ->disabled($locked)
                    ->dehydrated(! $locked)
                    ->helperText($locked ? 'Stripe fixes the country of a payout account when it is created.' : 'Where your business is based. It cannot be changed after you connect Stripe.'),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $user = auth()->user();
        $profile = $user->sellerProfile;

        if ($profile === null) {
            $profile = app(ConnectOnboardingService::class)->ensureProfile($user, $state['shop_name'], $state['country'] ?? null);
        }

        $profile->fill([
            'shop_name' => $state['shop_name'],
            'bio' => $state['bio'] ?? null,
            'country' => strtoupper((string) ($state['country'] ?? $profile->country)),
        ]);

        if (filled($state['slug'] ?? null)) {
            $profile->slug = $state['slug'];
        }

        $profile->save();
        $user->setRelation('sellerProfile', $profile);

        $this->form->fill([
            'shop_name' => $profile->shop_name, 'slug' => $profile->slug, 'bio' => $profile->bio, 'country' => $profile->country,
        ]);

        Notification::make()->success()->title('Shop profile saved')->send();
    }

    /** Ask Stripe once for the account flags (the account.updated webhook normally does it). */
    public function refreshStripe(): void
    {
        try {
            $profile = app(ConnectOnboardingService::class)->refreshStatus(auth()->user());
        } catch (ConnectException $e) {
            Notification::make()->danger()->title('Could not reach Stripe')->body($e->getMessage())->send();

            return;
        }

        $profile->canReceivePayouts()
            ? Notification::make()->success()->title('Payouts are set up')->send()
            : Notification::make()->info()->title('Stripe is still verifying your details')->send();
    }

    /** The five latest sales, as a table like the ones on the other dashboard pages. */
    public function table(Table $table): Table
    {
        $status = fn ($state): SellerOrderStatus => $state instanceof SellerOrderStatus ? $state : SellerOrderStatus::from((string) $state);

        return $table
            ->query(
                SellerOrder::query()
                    ->where('seller_id', auth()->id())
                    ->where('status', '!=', SellerOrderStatus::PENDING->value)
                    ->with(['order', 'items'])
                    ->latest()
                    ->limit(5)
            )
            ->heading('Recent sales')
            ->headerActions([
                Action::make('all')->label('All sales')->color('gray')->url(route('selling.orders')),
            ])
            ->paginated(false)
            ->columns([
                TextColumn::make('order.number')
                    ->label('Order')
                    ->weight('medium')
                    ->description(fn (SellerOrder $record): string => ($record->order->paid_at ?? $record->created_at)->format('M j, Y')),
                TextColumn::make('items_summary')
                    ->label('Items')
                    ->state(fn (SellerOrder $record): string => (string) $record->items->first()?->title)
                    ->description(fn (SellerOrder $record): ?string => $record->items->count() > 1 ? '+ '.($record->items->count() - 1).' more' : null)
                    ->limit(40),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $status($state)->label())
                    ->color(fn ($state): string => $status($state)->color()),
                TextColumn::make('total')
                    ->label('Total')
                    ->state(fn (SellerOrder $record): string => money($record->grossCents(), $record->order->currency))
                    ->weight('medium')
                    ->alignEnd(),
            ])
            ->emptyStateIcon('phosphor-receipt')
            ->emptyStateHeading('No sales yet')
            ->emptyStateDescription('Create a listing to get started.')
            ->emptyStateActions([
                Action::make('listings')->label('My listings')->url(route('dashboard.listings')),
            ]);
    }

    public function with(): array
    {
        $user = auth()->user();
        $profile = $user->sellerProfile?->refresh();

        $paid = SellerOrder::query()->where('seller_id', $user->getKey())->whereIn('status', [
            SellerOrderStatus::PAID->value, SellerOrderStatus::AWAITING_SHIPMENT->value, SellerOrderStatus::PARTIALLY_SHIPPED->value, SellerOrderStatus::SHIPPED->value,
            SellerOrderStatus::DELIVERED->value, SellerOrderStatus::COMPLETED->value,
        ]);

        $totals = (clone $paid)->selectRaw('COUNT(*) as orders, COALESCE(SUM(subtotal_cents + shipping_cents), 0) as gross, COALESCE(SUM(platform_fee_cents), 0) as fees')->first();

        // Payouts that have not been made yet: held back by a problem report, scheduled for a date, or waiting for the shipment.
        $payouts = app(PayoutService::class);
        $upcoming = SellerOrder::query()
            ->where('seller_id', $user->getKey())
            ->whereNull('transferred_at')
            ->where('payout_cents', '>', 0)
            ->whereIn('status', [SellerOrderStatus::AWAITING_SHIPMENT->value, SellerOrderStatus::PARTIALLY_SHIPPED->value, SellerOrderStatus::SHIPPED->value, SellerOrderStatus::DELIVERED->value, SellerOrderStatus::COMPLETED->value])
            ->with(['order', 'items', 'seller', 'activeIssue'])
            ->latest('id')
            ->limit(25)
            ->get()
            ->map(fn (SellerOrder $sellerOrder) => ['sellerOrder' => $sellerOrder, 'outlook' => $payouts->outlook($sellerOrder)])
            ->reject(fn (array $row) => $row['outlook']['state'] === 'none')
            // held first, then by date, then the ones that wait for a shipment
            ->sortBy(fn (array $row) => match ($row['outlook']['state']) {
                'held' => '0',
                'scheduled' => '1'.$row['outlook']['at']->format('YmdHis'),
                default => '2',
            })
            ->values();

        // Where the Stripe card is: nothing / account without details / waiting for Stripe / ready.
        $stripe = match (true) {
            $user->isPlatformSeller() => 'platform',
            $profile === null || blank($profile->stripe_account_id) => 'not_started',
            ! $profile->details_submitted => 'incomplete',
            ! $profile->canReceivePayouts() => 'pending',
            default => 'active',
        };

        return [
            'profile' => $profile,
            'stripe' => $stripe,
            'upcoming' => $upcoming,
            'payoutNote' => $payouts->timingNote(),
            'stats' => [
                'orders' => (int) $totals->orders,
                'gross' => (int) $totals->gross,
                'fees' => (int) $totals->fees,
                'payouts' => (int) (clone $paid)->whereNotNull('transferred_at')->sum('payout_cents'),
                'pending' => (int) (clone $paid)->whereNull('transferred_at')->sum('payout_cents'),
                'held' => $upcoming->where('outlook.state', 'held')->count(),
                'toShip' => SellerOrder::query()->where('seller_id', $user->getKey())->where('status', SellerOrderStatus::AWAITING_SHIPMENT->value)->count(),
            ],
        ];
    }
}; ?>

<x-layouts.app>
    @volt('selling.dashboard')
    <div class="space-y-6">
        <dl class="grid grid-cols-2 gap-6 lg:grid-cols-4">
            @foreach ([
                ['Gross sales', money($stats['gross']), $stats['orders'].' '.\Illuminate\Support\Str::plural('order', $stats['orders']), 'gross'],
                ['Platform fees', money($stats['fees']), 'kept by '.config('app.name'), 'fees'],
                ['Paid out', money($stats['payouts']), 'sent to your Stripe account', 'payouts'],
                ['Pending payouts', money($stats['pending']), $stats['held'] > 0 ? $stats['held'].' on hold: open '.\Illuminate\Support\Str::plural('problem', $stats['held']) : ($stats['toShip'] > 0 ? $stats['toShip'].' to ship' : 'see when each is released'), 'pending'],
            ] as [$label, $value, $hint, $key])
                <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5" data-stat="{{ $key }}">
                    <dt class="text-sm font-medium text-gray-500">{{ $label }}</dt>
                    <dd class="mt-2 text-3xl font-semibold tracking-tight text-gray-950">{{ $value }}</dd>
                    <p class="mt-2 text-sm text-gray-500">{{ $hint }}</p>
                </div>
            @endforeach
        </dl>

        <div class="grid gap-6 xl:grid-cols-2">
            <x-filament::section heading="Payouts with Stripe" icon="phosphor-bank" class="self-start" data-stripe-state="{{ $stripe }}">
                <x-slot name="headerEnd">
                    @switch($stripe)
                        @case('active') <x-marketplace.badge color="success">Active</x-marketplace.badge> @break
                        @case('pending') <x-marketplace.badge color="warning">Pending verification</x-marketplace.badge> @break
                        @case('incomplete') <x-marketplace.badge color="warning">Setup not finished</x-marketplace.badge> @break
                        @case('platform') <x-marketplace.badge color="primary">Platform seller</x-marketplace.badge> @break
                        @default <x-marketplace.badge>Not started</x-marketplace.badge>
                    @endswitch
                </x-slot>

                <div class="space-y-4 text-sm text-gray-700">
                    @switch($stripe)
                        @case('platform')
                            <p class="text-gray-700">You sell as the platform: revenue stays with {{ config('app.name') }} and no payout account is needed.</p>
                            @break
                        @case('active')
                            <p class="text-gray-700">Payments for your paid items are transferred to your bank through Stripe. You can publish paid designs and listings. {{ $payoutNote }}</p>
                            <x-button tag="a" href="{{ route('selling.dashboard-link') }}" color="gray">Open Stripe dashboard</x-button>
                            @break
                        @case('pending')
                            <p class="text-gray-700">Stripe has your details and is verifying them. This usually takes minutes, sometimes a few days. We e-mail you as soon as you can sell paid items.</p>
                            <div class="flex flex-wrap gap-3">
                                <x-button wire:click="refreshStripe" wire:loading.attr="disabled" wire:target="refreshStripe" color="gray">Refresh status</x-button>
                                <x-button tag="a" href="{{ route('selling.dashboard-link') }}" color="gray">Open Stripe dashboard</x-button>
                            </div>
                            @break
                        @case('incomplete')
                            <p class="text-gray-700">You started the Stripe setup but did not finish it. Continue where you left off.</p>
                            <x-button tag="a" href="{{ route('selling.connect') }}">Continue Stripe setup</x-button>
                            @break
                        @default
                            <p class="text-gray-700">Connect a Stripe account to get paid. Free designs work without it, but you need it to <strong>publish paid designs</strong> and listings. Setup takes a few minutes.</p>
                            <x-button tag="a" href="{{ route('selling.connect') }}">Connect Stripe</x-button>
                    @endswitch
                </div>
            </x-filament::section>

            <x-filament::section heading="Shop profile" icon="phosphor-storefront">
                <form wire:submit="save" class="space-y-4">
                    {{ $this->form }}
                    <div class="flex flex-wrap items-center gap-3">
                        <x-button type="submit" wire:loading.attr="disabled" wire:target="save">Save shop profile</x-button>
                        @if ($profile)
                            <a href="{{ route('seller', ['slug' => $profile->slug]) }}" class="text-sm font-medium text-gray-700 underline hover:text-gray-950">View public shop page</a>
                        @endif
                    </div>
                </form>
            </x-filament::section>
        </div>

        <x-filament::section heading="Upcoming payouts" icon="phosphor-calendar-check" description="When the money for your sales reaches your Stripe account." data-upcoming-payouts>
            <p class="text-sm text-gray-600">{{ $payoutNote }}</p>

            @if ($upcoming->isEmpty())
                <p class="mt-4 text-sm text-gray-500">Nothing is waiting to be paid out.</p>
            @else
                <ul class="mt-4 divide-y divide-gray-100">
                    @foreach ($upcoming as $row)
                        @php
                            $so = $row['sellerOrder'];
                            $outlook = $row['outlook'];
                        @endphp
                        <li class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm" data-payout-row="{{ $so->getKey() }}" data-payout-state="{{ $outlook['state'] }}">
                            <div class="min-w-0">
                                <p class="font-medium text-gray-950">{{ $so->order->number }} <span class="font-normal text-gray-500">&middot; {{ $so->items->first()?->title }}{{ $so->items->count() > 1 ? ' + '.($so->items->count() - 1).' more' : '' }}</span></p>
                                <p class="text-gray-500">
                                    @switch($outlook['state'])
                                        @case('held')
                                            On hold until the problem reported by the buyer is resolved.
                                            @if ($so->activeIssue)
                                                <a href="{{ $so->activeIssue->url() }}" class="font-medium text-brand-700 underline hover:text-brand-900">Open the problem</a>
                                            @endif
                                            @break
                                        @case('scheduled')
                                            @if ($outlook['at']->isPast())
                                                Due now: it is released within the hour.
                                            @else
                                                Released on {{ $outlook['at']->format('M j, Y') }} unless the buyer reports a problem.
                                            @endif
                                            @break
                                        @default Released after you mark the parcel as shipped.
                                    @endswitch
                                </p>
                            </div>
                            <div class="flex flex-shrink-0 items-center gap-3">
                                @if ($outlook['state'] === 'held')
                                    <x-marketplace.badge color="danger">On hold</x-marketplace.badge>
                                @elseif ($outlook['state'] === 'scheduled')
                                    <x-marketplace.badge color="info">{{ $outlook['at']->isPast() ? 'Due now' : $outlook['at']->format('M j') }}</x-marketplace.badge>
                                @else
                                    <x-marketplace.badge color="warning">After shipping</x-marketplace.badge>
                                @endif
                                <span class="font-semibold text-gray-950">{{ money($so->payout_cents, $so->order->currency) }}</span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>

        {{ $this->table }}
    </div>
    @endvolt
</x-layouts.app>
