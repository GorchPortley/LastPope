{{--
    "Measured response" sheet: the legend buttons that switch each line and the sum on and off, the chart (inline SVG
    drawn by assets/js/response-chart.js at the real width of its box), and a readout line with the level under the
    pointer, the midband level and the -3 dB low end. `chart` is App\Support\ResponseChartData::from(), null when there
    are no usable measurement files. Used by the design page and the driver page (`empty` is the text for no files; a
    chart made with from($response, false) shows the lines without a sum).
--}}
@props(['chart' => null, 'empty' => 'No measurement files.'])

@php
    use App\Support\ResponseChartData;
@endphp

@if ($chart === null)
    <x-design.section id="response" title="Measured response" data-response-empty>
        <p class="text-sm text-ink-3">{{ $empty }}</p>
    </x-design.section>
@else
    <section id="sec-response" class="sd-sheet mb-5 scroll-mt-14" data-response-chart
             x-data="responseChart(@js(['series' => $chart['series'], 'sum' => $chart['sum'], 'summed' => $chart['summed'] ?? true]))">
        <header class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b border-rule px-4 py-3 max-sm:px-3">
            <h2 class="sd-title text-[22px]">Measured response</h2>
            <div class="flex flex-wrap gap-1.5" role="group" aria-label="Show lines">
                <template x-for="l in lines" :key="l.key">
                    <button type="button" @click="toggle(l.key)" :aria-pressed="!off[l.key]"
                            class="sd-num inline-flex items-center gap-[7px] border border-rule bg-white px-2.5 py-1.5 text-xs text-ink hover:border-ink aria-[pressed=false]:opacity-45">
                        <i class="inline-block h-[3px] w-3.5" :style="'background:' + l.color"></i><span x-text="l.label"></span>
                    </button>
                </template>
            </div>
        </header>
        <div class="p-4 max-sm:p-3">
            <div x-ref="plot" class="w-full sm:min-h-[260px]"></div>
            <noscript><p class="text-sm text-ink-3">The chart needs JavaScript.</p></noscript>
        </div>
        <div class="sd-num flex min-h-10 flex-wrap items-center gap-x-[18px] gap-y-1 border-t border-hair px-4 py-2.5 text-xs text-ink-2 max-sm:px-3" data-response-readout>
            @if ($chart['summed'] ?? true)
                <span x-show="!at">Hover or drag across the chart to read the summed level.</span>
                <span x-show="at" x-cloak>At <b class="font-medium text-ink" x-text="at && at.f"></b> the sum is <b class="font-medium text-ink" x-text="at && at.db"></b></span>
            @else
                <span x-show="!at">Hover or drag across the chart to read the level.</span>
                <span x-show="at" x-cloak>At <b class="font-medium text-ink" x-text="at && at.f"></b> the level is <b class="font-medium text-ink" x-text="at && at.db"></b></span>
            @endif
            <span>Midband <b class="font-medium text-ink">{{ $chart['midband'] !== null ? number_format($chart['midband'], 1).' dB' : '-' }}</b></span>
            <span>&minus;3 dB <b class="font-medium text-ink">{{ $chart['f3'] !== null ? ResponseChartData::hz($chart['f3']) : '-' }}</b></span>
        </div>
    </section>
@endif
