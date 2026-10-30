<?php
    use App\Models\Design;
    use App\Models\Driver;
    use function Laravel\Folio\{name, render};
    use Illuminate\Contracts\View\View;

    name('home');

    render(function (View $view) {
        // Cards from one query each: rating and driver count as aggregates, designer/store listing eager-loaded.
        $designs = Design::query()->active()
            ->with(['designer.roles', 'designer.sellerProfile'])
            ->withAvg('reviews', 'rating')->withCount(['reviews', 'components'])
            ->latest()->limit(8)->get();

        $drivers = Driver::query()->where('active', 1)
            ->withCount('designs')
            ->with('activeStoreListing.supplier')
            ->latest()->limit(4)->get();

        $types = Design::query()->active()->whereNotNull('category')
            ->selectRaw('category, count(*) as total')->groupBy('category')->orderBy('category')
            ->pluck('total', 'category');

        return $view->with(['designs' => $designs, 'drivers' => $drivers, 'types' => $types]);
    });
?>

<x-layouts.marketing
    :seo="[
        'title'         => setting('site.title', 'SDLabs.cc'),
        'description'   => setting('site.description', 'Speaker Design Marketplace'),
        'image'         => url('/og_image.png'),
        'type'          => 'website'
    ]"
>
    <div class="hidden lg:block">
        <livewire:banner-display  location="front_page"></livewire:banner-display>
    </div>
    <x-marketing.hero :featured="$designs->first(fn ($design) => ! empty($design->card_image))" />

    <div class="bg-paper pb-12">
        <div class="mx-auto max-w-7xl space-y-10 px-4 pt-8 sm:px-6 lg:px-8">
            @if ($designs->isNotEmpty())
                <section aria-labelledby="home-designs">
                    <div class="sd-section-head mb-4">
                        <h2 id="home-designs" class="sd-title text-3xl">Newest designs</h2>
                        <a href="{{ route('designs') }}" class="font-condensed text-sm font-semibold uppercase tracking-[0.05em] text-brand-800 no-underline hover:underline">All designs &rarr;</a>
                    </div>
                    <x-marketing.design-card-container :designs="$designs" :padded="false" list-name="Home designs" />
                </section>
            @endif

            @if ($types->isNotEmpty())
                @php
                    // Pad the last row so the square grid is complete at 4 columns (and so at 2).
                    $fillers = (4 - $types->count() % 4) % 4;
                @endphp
                <section aria-labelledby="home-types">
                    <div class="sd-section-head mb-4">
                        <h2 id="home-types" class="sd-title text-3xl">Browse by type</h2>
                        <a href="{{ route('designs') }}" class="font-condensed text-sm font-semibold uppercase tracking-[0.05em] text-brand-800 no-underline hover:underline">Everything &rarr;</a>
                    </div>
                    <div class="grid grid-cols-2 border-l border-t border-rule bg-white lg:grid-cols-4" data-home-types>
                        @foreach ($types as $category => $total)
                            <a href="{{ route('designs', ['category' => $category]) }}" class="grid gap-0.5 border-b border-r border-rule px-3.5 py-3 no-underline transition-colors hover:bg-paper">
                                <b class="font-condensed text-lg font-bold uppercase leading-tight text-ink">{{ $category }}</b>
                                <span class="font-mono text-xs text-ink-3">{{ $total }} {{ \Illuminate\Support\Str::plural('design', $total) }}</span>
                            </a>
                        @endforeach
                        @for ($i = 0; $i < $fillers; $i++)
                            <div class="border-b border-r border-rule bg-paper" aria-hidden="true"></div>
                        @endfor
                    </div>
                </section>
            @endif

            @if ($drivers->isNotEmpty())
                <section aria-labelledby="home-drivers">
                    <div class="sd-section-head mb-4">
                        <h2 id="home-drivers" class="sd-title text-3xl">Drivers</h2>
                        <a href="{{ route('drivers') }}" class="font-condensed text-sm font-semibold uppercase tracking-[0.05em] text-brand-800 no-underline hover:underline">All drivers &rarr;</a>
                    </div>
                    <x-marketing.driver-card-container :drivers="$drivers" :padded="false" list-name="Home drivers" />
                </section>
            @endif
        </div>
    </div>
</x-layouts.marketing>
