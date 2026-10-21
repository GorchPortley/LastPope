<?php

use Livewire\WithPagination;
use function Laravel\Folio\{name};
use Illuminate\Support\Facades\Gate;
use Livewire\Volt\Component;
use App\Models\Driver;

name('driver');

new class extends Component {

    use WithPagination;

    public Driver $driver;

    public function mount(string $id)
    {
        $this->driver = Driver::with([
            'snapshots',
            'designs.design'
        ])->findOrFail($id);

        // Unpublished drivers are only visible to their creator (and admins).
        abort_unless(Gate::allows('view', $this->driver), 404);
    }

    public function with(): array
    {
        // SDLabs' own new stock: the active store listing (the buy box), or - when it sold out - the
        // latest one, so the page can say "Out of stock at SDLabs" instead of staying silent.
        $store = $this->driver->storeListing();
        $latest = $store ?? $this->driver->latestStoreListing();
        $inStock = $store !== null && $store->quantity > 0;

        // What members sell of this driver in the marketplace (used units, see Listing::channelRuleViolations()).
        $used = $this->driver->listings()->marketplace()->forSale();

        return [
            'storeListing' => $inStock ? $store : null,
            'storeSoldOut' => ! $inStock && $latest !== null,
            'usedCount' => (clone $used)->count(),
            'usedFrom' => (clone $used)->min('price_cents'),
        ];
    }
} ?>

