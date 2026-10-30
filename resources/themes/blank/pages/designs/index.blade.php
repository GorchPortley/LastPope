<?php

use function Laravel\Folio\{name};
use Livewire\Volt\Component;
use Livewire\WithPagination;
use App\Models\Design;
use Illuminate\Http\Request;

name('designs');

new class extends Component {
    use WithPagination;

    public function with(Request $request): array
    {
        $query = Design::query()
            ->where('active', 1)
            // The designer's roles and shop decide whether a card can be bought (analytics item data); load them once.
            ->with(['designer.roles', 'designer.sellerProfile'])
            // Rating on the cards from one query for the page, not several per card (Design::ratingSummary()).
            ->withAvg('reviews', 'rating')
            ->withCount(['reviews', 'components']);

        // Filter by category
        if ($request->category) {
            $query->where('category', $request->category);
        }

        // Filter by price range
        if ($request->min_price) {
            $query->where('price_cents', '>=', (int) round($request->min_price * 100));
        }
        if ($request->max_price) {
            $query->where('price_cents', '<=', (int) round($request->max_price * 100));
        }

        // Sort
        // Only known columns and directions: anything else is ignored instead of becoming a database error.
        $sortField = $request->sort === 'price' ? 'price_cents' : (in_array($request->sort, ['created_at', 'name'], true) ? $request->sort : 'created_at');
        $sortDirection = $request->direction === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortField, $sortDirection);

        // Get distinct categories for filter dropdown
        $categories = Design::distinct('category')->pluck('category');
        // Published designs per category, for the counts next to the filter list.
        $categoryCounts = Design::query()->where('active', 1)->selectRaw('category, count(*) as total')->groupBy('category')->pluck('total', 'category');

        return [
            'designs' => $query->paginate(12),
            'categories' => $categories,
            'categoryCounts' => $categoryCounts,
            'maxPrice' => ceil(Design::max('price_cents') / 100)
        ];
    }
} ?>

<x-layouts.marketing>
    @volt('designs')
    <div class="min-h-[60vh] bg-paper">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="pt-4">
                <livewire:banner-display location="design_page">
            </div>

            <div class="grid gap-7 pb-12 pt-6 lg:grid-cols-[230px_minmax(0,1fr)]" x-data="{ filters: false }">
                <aside class="lg:self-start">
                    <button type="button" @click="filters = ! filters" class="sd-btn mb-4 w-full justify-between lg:hidden" :aria-expanded="filters">
                        <span>Filters</span>
                        <span x-text="filters ? '-' : '+'" aria-hidden="true"></span>
                    </button>

                    <form id="design-filters" method="GET" :class="filters ? 'grid' : 'hidden lg:grid'" class="gap-5">
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

                        <!-- Price Filter -->
                        <fieldset>
                            <legend class="sd-label mb-2 w-full border-b border-rule pb-1.5 text-ink">Price range</legend>
                            <div class="flex items-center gap-2">
                                <input
                                    type="number"
                                    name="min_price"
                                    value="{{ request('min_price') }}"
                                    placeholder="Min"
                                    aria-label="Minimum price"
                                    min="0"
                                    max="{{ $maxPrice }}"
                                    class="w-1/2 rounded-none border-rule bg-white py-1.5 font-mono text-sm focus:border-ink focus:ring-0"
                                >
                                <span class="text-ink-3">-</span>
                                <input
                                    type="number"
                                    name="max_price"
                                    value="{{ request('max_price') }}"
                                    placeholder="Max"
                                    aria-label="Maximum price"
                                    min="0"
                                    max="{{ $maxPrice }}"
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
                            <h1 class="sd-title text-3xl sm:text-4xl">Designs</h1>
                            <p class="mt-1.5 font-mono text-[13px] text-ink-2" data-result-count>{{ number_format($designs->total()) }} {{ \Illuminate\Support\Str::plural('design', $designs->total()) }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <label class="sd-label" for="design-sort">Sort</label>
                            <select id="design-sort" name="sort" form="design-filters" onchange="this.form.submit()" class="rounded-none border-rule bg-white py-1.5 text-sm focus:border-ink focus:ring-0">
                                <option value="created_at" {{ request('sort') === 'created_at' ? 'selected' : '' }}>Date</option>
                                <option value="price" {{ request('sort') === 'price' ? 'selected' : '' }}>Price</option>
                            </select>
                            <select name="direction" form="design-filters" aria-label="Sort direction" onchange="this.form.submit()" class="rounded-none border-rule bg-white py-1.5 text-sm focus:border-ink focus:ring-0">
                                <option value="asc" {{ request('direction') === 'asc' ? 'selected' : '' }}>Ascending</option>
                                <option value="desc" {{ request('direction') !== 'asc' ? 'selected' : '' }}>Descending</option>
                            </select>
                        </div>
                    </div>

                    <x-marketing.design-card-container
                        :designs="$designs"
                        :padded="false"/>
                    <div class="mt-8 flex justify-center gap-4">
                        {{ $designs->links() }}
                    </div>
                </section>
            </div>
        </div>
    </div>
    @endvolt
</x-layouts.marketing>
