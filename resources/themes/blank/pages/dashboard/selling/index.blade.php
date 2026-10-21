<?php

use App\Enums\SellerOrderStatus;
use App\Exceptions\Marketplace\ConnectException;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Services\Marketplace\ConnectOnboardingService;
use App\Support\Countries;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Livewire\Volt\Component;
use function Laravel\Folio\{middleware, name};

middleware('auth');
name('selling.dashboard');

new class extends Component implements HasForms {
    use InteractsWithForms;

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

    public function with(): array
    {
        $user = auth()->user();
        $profile = $user->sellerProfile?->refresh();

        $paid = SellerOrder::query()->where('seller_id', $user->getKey())->whereIn('status', [
            SellerOrderStatus::PAID->value, SellerOrderStatus::AWAITING_SHIPMENT->value, SellerOrderStatus::SHIPPED->value,
            SellerOrderStatus::DELIVERED->value, SellerOrderStatus::COMPLETED->value,
        ]);

        $totals = (clone $paid)->selectRaw('COUNT(*) as orders, COALESCE(SUM(subtotal_cents + shipping_cents), 0) as gross, COALESCE(SUM(platform_fee_cents), 0) as fees')->first();

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
            'stats' => [
                'orders' => (int) $totals->orders,
                'gross' => (int) $totals->gross,
                'fees' => (int) $totals->fees,
                'payouts' => (int) (clone $paid)->whereNotNull('transferred_at')->sum('payout_cents'),
                'pending' => (int) (clone $paid)->whereNull('transferred_at')->sum('payout_cents'),
                'toShip' => SellerOrder::query()->where('seller_id', $user->getKey())->where('status', SellerOrderStatus::AWAITING_SHIPMENT->value)->count(),
            ],
            'recent' => SellerOrder::query()->where('seller_id', $user->getKey())
                ->where('status', '!=', SellerOrderStatus::PENDING->value)
                ->with(['order', 'items'])->latest()->limit(5)->get(),
        ];
    }
}; ?>

