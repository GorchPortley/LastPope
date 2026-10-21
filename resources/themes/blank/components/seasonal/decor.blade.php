{{--
    Seasonal decoration, rendered by the public layouts (marketing and app). Nothing is output for the default look.

    - garnish: a small inline SVG along the bottom edge of the marketing header (components/seasonal/garnish/{name}),
      positioned by seasons.css. Render this component right after the header so the garnish lands on its bottom edge,
      or pass :garnish="false" in layouts that have no header at the top (the dashboard).
    - effect:  `snow` is a CSS-only falling snow layer (seasons.css); it ignores the pointer, sits below dropdowns and
      modals, and is hidden under prefers-reduced-motion.
    - preview: when an admin looks at ?preview_season=..., a small notice says so (and how to leave the preview).
--}}
@props(['garnish' => true])
@php
    $theme = app(\App\Support\SeasonalTheme::class);
    $season = $theme->get($theme->forRequest(request()));
    $active = $season['key'] !== \App\Support\SeasonalTheme::DEFAULT;
@endphp
@if ($active)
    <div class="season-decor" data-season-decor="{{ $season['key'] }}" aria-hidden="true">
        @if ($garnish && $season['garnish'] && view()->exists('theme::components.seasonal.garnish.'.$season['garnish']))
            <div class="season-garnish season-garnish--{{ $season['key'] }}">
                <x-dynamic-component :component="'seasonal.garnish.'.$season['garnish']" />
            </div>
        @endif
        @if ($season['effect'] === 'snow')
            <div class="season-snow"></div>
        @endif
    </div>
    @if ($theme->isPreview(request()))
        <div class="season-preview fixed bottom-4 left-4 z-[60] flex max-w-[calc(100vw-2rem)] flex-wrap items-center gap-x-3 gap-y-1 border border-gray-300 bg-white px-4 py-2 text-sm text-gray-800 shadow-lg" role="status">
            <span>Previewing <strong>{{ $season['label'] }}</strong> (admins only, not live).</span>
            <a class="font-medium text-brand-700 underline hover:text-brand-900" href="{{ request()->url() }}">Leave preview</a>
            <a class="font-medium text-brand-700 underline hover:text-brand-900" href="{{ url('/admin/appearance') }}">Appearance settings</a>
        </div>
    @endif
@endif
