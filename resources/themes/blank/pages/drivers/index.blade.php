<?php
use function Laravel\Folio\{name};
use Livewire\Volt\Component;
use Livewire\WithPagination;
use App\Models\Driver;
use Illuminate\Http\Request;

name('drivers');

new class extends Component {
    use WithPagination;

    public function with(Request $request): array
    {
        $query = Driver::query()
            ->where('active', 1)
            ->withCount('designs')  // the cards show the count; no need to load every design of every driver
            ->with('activeStoreListing.supplier'); // SDLabs' store listing, for the "In stock at SDLabs" line on the cards

        // Filter by manufacturer (the filter form submits `manufacturer`; `brand` still works for old links)
        $manufacturer = $request->input('manufacturer', $request->input('brand'));
        if (is_string($manufacturer) && $manufacturer !== '') {
            $query->where('brand', $manufacturer);
        }

        // Filter by category
        if ($request->category) {
            $query->where('category', $request->category);
        }

        // Filter by power range
        if ($request->min_power) {
            $query->where('power', '>=', $request->min_power);
        }
        if ($request->max_power) {
            $query->where('power', '<=', $request->max_power);
        }

        // Filter by impedance
        if ($request->impedance) {
            $query->where('impedance', $request->impedance);
        }

        // Custom sorting
        // Only known columns and directions: anything else is ignored instead of becoming a database error.
        // ("power_handling" is what the sort menu sends for Power; there is no such column.)
        $sortField = $request->sort === 'power_handling' ? 'power' : $request->sort;
        $sortField = in_array($sortField, ['created_at', 'brand', 'model', 'size', 'power', 'impedance', 'designs'], true) ? $sortField : 'created_at';
        $sortDirection = $request->direction === 'asc' ? 'asc' : 'desc';

        // Handle special sort case for designs count
        if ($sortField === 'designs') {
            $query->orderBy('designs_count', $sortDirection);
        } else {
            $query->orderBy($sortField, $sortDirection);
        }

        // Cache frequently accessed collections
        $manufacturers = cache()->remember('driver-manufacturers', 3600, function() {
            return Driver::distinct('brand')->pluck('brand');
        });

        $categories = cache()->remember('driver-categories', 3600, function() {
            return Driver::distinct('category')->pluck('category');
        });

        // Published drivers per category, for the counts next to the filter list.
        $categoryCounts = cache()->remember('driver-category-counts', 3600, function() {
            return Driver::query()->where('active', 1)->selectRaw('category, count(*) as total')->groupBy('category')->pluck('total', 'category');
        });

        $impedances = cache()->remember('driver-impedances', 3600, function() {
            return Driver::distinct('impedance')->pluck('impedance')->sort();
        });

        $maxPower = cache()->remember('driver-max-power', 3600, function() {
            return ceil(Driver::max('power'));
        });

        return [
            'drivers' => $query->paginate(15),
            'manufacturers' => $manufacturers,
            'impedances' => $impedances,
            'maxPower' => $maxPower,
            'categories' => $categories,
            'categoryCounts' => $categoryCounts,
        ];
    }
} ?>

