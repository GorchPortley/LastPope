{{--
    Session flash messages of the marketplace: `success`, `info`, `error` and the list `checkout_issues`
    (the reasons a checkout was refused). Rendered once by the layouts.
--}}
@php
    $messages = collect(['success', 'info', 'error'])
        ->filter(fn ($level) => filled(session($level)))
        ->map(fn ($level) => ['level' => $level, 'message' => session($level)]);
    $issues = array_filter((array) session('checkout_issues', []));
@endphp

@if ($messages->isNotEmpty())
    <div {{ $attributes->class(['space-y-3']) }} role="status">
        @foreach ($messages as $flash)
            <div @class([
                'flex items-start gap-3 border px-4 py-3 text-sm',
                'border-green-300 bg-green-50 text-green-800' => $flash['level'] === 'success',
                'border-blue-300 bg-blue-50 text-blue-800' => $flash['level'] === 'info',
                'border-red-300 bg-red-50 text-red-800' => $flash['level'] === 'error',
            ])>
                @if ($flash['level'] === 'success')
                    <x-phosphor-check-circle class="mt-0.5 h-5 w-5 flex-shrink-0" />
                @elseif ($flash['level'] === 'error')
                    <x-phosphor-warning class="mt-0.5 h-5 w-5 flex-shrink-0" />
                @else
                    <x-phosphor-info class="mt-0.5 h-5 w-5 flex-shrink-0" />
                @endif
                <div class="min-w-0">
                    <p class="text-inherit font-medium">{{ $flash['message'] }}</p>
                    @if ($flash['level'] === 'error' && $issues !== [])
                        <ul class="mt-1 list-disc space-y-0.5 pl-5">
                            @foreach ($issues as $issue)
                                <li>{{ $issue }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
@endif
