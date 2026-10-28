{{--
    Sends GA4 events from a plain page (docs/PLAN.md 12): `events` is a list of ['name' => ..., 'params' => [...]]
    built with App\Support\Analytics\Ga4::payload(). Renders nothing while GA4_MEASUREMENT_ID is empty. Livewire
    actions use $this->dispatch('ga4', name: ..., params: ...) instead; the analytics partial forwards both to gtag.
--}}
@props(['events' => []])
@if (\App\Support\Analytics\Ga4::enabled() && $events !== [])
    <script>
        @foreach ($events as $event)
            window.sdlGa4 && window.sdlGa4.track({{ \Illuminate\Support\Js::from($event['name']) }}, {{ \Illuminate\Support\Js::from($event['params'] ?? (object) []) }});
        @endforeach
    </script>
@endif
