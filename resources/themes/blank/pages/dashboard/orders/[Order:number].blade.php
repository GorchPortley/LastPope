<?php

use App\Enums\IssueReason;
use App\Exceptions\Marketplace\InvalidOrderStateException;
use App\Models\Order;
use App\Services\Marketplace\IssueService;
use App\Support\Countries;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use function Laravel\Folio\{middleware, name};

middleware('auth');
name('dashboard.orders.show');

new class extends Component implements HasForms, HasActions {
    use InteractsWithForms, InteractsWithActions;

    public Order $order;

    public function mount(Order $order): void
    {
        // OrderPolicy::view: the buyer, a seller with items in it, or an admin.
        Gate::authorize('view', $order);

        $this->order = $order->load(['sellerOrders.seller.sellerProfile', 'sellerOrders.items.entitlement', 'sellerOrders.issues', 'items']);
    }

    /** A problem panel on this page changed something (a refund, an accepted offer): show the new state. */
    #[On('issue-changed')]
    public function reload(): void
    {
        $this->order->refresh()->load(['sellerOrders.seller.sellerProfile', 'sellerOrders.items.entitlement', 'sellerOrders.issues', 'items']);
    }

    public function with(): array
    {
        $user = auth()->user();
        $isBuyer = (int) $this->order->user_id === (int) $user->getKey();
        $isAdmin = $user->isPlatformSeller();
        $service = app(IssueService::class);

        // Sellers only see their own part of somebody else's order.
        $sellerOrders = $this->order->sellerOrders->filter(
            fn ($sellerOrder) => $isBuyer || $isAdmin || (int) $sellerOrder->seller_id === (int) $user->getKey()
        );

        return [
            'isBuyer' => $isBuyer,
            'sellerOrders' => $sellerOrders,
            'showTotals' => $isBuyer || $isAdmin,
            // Per seller order: its latest problem report, whether the buyer can report one, and until when.
            'problems' => $sellerOrders->mapWithKeys(fn ($sellerOrder) => [$sellerOrder->getKey() => [
                'issue' => $sellerOrder->issues->first(),
                'canReport' => $isBuyer && $service->canReport($sellerOrder),
                'blocker' => $isBuyer ? $service->reportBlocker($sellerOrder) : null,
                'windowEnds' => $service->windowEndsAt($sellerOrder),
            ]]),
        ];
    }

    /** "Report a problem" with one seller's part of the order (arguments: sellerOrder id). */
    public function reportProblemAction(): Action
    {
        return Action::make('reportProblem')
            ->label('Report a problem')
            ->icon('heroicon-m-exclamation-triangle')
            ->color('danger')
            ->outlined()
            ->size('sm')
            ->modalHeading('Report a problem')
            ->modalDescription('Tell us what is wrong. The seller is notified and can answer, refund or ask for the goods back. Their payout waits until it is settled.')
            ->modalWidth('2xl')
            ->modalSubmitActionLabel('Report the problem')
            ->form(function (array $arguments): array {
                $sellerOrder = $this->order->sellerOrders->firstWhere('id', (int) ($arguments['sellerOrder'] ?? 0));
                $options = $sellerOrder ? IssueReason::optionsFor($sellerOrder) : IssueReason::options();

                return [
                    Select::make('reason')
                        ->label('What is wrong?')
                        ->options($options)
                        ->required()
                        ->native(false),
                    Textarea::make('description')
                        ->label('Describe the problem')
                        ->placeholder('What happened, and what would you like the seller to do?')
                        ->required()
                        ->minLength(10)
                        ->maxLength(5000)
                        ->rows(5),
                    FileUpload::make('photos')
                        ->label('Photos (optional)')
                        ->helperText('Up to '.(int) config('marketplace.issue_max_photos', 5).' pictures, '.round((int) config('marketplace.issue_photo_max_kb', 8192) / 1024).' MB each. Only you, the seller and our team can see them.')
                        ->multiple()
                        ->image()
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                        ->maxFiles((int) config('marketplace.issue_max_photos', 5))
                        ->maxSize((int) config('marketplace.issue_photo_max_kb', 8192))
                        ->storeFiles(false),
                ];
            })
            ->action(function (array $data, array $arguments): void {
                $sellerOrder = $this->order->sellerOrders->firstWhere('id', (int) ($arguments['sellerOrder'] ?? 0));
                abort_if($sellerOrder === null, 404);

                try {
                    $issue = app(IssueService::class)->open(
                        auth()->user(),
                        $sellerOrder,
                        IssueReason::from($data['reason']),
                        (string) $data['description'],
                        array_values(array_filter($data['photos'] ?? [], fn ($file) => $file instanceof UploadedFile)),
                    );
                } catch (InvalidOrderStateException $e) {
                    Notification::make()->danger()->title('The problem could not be reported')->body($e->getMessage())->send();

                    return;
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('Please check your input')->body(collect($e->errors())->flatten()->first())->send();

                    return;
                }

                Notification::make()->success()->title('Problem reported')->body('We told the seller. You can follow it right here.')->send();
                $this->reload();
            });
    }
}; ?>

