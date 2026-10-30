<?php

use function Laravel\Folio\{name};
use App\Models\Design;
use App\Models\Driver;
use App\Services\Forum\ForumClient;
use App\Support\Analytics\Ga4;
use App\Support\DesignFileList;
use App\Support\DriverSpecs;
use Illuminate\Support\Facades\Gate;
use Livewire\Volt\Component;

name('driver');

new class extends Component {
    public Driver $driver;

    public function mount(string $id)
    {
        $this->driver = Driver::with('snapshots')->findOrFail($id);

        // Unpublished drivers are only visible to their creator (and admins).
        abort_unless(Gate::allows('view', $this->driver), 404);
    }

    public function with(): array
    {
        // SDLabs' own new stock: the active store listing (the buy box), or - when it sold out - the
        // latest one, so the page can say "Out of stock at SDLabs" instead of staying silent.
        $store = $this->driver->storeListing();
        $latest = $store ?? $this->driver->latestStoreListing();
        $inStock = $store !== null && $store->inStock();

        // What members sell of this driver in the marketplace (used units, see Listing::channelRuleViolations()).
        $used = $this->driver->listings()->marketplace()->forSale();

        // Published designs that use this driver (a design counts once, however many bands use it).
        $designs = Design::query()
            ->active()
            ->whereHas('components', fn ($components) => $components->where('driver_id', $this->driver->id));

        return [
            'storeListing' => $inStock ? $store : null,
            'storeSoldOut' => ! $inStock && $latest !== null,
            'usedCount' => (clone $used)->count(),
            'usedFrom' => (clone $used)->min('price_cents'),
            'usedListings' => (clone $used)->with(['seller.sellerProfile', 'driver', 'design'])->latest()->limit(4)->get(),
            'designCount' => (clone $designs)->count(),
            // Cards: the designer's roles and shop and the rating come from the eager loads, not one query per card.
            'designs' => (clone $designs)->with(['designer.roles', 'designer.sellerProfile'])
                ->withAvg('reviews', 'rating')->withCount(['reviews', 'components'])
                ->latest('id')->limit(12)->get(),
            'specs' => DriverSpecs::rows($this->driver->factory_specs),
            'fileGroups' => DesignFileList::driverGroups($this->driver),
            'snapshots' => $this->driver->snapshots->sortByDesc('id')->values(),
            // Driver data is free and public: the same rule as the download route.
            'canDownload' => Gate::allows('download', $this->driver),
        ];
    }
} ?>