<x-layouts.app>
    @volt('selling.dashboard')
    <x-app.container>
        <x-app.heading
            title="Selling"
            description="Your shop, payouts and sales."
            :border="true" />

        <dl class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
            @foreach ([
                ['Gross sales', money($stats['gross']), $stats['orders'].' '.\Illuminate\Support\Str::plural('order', $stats['orders']), 'gross'],
                ['Platform fees', money($stats['fees']), 'kept by '.config('app.name'), 'fees'],
                ['Paid out', money($stats['payouts']), 'sent to your Stripe account', 'payouts'],
                ['Pending payouts', money($stats['pending']), $stats['toShip'] > 0 ? $stats['toShip'].' to ship' : 'released when shipped', 'pending'],
            ] as [$label, $value, $hint, $key])
                <div class="border border-gray-200 bg-white p-4" data-stat="{{ $key }}">
                    <dt class="text-sm text-gray-500">{{ $label }}</dt>
                    <dd class="mt-1 text-2xl font-bold text-gray-900">{{ $value }}</dd>
                    <p class="mt-1 text-xs text-gray-500">{{ $hint }}</p>
                </div>
            @endforeach
        </dl>

        <div class="mt-8 grid gap-6 xl:grid-cols-2">
            <section class="self-start border border-gray-200 bg-white p-5" aria-labelledby="stripe-heading" data-stripe-state="{{ $stripe }}">
                <div class="flex items-center justify-between gap-3">
                    <h2 id="stripe-heading" class="flex items-center gap-2 text-lg font-semibold text-gray-900"><x-phosphor-bank class="h-5 w-5" /> Payouts with Stripe</h2>
                    @switch($stripe)
                        @case('active') <x-marketplace.badge color="success">Active</x-marketplace.badge> @break
                        @case('pending') <x-marketplace.badge color="warning">Pending verification</x-marketplace.badge> @break
                        @case('incomplete') <x-marketplace.badge color="warning">Setup not finished</x-marketplace.badge> @break
                        @case('platform') <x-marketplace.badge color="primary">Platform seller</x-marketplace.badge> @break
                        @default <x-marketplace.badge>Not started</x-marketplace.badge>
                    @endswitch
                </div>

                <div class="mt-3 space-y-4 text-sm text-gray-700">
                    @switch($stripe)
                        @case('platform')
                            <p>You sell as the platform: revenue stays with {{ config('app.name') }} and no payout account is needed.</p>
                            @break
                        @case('active')
                            <p>Payments for your paid items are transferred to your bank through Stripe. You can publish paid designs, data packs and listings.</p>
                            <x-button tag="a" href="{{ route('selling.dashboard-link') }}" color="gray">Open Stripe dashboard</x-button>
                            @break
                        @case('pending')
                            <p>Stripe has your details and is verifying them. This usually takes minutes, sometimes a few days. We e-mail you as soon as you can sell paid items.</p>
                            <div class="flex flex-wrap gap-3">
                                <x-button wire:click="refreshStripe" wire:loading.attr="disabled" wire:target="refreshStripe" color="gray">Refresh status</x-button>
                                <x-button tag="a" href="{{ route('selling.dashboard-link') }}" color="gray">Open Stripe dashboard</x-button>
                            </div>
                            @break
                        @case('incomplete')
                            <p>You started the Stripe setup but did not finish it. Continue where you left off.</p>
                            <x-button tag="a" href="{{ route('selling.connect') }}">Continue Stripe setup</x-button>
                            @break
                        @default
                            <p>Connect a Stripe account to get paid. Free designs and data packs work without it, but you need it to <strong>publish paid items</strong> and listings. Setup takes a few minutes.</p>
                            <x-button tag="a" href="{{ route('selling.connect') }}">Connect Stripe</x-button>
                    @endswitch
                </div>
            </section>

            <section class="border border-gray-200 bg-white p-5" aria-labelledby="shop-heading">
                <h2 id="shop-heading" class="flex items-center gap-2 text-lg font-semibold text-gray-900"><x-phosphor-storefront class="h-5 w-5" /> Shop profile</h2>
                <form wire:submit="save" class="mt-4 space-y-4">
                    {{ $this->form }}
                    <div class="flex flex-wrap items-center gap-3">
                        <x-button type="submit" wire:loading.attr="disabled" wire:target="save">Save shop profile</x-button>
                        @if ($profile)
                            <a href="{{ route('seller', ['slug' => $profile->slug]) }}" class="text-sm font-medium text-purple-700 underline hover:text-purple-900">View public shop page</a>
                        @endif
                    </div>
                </form>
            </section>
        </div>

        <section class="mt-8" aria-labelledby="recent-heading">
            <div class="mb-3 flex items-center justify-between">
                <h2 id="recent-heading" class="text-lg font-semibold text-gray-900">Recent sales</h2>
                <a href="{{ route('selling.orders') }}" class="text-sm font-medium text-purple-700 underline hover:text-purple-900">All sales</a>
            </div>
            @if ($recent->isEmpty())
                <p class="border border-dashed border-gray-300 bg-white p-8 text-center text-sm text-gray-500">No sales yet. <a class="underline" href="{{ route('dashboard.listings') }}">Create a listing</a> to get started.</p>
            @else
                <div class="overflow-x-auto border border-gray-200 bg-white">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-4 py-2 font-medium">Order</th>
                                <th class="px-4 py-2 font-medium">Items</th>
                                <th class="px-4 py-2 font-medium">Status</th>
                                <th class="px-4 py-2 text-right font-medium">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($recent as $sale)
                                <tr>
                                    <td class="whitespace-nowrap px-4 py-3">
                                        <span class="font-medium text-gray-900">{{ $sale->order->number }}</span>
                                        <span class="block text-xs text-gray-500">{{ ($sale->order->paid_at ?? $sale->created_at)->format('M j, Y') }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-gray-700">{{ $sale->items->first()?->title }}@if ($sale->items->count() > 1) <span class="text-gray-500">+{{ $sale->items->count() - 1 }} more</span>@endif</td>
                                    <td class="px-4 py-3"><x-marketplace.badge :color="$sale->status->color()">{{ $sale->status->label() }}</x-marketplace.badge></td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right font-medium text-gray-900">{{ money($sale->grossCents(), $sale->order->currency) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </x-app.container>
    @endvolt
</x-layouts.app>