<x-layouts.app>
    @volt('dashboard.orders.show')
    <div class="space-y-6">
        <a href="{{ route('dashboard.orders') }}" class="inline-flex items-center gap-1 text-sm font-medium text-gray-600 hover:text-gray-900">
            <x-phosphor-arrow-left class="h-4 w-4" /> Back to my orders
        </a>

        <x-filament::section
            :heading="'Order '.$order->number"
            :description="'Placed '.($order->paid_at ?? $order->created_at)->format('F j, Y').($isBuyer ? '' : ' by a buyer')">
            <x-slot name="headerEnd">
                <x-marketplace.badge :color="$order->status->color()">{{ $order->status->label() }}</x-marketplace.badge>
            </x-slot>

            @if ($order->status === \App\Enums\OrderStatus::PENDING)
                <p class="text-sm text-gray-600">Waiting for the payment confirmation from Stripe.</p>
            @else
                <p class="text-sm text-gray-600">{{ $order->items->count() }} {{ \Illuminate\Support\Str::plural('item', $order->items->count()) }} from {{ $sellerOrders->count() }} {{ \Illuminate\Support\Str::plural('seller', $sellerOrders->count()) }}.</p>
            @endif
        </x-filament::section>

        <div class="gap-6 lg:grid lg:grid-cols-[minmax(0,1fr)_20rem]">
            <div class="space-y-6">
                @forelse ($sellerOrders as $sellerOrder)
                    @php($problem = $problems[$sellerOrder->getKey()])
                    <x-marketplace.seller-order :sellerOrder="$sellerOrder" :order="$order" :problem="$problem" wire:key="so-{{ $sellerOrder->id }}">
                        @if ($problem['canReport'])
                            <x-slot name="reportButton">{{ ($this->reportProblemAction)(['sellerOrder' => $sellerOrder->getKey()]) }}</x-slot>
                        @endif
                    </x-marketplace.seller-order>
                @empty
                    <p class="text-gray-500">There is nothing to show for this order.</p>
                @endforelse
            </div>

            <aside class="mt-6 space-y-6 lg:mt-0">
                @if ($showTotals)
                    <x-filament::section heading="Payment">
                        <dl class="space-y-2 text-sm">
                            <div class="flex justify-between text-gray-600"><dt>Items</dt><dd>{{ money($order->subtotal_cents, $order->currency) }}</dd></div>
                            <div class="flex justify-between text-gray-600"><dt>Shipping</dt><dd>{{ money($order->shipping_cents, $order->currency) }}</dd></div>
                            @if ($order->tax_cents > 0)
                                <div class="flex justify-between text-gray-600"><dt>Tax</dt><dd>{{ money($order->tax_cents, $order->currency) }}</dd></div>
                            @endif
                            <div class="flex justify-between border-t border-gray-200 pt-2 text-base font-bold text-gray-950"><dt>Total</dt><dd>{{ money($order->total_cents, $order->currency) }}</dd></div>
                        </dl>
                        @if ($order->paid_at)
                            <p class="mt-3 text-xs text-gray-500">Paid {{ $order->paid_at->format('M j, Y H:i') }} via Stripe</p>
                        @endif
                    </x-filament::section>
                @endif

                @if (filled($order->shipping_address))
                    <x-filament::section heading="Ship to">
                        @php($address = $order->shipping_address)
                        <address class="text-sm not-italic text-gray-700">
                            {{ $address['name'] ?? '' }}<br>
                            {{ $address['line1'] ?? '' }}@if (filled($address['line2'] ?? null)), {{ $address['line2'] }}@endif<br>
                            {{ $address['postal_code'] ?? '' }} {{ $address['city'] ?? '' }}@if (filled($address['state'] ?? null)), {{ $address['state'] }}@endif<br>
                            {{ Countries::name($address['country'] ?? null) }}
                        </address>
                    </x-filament::section>
                @endif
            </aside>
        </div>

        <x-filament-actions::modals />
    </div>
    @endvolt
</x-layouts.app>
