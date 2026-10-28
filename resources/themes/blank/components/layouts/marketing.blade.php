@php($season = app(\App\Support\SeasonalTheme::class)->forRequest(request()))
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" class=""@if ($season !== \App\Support\SeasonalTheme::DEFAULT) data-season="{{ $season }}"@endif>
<head>
    @include('theme::partials.analytics')
    @include('theme::partials.head', ['seo' => ($seo ?? null) ])
    @stack('head')
</head>
<body class="flex min-h-screen flex-col bg-white dark:bg-zinc-900">
<x-marketing.header />
<x-seasonal.decor />
<main class="flex-1">
    <x-marketplace.flash class="mx-auto mt-4 max-w-7xl px-4 sm:px-6 lg:px-8" />
    {!! $slot !!}
</main>
<x-marketing.footer />
{{ $javascript ?? '' }}
@livewire('notifications')
@filamentScripts
@livewireScripts
@include('theme::partials.consent-banner')

</body>
</html>