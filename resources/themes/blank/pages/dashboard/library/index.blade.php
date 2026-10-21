<?php

use App\Models\Design;
use App\Models\Driver;
use App\Models\Entitlement;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use function Laravel\Folio\{middleware, name};

middleware('auth');
name('dashboard.library');

new class extends Component {
    use WithPagination;

    public function with(): array
    {
        return [
            'entitlements' => Entitlement::query()
                ->where('user_id', auth()->id())
                ->whereNull('revoked_at')
                ->whereHasMorph('entitleable', [Design::class, Driver::class])
                ->with(['entitleable.snapshots', 'orderItem.order'])
                ->latest('granted_at')
                ->latest('id')
                ->paginate(12),
        ];
    }
}; ?>

<x-layouts.app>
    @volt('dashboard.design-library')
    <x-app.container>
        <x-app.heading
            title="My library"
            description="Designs and driver data packs you bought, collected for free, or own. Download the newest snapshot, or any earlier version."
            :border="true" />

        @if ($entitlements->isEmpty())
            <div class="mt-8 border border-dashed border-gray-300 bg-white p-12 text-center">
                <x-phosphor-books-duotone class="mx-auto h-14 w-14 text-gray-400" />
                <p class="mt-3 text-lg font-medium text-gray-900">Your library is empty</p>
                <p class="mt-1 text-sm text-gray-600">Free designs and data packs you collect, and everything you buy, will show up here.</p>
                <div class="mt-5 flex flex-wrap justify-center gap-3">
                    <x-button tag="a" href="{{ route('designs') }}">Browse designs</x-button>
                    <x-button tag="a" href="{{ route('drivers') }}" color="gray">Browse drivers</x-button>
                </div>
            </div>
        @else
            <div class="mt-6 grid gap-5 xl:grid-cols-2">
                @foreach ($entitlements as $entitlement)
                    @php
                        $item = $entitlement->entitleable;
                        $isDesign = $item instanceof Design;
                        $order = $entitlement->orderItem?->order;
                        $snapshots = $item->snapshots->sortByDesc('id')->values();
                    @endphp
                    <article class="flex flex-col border border-gray-200 bg-white" wire:key="entitlement-{{ $entitlement->id }}">
                        <header class="flex items-start gap-4 border-b border-gray-200 p-4">
                            <x-marketplace.thumb :purchasable="$item" />
                            <div class="min-w-0 flex-1">
                                <h2 class="truncate text-lg font-semibold text-gray-900">
                                    <a href="{{ $isDesign ? route('design', ['id' => $item->id]) : route('driver', ['id' => $item->id]) }}" class="hover:underline">{{ $item->purchasableTitle() }}</a>
                                </h2>
                                <div class="mt-1 flex flex-wrap items-center gap-2 text-sm text-gray-500">
                                    <x-marketplace.badge :color="$isDesign ? 'primary' : 'info'">{{ $isDesign ? 'Design' : 'Driver data pack' }}</x-marketplace.badge>
                                    <x-marketplace.badge>{{ $entitlement->source->label() }}</x-marketplace.badge>
                                    <span>since {{ $entitlement->granted_at?->format('M j, Y') ?? $entitlement->created_at->format('M j, Y') }}</span>
                                </div>
                                <p class="mt-1 text-sm text-gray-500">
                                    By {{ $item->getSeller()?->name ?? 'unknown' }}
                                    @if ($order)
                                        &middot; <a href="{{ route('dashboard.orders.show', ['order' => $order->number]) }}" class="underline hover:text-gray-700">Order {{ $order->number }}</a>
                                    @endif
                                </p>
                            </div>
                        </header>

                        <div class="flex-1 p-4">
                            @if ($snapshots->isEmpty())
                                <p class="text-sm text-gray-500">No files have been published yet. They appear here as soon as the author creates a snapshot.</p>
                            @else
                                <ul class="divide-y divide-gray-100">
                                    @foreach ($snapshots as $snapshot)
                                        <li class="flex flex-wrap items-center justify-between gap-3 py-2 first:pt-0 last:pb-0">
                                            <div class="min-w-0">
                                                <p class="truncate text-sm font-medium text-gray-900">
                                                    {{ $snapshot->snapshot_name ?: 'Snapshot '.$snapshot->version }}
                                                    @if ($loop->first)<x-marketplace.badge color="success" class="ml-1">Latest</x-marketplace.badge>@endif
                                                </p>
                                                <p class="text-xs text-gray-500">
                                                    Version {{ $snapshot->version }}
                                                    @if ($snapshot->file_size) &middot; {{ \Illuminate\Support\Number::fileSize($snapshot->file_size) }}@endif
                                                    &middot; {{ $snapshot->created_at->format('M j, Y') }}
                                                </p>
                                            </div>
                                            <x-button tag="a" size="sm" :color="$loop->first ? 'primary' : 'gray'"
                                                      href="{{ $isDesign ? route('design-snapshots.download', $snapshot) : route('driver-snapshots.download', $snapshot) }}">
                                                <x-phosphor-download-simple class="mr-1 inline h-4 w-4" /> Download
                                            </x-button>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>

            <div class="mt-6">{{ $entitlements->links() }}</div>
        @endif
    </x-app.container>
    @endvolt
</x-layouts.app>
