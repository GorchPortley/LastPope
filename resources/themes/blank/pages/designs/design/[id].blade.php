<?php

use function Laravel\Folio\{name};
use App\Models\Design;
use App\Support\Analytics\Ga4;
use App\Support\DesignFileList;
use App\Support\FrequencyResponse;
use App\Support\ResponseChartData;
use Illuminate\Support\Facades\Gate;
use Livewire\Volt\Component;

name('design');


new class extends Component {
    public Design $design;

    public function mount(string $id)
    {
        $this->design = Design::with([
            'designer',
            'components.driver',
            'snapshots',
        ])->findOrFail($id);

        // Unpublished designs are only visible to their designer (and admins).
        abort_unless(Gate::allows('view', $this->design), 404);
    }

    public function with(): array
    {
        $design = $this->design;
        $user = auth()->user();
        $chart = ResponseChartData::from(FrequencyResponse::forFiles(array_values((array) ($design->frd_files ?? []))));
        $rating = $design->ratingSummary();

        return [
            // The measured response, its numbers and the files, worked out once for the spec table and the sections.
            'chart' => $chart,
            'rating' => $rating,
            'fileGroups' => DesignFileList::groups($design),
            'snapshots' => $design->snapshots->sortByDesc('id')->values(),
            // Same rule as the buy box and the download route: designer, admin, an entitlement, or a free design.
            'hasAccess' => $user !== null && $user->hasAccessTo($design, false),
            // A paid design shows its summary, response, specs and file list to everyone; the description, the drivers
            // and the parts list only to people with access (they are what the buyer pays for). Not sent otherwise.
            'locked' => (int) $design->price_cents > 0 && ! ($user !== null && $user->hasAccessTo($design, false)),
            // Kits and finished speakers members sell, built from this design.
            'builds' => $design->listings()->marketplace()->forSale()
                ->with(['seller.sellerProfile', 'driver', 'design'])->latest()->get(),
        ];
    }
} ?>