<x-layouts.marketing>
    @volt('driver')
    @php
        $docNo = 'SDL-R-'.str_pad((string) $driver->id, 4, '0', STR_PAD_LEFT);
        $num = fn ($value) => $value === null || $value === '' ? null : rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
        $withUnit = fn ($value, string $unit) => filled($value) ? $value.(is_numeric($value) ? ' '.$unit : '') : '-';
        $buyLink = filled($driver->link) && \Illuminate\Support\Str::startsWith($driver->link, ['http://', 'https://']) ? $driver->link : null;
        $forum = app(ForumClient::class);
        $thread = $driver->forum_slug && $forum->enabled();
        $aboutHtml = trim(strip_tags((string) tiptap_converter()->asHtml((string) $driver->summary).tiptap_converter()->asHtml((string) $driver->description), '<img><table>'));
    @endphp
    <div class="min-h-dvh bg-paper pb-10">
        <x-analytics.events :events="[Ga4::payload('view_item', Ga4::ecommerce([Ga4::item($driver, 1, $storeListing?->purchasablePriceCents())]))]" />

        <div class="mx-auto w-full max-w-7xl pl-[max(1rem,env(safe-area-inset-left,0px))] pr-[max(1rem,env(safe-area-inset-right,0px))] sm:px-6 lg:px-8">
            <nav class="py-3.5 text-sm text-ink-3" aria-label="Breadcrumb">
                <ol class="flex flex-wrap items-center gap-x-2">
                    <li><a href="/" class="text-ink-2 hover:text-ink">Home</a></li>
                    <li aria-hidden="true">/</li>
                    <li><a href="/drivers" class="text-ink-2 hover:text-ink">Drivers</a></li>
                    @if ($driver->category)
                        <li aria-hidden="true">/</li>
                        <li><a href="{{ url('/drivers').'?'.http_build_query(['category' => $driver->category]) }}" class="text-ink-2 hover:text-ink">{{ $driver->category }}</a></li>
                    @endif
                    <li aria-hidden="true">/</li>
                    <li class="min-w-0 text-ink" aria-current="page">{{ $driver->fullName() }}</li>
                </ol>
            </nav>

            {{-- Photos on the left, the sheet (number, name, where to buy, specs) on the right --}}
            <div class="grid gap-6 pb-5 lg:grid-cols-12 lg:gap-[30px]">
                <x-marketplace.gallery
                    class="lg:col-span-7"
                    :images="(array) $driver->card_image"
                    :paths="true"
                    ratio="square"
                    fit="contain"
                    :alt="$driver->fullName()"
                />

                <div class="sd-sheet grid content-start lg:col-span-5">
                    <div class="sd-num flex flex-wrap justify-between gap-x-2.5 gap-y-1 border-b border-rule px-3.5 py-2 text-[11px] text-ink-3">
                        <span data-driver-docno>{{ $docNo }}</span>
                        <span>{{ $driver->category ?: 'Driver' }}</span>
                    </div>

                    <div class="grid gap-3 px-4 pb-4 pt-4 sm:px-[18px] sm:pb-[18px]">
                        <h1 class="sd-title text-[clamp(34px,5vw,46px)] leading-[0.95]">
                            <span class="sd-label mb-1.5 block">{{ $driver->brand }}</span> {{ $driver->model }}
                        </h1>
                        @if ($driver->tag)
                            <p class="text-[17px] !text-ink-2">{{ $driver->tag }}</p>
                        @endif
                        @if ($driver->official)
                            <p class="flex items-center gap-3"><span class="sd-seal">Official</span></p>
                        @endif

                        {{-- Where to buy --}}
                        <div class="grid gap-3">
                            @if ($storeListing)
                                <div data-driver-store>
                                    <livewire:buy-box :purchasable="$storeListing" label="Sold by SDLabs" :key="'store-'.$storeListing->id" />
                                    @if ($driver->msrp_cents)
                                        <p class="mt-2 text-sm text-ink-2">Manufacturer list price: <span class="sd-num text-[13px]">{{ money($driver->msrp_cents) }}</span></p>
                                    @endif
                                </div>
                            @else
                                @if ($storeSoldOut)
                                    <div class="grid gap-1 border border-rule bg-paper p-3.5" data-driver-sold-out>
                                        <p class="sd-label">SDLabs store</p>
                                        <p class="font-condensed text-[28px] font-bold uppercase leading-none text-ink">Out of stock at SDLabs</p>
                                        <p class="text-sm text-ink-2">We are waiting for the next delivery of this driver.@if ($usedCount > 0) Members have used ones for sale, see below.@endif</p>
                                    </div>
                                @endif

                                @if ($driver->msrp_cents || $buyLink)
                                    <div class="grid gap-2.5 border border-rule bg-paper p-3.5" data-driver-msrp>
                                        <p class="sd-label">New from the manufacturer</p>
                                        @if ($driver->msrp_cents)
                                            <p class="font-condensed text-[34px] font-bold leading-none text-ink">{{ money($driver->msrp_cents) }} <span class="font-sans text-sm font-normal text-ink-2">MSRP</span></p>
                                        @endif
                                        @if ($buyLink)
                                            <a href="{{ $buyLink }}" target="_blank" rel="noopener nofollow" class="sd-btn w-full">Buy from the manufacturer &rarr;</a>
                                        @endif
                                    </div>
                                @endif
                            @endif

                            @if ($usedCount > 0)
                                <a href="{{ route('marketplace', ['type' => 'used_gear', 'driver' => $driver->id]) }}" class="flex items-center justify-between gap-3 border border-rule bg-white p-3.5 no-underline hover:border-ink" data-driver-listings>
                                    <span>
                                        <span class="block font-semibold text-ink">Used ones on the marketplace ({{ $usedCount }})</span>
                                        <span class="block text-sm text-ink-2">From {{ money((int) $usedFrom) }}</span>
                                    </span>
                                    <x-phosphor-storefront class="h-7 w-7 flex-shrink-0 text-brand-800" />
                                </a>
                            @endif
                        </div>

                        <table class="w-full border-collapse text-[15px]" data-driver-specs>
                            <tbody class="[&_td]:border-b [&_td]:border-dotted [&_td]:border-rule [&_td]:py-1.5 [&_td:last-child]:text-right [&_td:last-child]:font-mono [&_td:last-child]:text-[13px] [&_td:last-child]:tabular-nums [&_tr:last-child_td]:border-b-0">
                                <tr><td>Size</td><td>{{ $num($driver->size) !== null ? $num($driver->size).' in' : '-' }}</td></tr>
                                <tr><td>Impedance</td><td>{{ $withUnit($driver->impedance, 'Ω') }}</td></tr>
                                <tr><td>Power</td><td>{{ (float) $driver->power > 0 ? $num($driver->power).' W' : '-' }}</td></tr>
                                <tr><td>MSRP</td><td>{{ $driver->msrp_cents ? money($driver->msrp_cents) : '-' }}</td></tr>
                                <tr><td>Used in</td><td data-driver-design-count>{{ $designCount }} {{ \Illuminate\Support\Str::plural('design', $designCount) }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- Sticky row of links to the sections --}}
        <nav class="sticky top-[env(safe-area-inset-top,0px)] z-20 mb-5 border-b border-rule bg-paper" aria-label="On this page" data-driver-jump>
            <div class="sd-jump-row mx-auto max-w-7xl pl-[max(1rem,env(safe-area-inset-left,0px))] pr-[max(1rem,env(safe-area-inset-right,0px))] sm:px-6 lg:px-8">
                @foreach ([
                    ['specs', 'Factory specs'],
                    ['response', 'Response'],
                    ['about', 'About'],
                    ['designs', 'Used in designs'],
                    $usedCount > 0 ? ['marketplace', 'Marketplace'] : null,
                    ['downloads', 'Downloads'],
                    ['discussion', 'Discussion'],
                ] as $link)
                    @continue($link === null)
                    <a href="#sec-{{ $link[0] }}" class="flex-none border-b-2 border-transparent px-3 py-[11px] font-condensed text-sm font-semibold uppercase tracking-[0.05em] text-ink-2 no-underline hover:border-brand-800 hover:text-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent-500">{{ $link[1] }}</a>
                @endforeach
            </div>
        </nav>

        <div class="mx-auto w-full max-w-7xl pl-[max(1rem,env(safe-area-inset-left,0px))] pr-[max(1rem,env(safe-area-inset-right,0px))] sm:px-6 lg:px-8">
            {{-- Factory specs: the datasheet's Thiele/Small parameters --}}
            <x-design.section id="specs" title="Factory specs" hint="From the manufacturer's datasheet" data-driver-factory-specs>
                @if ($specs === [])
                    <p class="text-sm text-ink-3">No factory specs yet.</p>
                @else
                    <dl class="grid gap-x-10 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($specs as $row)
                            <div class="flex items-baseline justify-between gap-3 border-b border-dotted border-rule py-2" data-spec="{{ $row['key'] }}">
                                <dt class="min-w-0">
                                    <span class="font-condensed text-base font-bold uppercase tracking-[0.04em] text-ink">{{ $row['key'] }}</span>
                                    @if ($row['name'])
                                        <span class="block text-[13px] leading-tight text-ink-3">{{ $row['name'] }}</span>
                                    @endif
                                </dt>
                                <dd class="sd-num flex-none whitespace-nowrap text-right text-[15px] text-ink">{{ $row['value'] }}@if ($row['unit'] !== '') <span class="text-[13px] text-ink-3">{{ $row['unit'] }}</span>@endif</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </x-design.section>

            {{-- Measured response (the manufacturer's frequency files) --}}
            <livewire:driver-frequency-response-viewer :driver="$driver" />

            {{-- About: summary, then description --}}
            <x-design.section id="about" title="About this driver">
                @if ($aboutHtml !== '')
                    <div class="grid gap-7">
                        <x-design.rich :content="$driver->summary" :design="$driver" class="text-[19px]" data-driver-summary />
                        <x-design.rich :content="$driver->description" :design="$driver" data-driver-description />
                    </div>
                @else
                    <p class="text-sm text-ink-3">No description yet.</p>
                @endif
            </x-design.section>

            {{-- Designs that use this driver --}}
            <x-design.section id="designs" title="Used in designs" :hint="$designCount > $designs->count() ? 'Showing the newest '.$designs->count().' of '.$designCount : null" data-driver-designs>
                @if ($designs->isEmpty())
                    <p class="text-sm text-ink-3">No published design uses this driver yet.</p>
                @else
                    <x-marketing.design-card-container :designs="$designs" :padded="false" list-name="Designs using this driver" />
                @endif
            </x-design.section>

            {{-- Used units members sell --}}
            @if ($usedCount > 0)
                <x-design.section id="marketplace" title="On the marketplace" data-driver-marketplace>
                    <x-slot:header>
                        <a href="{{ route('marketplace', ['type' => 'used_gear', 'driver' => $driver->id]) }}" class="text-[13px] font-semibold text-brand-800 underline hover:text-brand-900">All {{ $usedCount }} used {{ \Illuminate\Support\Str::plural('listing', $usedCount) }} from {{ money((int) $usedFrom) }}</a>
                    </x-slot:header>
                    <x-marketing.listing-card-container :listings="$usedListings" cols="grid-cols-1 sm:grid-cols-2 lg:grid-cols-4" :padded="false" :list-name="'Used '.$driver->fullName()" />
                </x-design.section>
            @endif

            {{-- Downloads: the measurement snapshots (free and public) and what is in them --}}
            <x-design.section id="downloads" title="Downloads" data-driver-files>
                <x-slot:header>
                    <span class="text-[13px] text-ink-2">Free, no login needed</span>
                </x-slot:header>

                <div>
                    <h3 class="sd-label border-b-2 border-ink pb-1">Versions</h3>
                    @forelse ($snapshots as $snapshot)
                        <div class="sd-num flex flex-wrap items-center justify-between gap-x-3.5 gap-y-1.5 border-b border-dotted border-rule py-2 text-[13px]" data-driver-snapshot>
                            <span class="font-medium text-ink">{{ $snapshot->version }}</span>
                            <span class="text-ink-2">{{ $snapshot->created_at?->format('j M Y') }}</span>
                            <span class="text-ink-2">{{ $snapshot->file_size ? DesignFileList::size((int) $snapshot->file_size) : '-' }}</span>
                            @if ($canDownload)
                                <a href="{{ route('driver-snapshots.download', $snapshot->id) }}" class="sd-btn sd-btn-sm" data-ga4-click="{{ Ga4::download($driver, $snapshot->snapshot_name) }}">Download {{ $snapshot->snapshot_name }}</a>
                            @endif
                        </div>
                    @empty
                        <p class="mt-2 text-sm text-ink-3">No files published yet.</p>
                    @endforelse
                </div>

                @foreach ($fileGroups as $group)
                    <div class="mt-6" data-file-group>
                        <h3 class="sd-label border-b-2 border-ink pb-1">{{ $group['title'] }}</h3>
                        <div class="overflow-x-auto">
                            <table class="w-full table-fixed border-collapse text-[15px] max-sm:min-w-[440px]">
                                <colgroup><col><col class="w-[210px]"><col class="w-[80px]"></colgroup>
                                <tbody class="[&_td]:border-b [&_td]:border-hair [&_td]:py-2 [&_td]:pr-3 [&_td]:align-baseline">
                                    @foreach ($group['files'] as $file)
                                        <tr data-file>
                                            <td class="break-all font-medium text-ink">{{ $file['name'] }}</td>
                                            <td class="whitespace-nowrap text-ink-2">{{ $file['type'] }}</td>
                                            <td class="sd-num whitespace-nowrap text-right text-xs text-ink-2">{{ DesignFileList::size($file['size']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach
                @if ($fileGroups === [])
                    <p class="mt-4 text-sm text-ink-3">No measurement files are listed for this driver yet.</p>
                @endif
            </x-design.section>

            {{-- Discussion --}}
            <x-design.section id="discussion" title="Discussion" data-driver-discussion>
                <div id="tabpanelDiscussion" role="tabpanel" aria-label="discussion">
                    @if ($thread)
                        <iframe src="{{ $forum->embedUrl($driver->forum_slug) }}" title="Discussion of {{ $driver->fullName() }}" loading="lazy"
                                class="block h-[720px] w-full border border-hair max-sm:h-[600px]"></iframe>
                    @else
                        <p class="text-sm text-ink-3">No discussion data available</p>
                    @endif
                </div>
            </x-design.section>
        </div>
    </div>
    @endvolt
</x-layouts.marketing>