@push('head')
    <style>.tiptap-content table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1.25rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .tiptap-content table th, .tiptap-content table td {
            border: 1px solid #e0e0e0;
            padding: 0.75rem;
            text-align: left;
        }

        .tiptap-content table th {
            background-color: #f5f5f5;
            font-weight: bold;
        }

        .tiptap-content table tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        .tiptap-content table tr:hover {
            background-color: #f0f0f0;
        }

        .tiptap-content table th p, .tiptap-content table td p {
            margin: 0;
            padding: 0;
        }

        .tiptap-content .filament-tiptap-grid-builder {
            display: grid;
            gap: 1rem;
            width: 100%;
        }

        .tiptap-content .filament-tiptap-grid-builder[data-cols="3"] {
            grid-template-columns: repeat(3, 1fr);
        }

        .tiptap-content .filament-tiptap-grid-builder[data-cols="2"] {
            grid-template-columns: repeat(2, 1fr);
        }

        .tiptap-content .filament-tiptap-grid-builder[data-cols="4"] {
            grid-template-columns: repeat(4, 1fr);
        }

        .tiptap-content .filament-tiptap-grid-builder__column {
            border-radius: 0.5rem;
            padding: 1rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .tiptap-content .filament-tiptap-grid-builder__column h2 {
            margin-bottom: 0.75rem;
        }

        .tiptap-content .filament-tiptap-grid-builder__column p {
        }

        @media (max-width: 640px) {
            .tiptap-content .filament-tiptap-grid-builder {
                grid-template-columns: 1fr !important;
            }

            .tiptap-content table {
                font-size: 0.875rem;
            }

            .tiptap-content table th, .tiptap-content table td {
                padding: 0.5rem;
            }
        }
    </style>
    @endpush
<x-layouts.marketing>
    @volt('driver')
    <div class="bg-zinc-200 min-h-dvh dark:text-white">
        <main class="mx-auto bg-white max-w-7xl px-4 sm:px-6 lg:px-8 min-h-full py-4">
            <!-- Breadcrumb -->
            <nav class="mb-6" aria-label="Breadcrumb">
                <ol class="flex items-center space-x-2">
                    <li><a href="/drivers" class="text-gray-500 hover:text-gray-700">Drivers</a></li>
                    <li class="text-gray-400">/</li>
                    <li class="text-gray-900">{{ $driver->brand }} - {{ $driver->model }}</li>
                </ol>
            </nav>

            <hr class="mb-8 border-t-2 border-gray-300">

            <!-- Content Container -->
            <div class="space-y-8">
                <!-- Top Section: Image and Details -->
                <div class="lg:grid lg:grid-cols-2 lg:gap-8">
                    <!-- Left: Image gallery -->
                    <x-marketplace.gallery
                        class="relative mb-6 lg:mb-0"
                        :images="array_map(fn ($image) => Storage::url($image), (array) $driver->card_image)"
                        :alt="$driver->fullName()"
                    />

                    <!-- Right: Driver Info -->
                    <div class="space-y-6">
                        <!-- Title and Tag -->
                        <div>
                            <h1 class="text-3xl font-bold text-gray-900">{{ $driver->brand }}
                                - {{ $driver->model }}</h1>
                            @if($driver->tag)
                                <p class="mt-2 text-lg text-gray-600 italic">{{ $driver->tag }}</p>
                            @endif
                        </div>

                        <!-- Where to buy -->
                        @php($buyLink = filled($driver->link) && \Illuminate\Support\Str::startsWith($driver->link, ['http://', 'https://']) ? $driver->link : null)
                        <div class="space-y-4">
                            @if ($storeListing)
                                <div data-driver-store>
                                    <livewire:buy-box :purchasable="$storeListing" label="Sold by SDLabs" :key="'store-'.$storeListing->id" />
                                    @if ($driver->msrp_cents)
                                        <p class="mt-2 text-sm text-gray-500">Manufacturer list price: {{ money($driver->msrp_cents) }}</p>
                                    @endif
                                </div>
                            @else
                                @if ($storeSoldOut)
                                    <x-marketplace.box class="w-full" data-driver-sold-out>
                                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">SDLabs store</p>
                                        <p class="mt-1 text-2xl font-bold text-gray-900">Out of stock at SDLabs</p>
                                        <p class="mt-1 text-sm text-gray-600">We are waiting for the next delivery of this driver.@if ($usedCount > 0) Members have used ones for sale, see below.@endif</p>
                                    </x-marketplace.box>
                                @endif

                                @if ($driver->msrp_cents || $buyLink)
                                    <x-marketplace.box class="w-full" data-driver-msrp>
                                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">New from the manufacturer</p>
                                        @if ($driver->msrp_cents)
                                            <p class="mt-1 text-3xl font-bold text-gray-900">{{ money($driver->msrp_cents) }} <span class="text-sm font-normal text-gray-500">MSRP</span></p>
                                        @endif
                                        @if ($buyLink)
                                            <x-button tag="a" href="{{ $buyLink }}" target="_blank" rel="noopener nofollow" color="gray" class="mt-3 w-full">Buy from the manufacturer &rarr;</x-button>
                                        @endif
                                    </x-marketplace.box>
                                @endif
                            @endif

                            @if ($usedCount > 0)
                                <a href="{{ route('marketplace', ['type' => 'used_gear', 'driver' => $driver->id]) }}" class="flex items-center justify-between gap-3 rounded-lg border-2 border-gray-100 bg-white p-4 shadow-sm hover:border-brand-950" data-driver-listings>
                                    <span>
                                        <span class="block font-semibold text-gray-900">Used ones on the marketplace ({{ $usedCount }})</span>
                                        <span class="block text-sm text-gray-500">From {{ money((int) $usedFrom) }}</span>
                                    </span>
                                    <x-phosphor-storefront class="h-8 w-8 flex-shrink-0 text-brand-950" />
                                </a>
                            @endif
                        </div>

                        <!-- Specifications -->
                        <div class="border-t border-gray-200 pt-6">
                            <h2 class="text-xl font-semibold text-gray-900 mb-4">Specifications</h2>
                            <dl class="grid grid-cols-2 gap-x-6 gap-y-4">
                                <div>
                                    <dt class="text-sm text-gray-500">Power Handling</dt>
                                    <dd class="mt-1 text-lg font-medium text-gray-900">{{ $driver->power }}W</dd>
                                </div>
                                <div>
                                    <dt class="text-sm text-gray-500">Impedance</dt>
                                    <dd class="mt-1 text-lg font-medium text-gray-900">{{ $driver->impedance }}Ω</dd>
                                </div>
                                <div>
                                    <dt class="text-sm text-gray-500">Size</dt>
                                    <dd class="mt-1 text-lg font-medium text-gray-900">{{ $driver->size }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm text-gray-500">Category</dt>
                                    <dd class="mt-1 text-lg font-medium text-gray-900">{{ $driver->category }}</dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                </div>

                <!-- Designs Using This Driver -->
                @if($driver->designs->count() > 0)
                    <div x-data="{ isOpen: true }" class="border-t border-gray-200 pt-8">
                        <button @click="isOpen = !isOpen"
                                class="flex items-center justify-between w-full text-xl font-semibold text-gray-900 pb-4 border-b-2 border-zinc-400">
                            <span>Designs Using This Driver</span>
                            <svg class="w-6 h-6 transition-transform" :class="{ 'rotate-90': isOpen }" fill="none"
                                 stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>

                        <div x-show="isOpen" class="divide-y divide-gray-200">
                            @foreach($driver->designs as $design)
                                <a href="/designs/design/{{$design->id}}">
                                    <div class="py-4">
                                        <div class="flex justify-between items-start">
                                            <div class="flex-1">
                                                <h4 class="text-lg font-medium text-gray-900">
                                                    <a href="/designs/design/{{$design->design->id}}"
                                                       class="hover:text-link-600">
                                                        {{ $design->design->name }}
                                                    </a>
                                                </h4>
                                                <div class="mt-1 flex items-center space-x-4 text-sm text-gray-500">
                                                    <span>{{ $design->position }}</span>
                                                    <span>•</span>
                                                    <span>{{ $design->design->category }}</span>
                                                    <span>•</span>
                                                    <span>{{ $design->low_frequency }} Hz - {{ $design->high_frequency }} Hz</span>
                                                    <span>•</span>
                                                    <span>Qty: {{ $design->quantity }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif

                <!-- Factory Specs -->
                @if($driver->factory_specs)
                    <div x-data="{ isOpen: true }">
                    <button @click="isOpen = !isOpen"
                            class="flex items-center justify-between w-full text-xl font-semibold text-gray-900 pb-4 border-b-2 border-zinc-400">
                        <span>Factory Data</span>
                        <svg class="w-6 h-6 transition-transform" :class="{ 'rotate-90': isOpen }" fill="none"
                             stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>
                    <div x-show="isOpen" class="border-t border-gray-200 pt-8">
                        <div>
                                                    <livewire:DriverFrequencyResponseViewer :driver="$driver"></livewire:DriverFrequencyResponseViewer>
                            <span class="text-xl justify-center font-semibold text-gray-900">T/S Parameters</span>
                        <dl class="grid grid-cols-2 gap-4 mx-20">
                            @foreach($driver->factory_specs as $key => $value)
                                <div class="flex justify-between col-span-2 py-2 border-b">
                                    <dt class="font-medium text-gray-600">{{ $key }}</dt>
                                    <dd class="text-gray-900">{{ $value }}</dd>
                                </div>
                            @endforeach
                        </dl>
                        </div>
                    </div>
                    </div>
                @endif
                <div class="w-full">
                    <span class="text-xl justify-center font-semibold text-gray-900">Download Files</span>
                    {{-- Driver measurement data is free and public: no login needed. --}}
                    @foreach($driver->snapshots as $snapshot)
                        <br>
                        <div>
                            <a href="{{ route('driver-snapshots.download', $snapshot->id) }}" class="button">
                                <span class="text-gray-900">{{$snapshot->snapshot_name}}</span>
                                <i class="ph ph-download-simple"></i>
                            </a>
                        </div>
                    @endforeach
                </div>

                <!-- Description Section -->
                <div class="border-t border-gray-200 pt-8">
                    <h2 class="text-xl font-semibold text-gray-900 mb-4">Description</h2>
                    <div class="w-full prose max-w-none">
                        <div class="tiptap-content w-full">
                            {!! tiptap_converter()->asHtml($driver->description) !!}
                        </div>
                    </div>
                </div>

                <!-- Discussion Section -->
                <div class="border-t border-gray-200 pt-8">
                <h2 class="text-xl font-semibold text-gray-900 mb-4">Discussion
                </h2>
                 <div class="w-full h-auto min-h-[200px]"  id="tabpanelDiscussion" role="tabpanel" aria-label="discussion">
                @if($driver->forum_slug && app(\App\Services\Forum\ForumClient::class)->enabled())
                <iframe src="{{ app(\App\Services\Forum\ForumClient::class)->embedUrl($driver->forum_slug) }}" style="border: 0; width:100%; height: 800px; overflow: auto;" ></iframe>
                @else
                <p class="py-8 text-center text-gray-500">No discussion data available</p>
                @endif
                </div>
                </div>
            </div>
        </main>
    </div>
    @endvolt
</x-layouts.marketing>