<x-layouts.marketing>
    @volt('design')
    @php
        $docNo = 'SDL-D-'.str_pad((string) $design->id, 4, '0', STR_PAD_LEFT);
        $latest = $snapshots->first();
        $driverCount = (int) $design->components->sum('quantity');
        $bandCount = $design->components->count();
        $free = (int) $design->price_cents <= 0;
        $axis = fn (float $f): float => max(0, min(100, (log10(max($f, 20)) - log10(20)) / 3 * 100));
        $num = fn ($value) => $value === null || $value === '' ? null : rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
        $designer = $design->designer;
    @endphp
    <div class="min-h-dvh bg-paper pb-10">
        <x-analytics.events :events="[Ga4::payload('view_item', Ga4::ecommerce([Ga4::item($design)]))]" />

        <div class="mx-auto w-full max-w-7xl pl-[max(1rem,env(safe-area-inset-left,0px))] pr-[max(1rem,env(safe-area-inset-right,0px))] sm:px-6 lg:px-8">
            <nav class="py-3.5 text-sm text-ink-3" aria-label="Breadcrumb">
                <ol class="flex flex-wrap items-center gap-x-2">
                    <li><a href="/" class="text-ink-2 hover:text-ink">Home</a></li>
                    <li aria-hidden="true">/</li>
                    <li><a href="/designs" class="text-ink-2 hover:text-ink">Designs</a></li>
                    <li aria-hidden="true">/</li>
                    <li class="min-w-0 text-ink" aria-current="page">{{ $design->name }}</li>
                </ol>
            </nav>

            {{-- Photos on the left, the sheet (number, name, price, specs) on the right --}}
            <div class="grid gap-6 pb-5 lg:grid-cols-12 lg:gap-[30px]">
                <x-marketplace.gallery
                    class="lg:col-span-7"
                    :images="(array) $design->card_image"
                    :paths="true"
                    ratio="4:3"
                    fit="cover"
                    :alt="$design->name"
                />

                <div class="sd-sheet grid content-start lg:col-span-5">
                    <div class="sd-num flex flex-wrap justify-between gap-x-2.5 gap-y-1 border-b border-rule px-3.5 py-2 text-[11px] text-ink-3">
                        <span data-design-docno>{{ $docNo }}</span>
                        <span>{{ $design->category }} &middot; {{ $latest ? 'Files '.$latest->version : 'No files yet' }}</span>
                    </div>

                    <div class="grid gap-3 px-4 pb-4 pt-4 sm:px-[18px] sm:pb-[18px]">
                        <h1 class="sd-title text-[clamp(34px,5vw,46px)] leading-[0.95]">{{ $design->name }}</h1>
                        @if ($design->tag)
                            <p class="text-[17px] !text-ink-2">{{ $design->tag }}</p>
                        @endif

                        <p class="flex flex-wrap items-center gap-x-3.5 gap-y-1.5 text-[15px] !text-ink-2">
                            <span>by
                                @if ($designer && $designer->username)
                                    <a href="{{ url('profile/'.$designer->username) }}" class="font-semibold text-ink hover:underline">{{ $designer->name ?? 'Speaker Designer' }}</a>
                                @else
                                    <b class="font-semibold text-ink">{{ $designer?->name ?? 'Speaker Designer' }}</b>
                                @endif
                            </span>
                            @if ($design->official)
                                <span class="sd-seal">Official</span>
                            @endif
                            <a href="#sec-reviews" class="inline-flex items-center gap-2 text-ink-2 no-underline hover:text-ink" data-design-rating>
                                @if ($rating['count'] > 0)
                                    <span class="sd-stars" style="--p: {{ round($rating['average'] * 20) }}%" role="img" aria-label="{{ number_format($rating['average'], 1) }} out of 5"></span>
                                    <span class="sd-num text-xs">{{ number_format($rating['average'], 1) }} ({{ $rating['count'] }})</span>
                                @else
                                    <span class="text-[13px] text-ink-3">No reviews yet</span>
                                @endif
                            </a>
                        </p>

                        <div id="buy" class="scroll-mt-16">
                            <livewire:buy-box :purchasable="$design" label="Plans, drawings and measurements" />
                        </div>

                        <table class="w-full border-collapse text-[15px]" data-design-specs>
                            <tbody class="[&_td]:border-b [&_td]:border-dotted [&_td]:border-rule [&_td]:py-1.5 [&_td:last-child]:text-right [&_td:last-child]:font-mono [&_td:last-child]:text-[13px] [&_td:last-child]:tabular-nums [&_tr:last-child_td]:border-b-0">
                                <tr><td>Type</td><td>{{ $design->category ?: '-' }}</td></tr>
                                <tr><td>Impedance</td><td>{{ $num($design->impedance) !== null ? $num($design->impedance).' Ω' : '-' }}</td></tr>
                                <tr><td>Power</td><td>{{ $num($design->power) !== null ? $num($design->power).' W' : '-' }}</td></tr>
                                <tr><td>Drivers</td><td>{{ $driverCount }} ({{ $bandCount }} {{ $bandCount === 1 ? 'band' : 'bands' }})</td></tr>
                                <tr><td>&minus;3 dB low end</td><td>{{ $chart && $chart['f3'] !== null ? ResponseChartData::hz($chart['f3']) : '-' }}</td></tr>
                                <tr><td>Build cost</td><td>{{ $design->build_cost_cents ? '≈ '.money((int) $design->build_cost_cents) : '-' }}</td></tr>
                                <tr><td>Published</td><td>{{ $design->created_at?->format('j M Y') ?? '-' }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- Sticky row of links to the sections --}}
        <nav class="sticky top-[env(safe-area-inset-top,0px)] z-20 mb-5 border-b border-rule bg-paper" aria-label="On this page" data-design-jump>
            <div class="sd-jump-row mx-auto max-w-7xl pl-[max(1rem,env(safe-area-inset-left,0px))] pr-[max(1rem,env(safe-area-inset-right,0px))] sm:px-6 lg:px-8">
                @foreach ([
                    ['response', 'Response'],
                    ['about', 'About'],
                    ['drivers', 'Drivers'],
                    ['parts', 'Parts list'],
                    ['files', 'Files'],
                    $builds->isNotEmpty() ? ['builds', 'Kits for sale'] : null,
                    ['reviews', 'Reviews'],
                    ['discussion', 'Discussion'],
                ] as $link)
                    @continue($link === null)
                    <a href="#sec-{{ $link[0] }}" class="flex-none border-b-2 border-transparent px-3 py-[11px] font-condensed text-sm font-semibold uppercase tracking-[0.05em] text-ink-2 no-underline hover:border-brand-800 hover:text-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent-500">{{ $link[1] }}</a>
                @endforeach
            </div>
        </nav>

        <div class="mx-auto w-full max-w-7xl pl-[max(1rem,env(safe-area-inset-left,0px))] pr-[max(1rem,env(safe-area-inset-right,0px))] sm:px-6 lg:px-8">
            {{-- Measured response --}}
            <x-design.response-chart :chart="$chart" />

            {{-- About: summary, then description --}}
            @php
                $css = $design->descriptionStyles();
                $aboutHtml = trim(strip_tags((string) tiptap_converter()->asHtml((string) $design->summary).tiptap_converter()->asHtml((string) $design->description), '<img><table>'));
            @endphp
            <x-design.section id="about" title="About this design">
                @if ($css !== '')
                    {{-- the designer's own CSS, cleaned and limited to .sd-rich-{id} by App\Support\ScopedCss --}}
                    <style data-design-css>
{!! $css !!}
                    </style>
                @endif
                @if ($locked)
                    <div class="grid gap-7">
                        <x-design.rich :content="$design->summary" :design="$design" class="text-[19px]" data-design-summary />
                        <x-design.locked what="The full description: build notes, the crossover and how it was tuned." />
                    </div>
                @elseif ($aboutHtml !== '')
                    <div class="grid gap-7">
                        <x-design.rich :content="$design->summary" :design="$design" class="text-[19px]" data-design-summary />
                        <x-design.rich :content="$design->description" :design="$design" data-design-description />
                    </div>
                @else
                    <p class="text-sm text-ink-3">No summary or description yet.</p>
                @endif
            </x-design.section>

            {{-- Drivers --}}
            <x-design.section id="drivers" title="Drivers" hint="Bar = the band each driver plays, on a 20 Hz to 20 kHz axis">
                @if ($locked)
                    {{-- which drivers it uses is public (it helps people choose); bands, box volumes and notes are for buyers --}}
                    @if ($design->components->isNotEmpty())
                        <ul class="mb-4 grid gap-0 border-t border-hair" data-design-driver-names>
                            @foreach ($design->components->unique('driver_id') as $band)
                                <li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-b border-hair py-2.5">
                                    <span class="font-medium text-ink">{{ $band->driver ? $band->driver->brand.' '.$band->driver->model : 'Driver no longer listed' }}</span>
                                    @if ($band->driver && $band->driver->active)
                                        <a href="{{ route('driver', ['id' => $band->driver->id]) }}" class="text-sm font-semibold text-brand-800 underline hover:text-brand-900">View driver →</a>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <x-design.locked what="The band each driver plays, its box volume and the designer's notes for it." />
                @else
                @if ($design->components->isEmpty())
                    <p class="text-sm text-ink-3">No drivers listed yet.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full border-collapse" data-design-drivers>
                            <thead>
                                <tr class="[&_th]:border-b-2 [&_th]:border-ink [&_th]:py-1.5 [&_th]:pr-2 [&_th]:text-left [&_th]:font-condensed [&_th]:text-xs [&_th]:font-semibold [&_th]:uppercase [&_th]:tracking-[0.06em] [&_th]:text-ink-3">
                                    <th>Band</th>
                                    <th>Driver</th>
                                    <th>Qty</th>
                                    <th>Range</th>
                                    <th>Air volume</th>
                                    <th class="min-w-[180px]">
                                        <div class="sd-num flex min-w-[160px] justify-between text-[10px] font-normal normal-case tracking-normal"><span>20</span><span>200</span><span>2k</span><span>20k</span></div>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="[&_td]:border-b [&_td]:border-hair [&_td]:py-[9px] [&_td]:pr-2 [&_td]:align-middle [&_td]:text-[15px] [&_td.m]:whitespace-nowrap [&_td.m]:font-mono [&_td.m]:text-xs [&_td.m]:tabular-nums">
                                @foreach ($design->components as $band)
                                    @php
                                        $low = $band->low_frequency ? (float) $band->low_frequency : null;
                                        $high = $band->high_frequency ? (float) $band->high_frequency : null;
                                        $from = $axis($low ?? 20);
                                        $to = $axis($high ?? 20000);
                                        $air = (float) $band->air_volume;
                                        $driver = $band->driver;
                                    @endphp
                                    <tr data-design-driver>
                                        <td class="m">{{ $band->position }}</td>
                                        <td>
                                            @if ($driver)
                                                <a href="{{ url('drivers/driver/'.$driver->id) }}" class="font-semibold text-brand-800 underline hover:text-brand-900">{{ $driver->fullName() }}</a>
                                            @else
                                                -
                                            @endif
                                        </td>
                                        <td class="m">{{ $band->quantity }}</td>
                                        <td class="m">{{ $low ? ResponseChartData::hz($low) : '?' }} &ndash; {{ $high ? ResponseChartData::hz($high) : '?' }}</td>
                                        <td class="m" data-air-volume>{{ $air > 0 ? $num($air).' L' : '-' }}</td>
                                        <td><div class="sd-lane" role="img" aria-label="{{ $low ? ResponseChartData::hz($low) : 'low end' }} to {{ $high ? ResponseChartData::hz($high) : 'high end' }}"><i style="left: {{ number_format($from, 1, '.', '') }}%; width: {{ number_format(max(1, $to - $from), 1, '.', '') }}%"></i></div></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4">
                        @foreach ($design->components as $band)
                            <details open class="border-t border-hair" data-driver-notes>
                                <summary class="flex cursor-pointer list-none flex-wrap items-baseline gap-x-3.5 gap-y-1 py-3 font-semibold text-ink [&::-webkit-details-marker]:hidden">
                                    <span class="sd-num w-3.5 text-base font-medium text-brand-800" aria-hidden="true">+</span>
                                    <span>{{ $band->position }} &middot; {{ $band->driver?->fullName() ?? 'Driver' }}</span>
                                    <span class="sd-num text-xs font-normal text-ink-2">{{ $band->quantity }} &times;{{ (float) $band->air_volume > 0 ? ' · '.$num($band->air_volume).' L' : '' }}</span>
                                </summary>
                                <div class="pb-4 pl-6 max-sm:pl-0">
                                    @if (trim(strip_tags((string) tiptap_converter()->asHtml((string) $band->description), '<img><table>')) !== '')
                                        <x-design.rich :content="$band->description" :design="$design" class="text-base" />
                                    @else
                                        <p class="text-[13px] text-ink-3">No notes for this driver.</p>
                                    @endif
                                </div>
                            </details>
                        @endforeach
                    </div>
                @endif
                @endif
            </x-design.section>

            {{-- Parts list --}}
            @php
                $parts = collect((array) $design->bill_of_materials)->filter(fn ($value, $key) => filled($key) || filled($value));
            @endphp
            <x-design.section id="parts" title="Parts list" hint="From the designer's bill of materials">
                @if ($locked)
                    <x-design.locked :what="'The parts list'.($parts->isNotEmpty() ? ' ('.$parts->count().' '.\Illuminate\Support\Str::plural('line', $parts->count()).')' : '').'.'" />
                @else
                @if ($parts->isEmpty())
                    <p class="text-sm text-ink-3">No parts list yet.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full border-collapse text-[15px]" data-design-bom>
                            <thead>
                                <tr class="[&_th]:border-b-2 [&_th]:border-ink [&_th]:py-1.5 [&_th]:pr-2.5 [&_th]:text-left [&_th]:font-condensed [&_th]:text-xs [&_th]:font-semibold [&_th]:uppercase [&_th]:tracking-[0.06em] [&_th]:text-ink-3">
                                    <th>Part</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody class="[&_td]:border-b [&_td]:border-hair [&_td]:py-2 [&_td]:pr-2.5 [&_td]:align-top [&_td:first-child]:whitespace-nowrap [&_td:first-child]:font-semibold">
                                @foreach ($parts as $part => $details)
                                    <tr><td>{{ $part }}</td><td>{{ is_scalar($details) ? $details : json_encode($details) }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                @endif
            </x-design.section>

            {{-- What you download --}}
            @php
                $groupFiles = fn (string ...$titles) => collect($fileGroups)->filter(fn ($g) => collect($titles)->contains(fn ($t) => str_starts_with($g['title'], $t)))->sum(fn ($g) => count($g['files']));
                $measurementFiles = collect($fileGroups)->filter(fn ($g) => str_contains($g['title'], ':') || $g['title'] === 'Response previews')->sum(fn ($g) => count($g['files']));
            @endphp
            <x-design.section id="files" title="What you download" data-design-files>
                <x-slot:header>
                    <span class="inline-flex items-center gap-1.5 text-[13px] text-ink-2" data-files-access>
                        <x-phosphor-lock-simple class="h-4 w-4" />
                        @if ($hasAccess)
                            You have access to these files
                        @elseif ($free)
                            Free with an account
                        @else
                            Available after purchase
                        @endif
                    </span>
                </x-slot:header>

                <div class="grid grid-cols-[repeat(auto-fit,minmax(140px,1fr))] border border-hair [&>div]:grid [&>div]:content-start [&>div]:gap-0.5 [&>div]:border-r [&>div]:border-hair [&>div]:px-3 [&>div]:py-2.5">
                    <div><b class="sd-num text-[22px] font-medium leading-none">{{ $groupFiles('Enclosure') }}</b><span class="text-[13px] text-ink-2">Enclosure files</span></div>
                    <div><b class="sd-num text-[22px] font-medium leading-none">{{ $groupFiles('Crossover') }}</b><span class="text-[13px] text-ink-2">Crossover / electronics files</span></div>
                    <div><b class="sd-num text-[22px] font-medium leading-none">{{ $groupFiles('Other design') }}</b><span class="text-[13px] text-ink-2">Other design files</span></div>
                    <div><b class="sd-num text-[22px] font-medium leading-none">{{ $measurementFiles }}</b><span class="text-[13px] text-ink-2">Measurement files</span></div>
                    <div><b class="sd-num text-[22px] font-medium leading-none">1</b><span class="text-[13px] text-ink-2">Build guide PDF with your name on every page</span></div>
                </div>

                @foreach ($fileGroups as $group)
                    <div class="mt-5" data-file-group>
                        <h3 class="sd-label border-b-2 border-ink pb-1">{{ $group['title'] }}</h3>
                        @if ($locked)
                            {{-- paid design, no access: how many files of which kind, not their names --}}
                            <p class="py-2 text-[15px] text-ink-2" data-file-kinds>
                                {{ collect($group['files'])->countBy('type')->map(fn ($n, $type) => $n.' × '.$type)->implode(' · ') }}
                            </p>
                        @else
                        <div class="overflow-x-auto">
                            <table class="w-full table-fixed border-collapse text-[15px] max-sm:min-w-[440px]">
                                <colgroup><col><col class="w-[210px]"><col class="w-[80px]"></colgroup>
                                <tbody class="[&_td]:border-b [&_td]:border-hair [&_td]:py-2 [&_td]:pr-3 [&_td]:align-baseline">
                                    @foreach ($group['files'] as $file)
                                        <tr data-file>
                                            <td class="break-all font-medium text-ink">{{ $file['name'] }}</td>
                                            <td class="whitespace-nowrap text-ink-2">{{ $file['type'] }}</td>
                                            <td class="sd-num whitespace-nowrap text-right text-xs text-ink-2">{{ \App\Support\DesignFileList::size($file['size']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @endif
                    </div>
                @endforeach
                @if ($fileGroups === [])
                    <p class="mt-4 text-sm text-ink-3">No source files are listed for this design yet.</p>
                @endif

                <div class="mt-6">
                    <h3 class="sd-label border-b-2 border-ink pb-1">Versions</h3>
                    @forelse ($snapshots as $snapshot)
                        <div class="sd-num flex flex-wrap items-center justify-between gap-x-3.5 gap-y-1.5 border-b border-dotted border-rule py-2 text-[13px]" data-design-version>
                            <span class="font-medium text-ink">{{ $snapshot->version }}</span>
                            <span class="text-ink-2">{{ $snapshot->created_at?->format('j M Y') }}</span>
                            <span class="text-ink-2">{{ $snapshot->file_size ? \App\Support\DesignFileList::size((int) $snapshot->file_size) : '-' }}</span>
                            @if ($hasAccess)
                                <a href="{{ route('design-snapshots.download', $snapshot->id) }}" class="sd-btn sd-btn-sm" data-ga4-click="{{ Ga4::download($design, $snapshot->snapshot_name) }}">Download {{ $snapshot->snapshot_name }}</a>
                            @endif
                        </div>
                    @empty
                        <p class="mt-2 text-sm text-ink-3">No files published yet.</p>
                    @endforelse
                    @if ($snapshots->isNotEmpty() && ! $hasAccess)
                        <p class="mt-3 text-sm text-ink-2">{{ auth()->check() ? ($free ? 'Get this design to download its files.' : 'Buy this design to download its files.') : 'Log in to download this design\'s files.' }} Every version comes as one ZIP, with the build guide PDF made for you.</p>
                    @endif
                </div>
            </x-design.section>

            {{-- Kits & builds for sale --}}
            @if ($builds->isNotEmpty())
                <x-design.section id="builds" title="Kits & builds for sale" data-design-builds>
                    <x-marketing.listing-card-container :listings="$builds" cols="grid-cols-1 sm:grid-cols-2 lg:grid-cols-4" :padded="false" />
                </x-design.section>
            @endif

            {{-- Reviews and Discussion --}}
            <livewire:design-description :design="$design" />
        </div>
    </div>
    @endvolt
</x-layouts.marketing>