<x-layouts.marketing>
    @volt('drivers')
    <div class="min-h-[60vh] bg-paper">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="pt-4">
                <livewire:banner-display location="driver_page">
            </div>

            <div class="grid gap-7 pb-12 pt-6 lg:grid-cols-[230px_minmax(0,1fr)]" x-data="{ filters: false }">
                <aside class="lg:self-start">
                    <button type="button" @click="filters = ! filters" class="sd-btn mb-4 w-full justify-between lg:hidden" :aria-expanded="filters">
                        <span>Filters</span>
                        <span x-text="filters ? '-' : '+'" aria-hidden="true"></span>
                    </button>

                    <form id="driver-filters" method="GET" :class="filters ? 'grid' : 'hidden lg:grid'" class="gap-5">
                        <!-- Category Filter -->
                        <fieldset>
                            <legend class="sd-label mb-2 w-full border-b border-rule pb-1.5 text-ink">Category</legend>
                            <label class="flex cursor-pointer items-center justify-between gap-2 py-1 text-[15px] text-ink">
                                <span class="flex items-center gap-2"><input type="radio" name="category" value="" class="h-4 w-4 border-rule text-brand-800 focus:ring-brand-800" {{ request('category') ? '' : 'checked' }}> All</span>
                                <em class="font-mono text-xs not-italic text-ink-3">{{ $categoryCounts->sum() }}</em>
                            </label>
                            @foreach($categories as $category)
                                <label class="flex cursor-pointer items-center justify-between gap-2 py-1 text-[15px] text-ink">
                                    <span class="flex items-center gap-2"><input type="radio" name="category" value="{{ $category }}" class="h-4 w-4 border-rule text-brand-800 focus:ring-brand-800" {{ request('category') === $category ? 'checked' : '' }}> {{ $category }}</span>
                                    <em class="font-mono text-xs not-italic text-ink-3">{{ $categoryCounts[$category] ?? 0 }}</em>
                                </label>
                            @endforeach
                        </fieldset>

                        <!-- Manufacturer Filter -->
                        <div>
                            <label for="f-manufacturer" class="sd-label mb-2 block w-full border-b border-rule pb-1.5 text-ink">Manufacturer</label>
                            <select id="f-manufacturer" name="manufacturer" class="w-full rounded-none border-rule bg-white py-1.5 text-sm focus:border-ink focus:ring-0">
                                <option value="">All Manufacturers</option>
                                @foreach($manufacturers as $manufacturer)
                                    <option value="{{ $manufacturer }}" {{ request('manufacturer') === $manufacturer ? 'selected' : '' }}>
                                        {{ $manufacturer }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <!-- Impedance Filter -->
                        <div>
                            <label for="f-impedance" class="sd-label mb-2 block w-full border-b border-rule pb-1.5 text-ink">Impedance (&Omega;)</label>
                            <select id="f-impedance" name="impedance" class="w-full rounded-none border-rule bg-white py-1.5 font-mono text-sm focus:border-ink focus:ring-0">
                                <option value="">All Impedances</option>
                                @foreach($impedances as $impedance)
                                    <option value="{{ $impedance }}" {{ request('impedance') == $impedance ? 'selected' : '' }}>
                                        {{ $impedance }}&Omega;
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <!-- Power Handling Filter -->
                        <fieldset>
                            <legend class="sd-label mb-2 w-full border-b border-rule pb-1.5 text-ink">Power handling (W)</legend>
                            <div class="flex items-center gap-2">
                                <input
                                    type="number"
                                    name="min_power"
                                    value="{{ request('min_power') }}"
                                    placeholder="Min"
                                    aria-label="Minimum power"
                                    min="0"
                                    max="{{ $maxPower }}"
                                    class="w-1/2 rounded-none border-rule bg-white py-1.5 font-mono text-sm focus:border-ink focus:ring-0"
                                >
                                <span class="text-ink-3">-</span>
                                <input
                                    type="number"
                                    name="max_power"
                                    value="{{ request('max_power') }}"
                                    placeholder="Max"
                                    aria-label="Maximum power"
                                    min="0"
                                    max="{{ $maxPower }}"
                                    class="w-1/2 rounded-none border-rule bg-white py-1.5 font-mono text-sm focus:border-ink focus:ring-0"
                                >
                            </div>
                        </fieldset>

                        <!-- Action Buttons -->
                        <div class="flex gap-2">
                            <button type="submit" class="sd-btn sd-btn-go sd-btn-sm flex-1">Apply filters</button>
                            <a href="{{ request()->url() }}" class="sd-btn sd-btn-sm flex-1">Clear</a>
                        </div>
                    </form>
                </aside>

                <section class="min-w-0">
                    <!-- Top bar: heading, result count and sort -->
                    <div class="mb-4 flex flex-wrap items-end justify-between gap-x-4 gap-y-3">
                        <div>
                            <h1 class="sd-title text-3xl sm:text-4xl">Drivers</h1>
                            <p class="mt-1.5 font-mono text-[13px] text-ink-2" data-result-count>{{ number_format($drivers->total()) }} {{ \Illuminate\Support\Str::plural('driver', $drivers->total()) }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <label class="sd-label" for="driver-sort">Sort</label>
                            <select id="driver-sort" name="sort" form="driver-filters" onchange="this.form.submit()" class="rounded-none border-rule bg-white py-1.5 text-sm focus:border-ink focus:ring-0">
                                <option value="created_at" {{ request('sort') === 'created_at' ? 'selected' : '' }}>Date</option>
                                <option value="power_handling" {{ request('sort') === 'power_handling' ? 'selected' : '' }}>Power</option>
                                <option value="designs" {{ request('sort') === 'designs' ? 'selected' : '' }}>Design Number</option>
                            </select>
                            <select name="direction" form="driver-filters" aria-label="Sort direction" onchange="this.form.submit()" class="rounded-none border-rule bg-white py-1.5 text-sm focus:border-ink focus:ring-0">
                                <option value="asc" {{ request('direction') === 'asc' ? 'selected' : '' }}>Ascending</option>
                                <option value="desc" {{ request('direction') !== 'asc' ? 'selected' : '' }}>Descending</option>
                            </select>
                        </div>
                    </div>

                    <x-marketing.driver-card-container
                        :drivers="$drivers"
                        :padded="false"
                    />
                    <div class="mt-8 flex justify-center gap-4">
                        {{ $drivers->links() }}
                    </div>
                </section>
            </div>
        </div>
    </div>
    @endvolt
</x-layouts.marketing>
