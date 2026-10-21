@props(['level' => 'info'])

<div {{ $attributes->class([
    'flex items-start gap-3 border px-4 py-3 text-sm',
    'border-green-300 bg-green-50 text-green-800' => $level === 'success',
    'border-blue-300 bg-blue-50 text-blue-800' => $level === 'info',
    'border-amber-300 bg-amber-50 text-amber-900' => $level === 'warning',
    'border-red-300 bg-red-50 text-red-800' => $level === 'error',
]) }}>
    @if ($level === 'success')
        <x-phosphor-check-circle class="mt-0.5 h-5 w-5 flex-shrink-0" />
    @elseif ($level === 'error' || $level === 'warning')
        <x-phosphor-warning class="mt-0.5 h-5 w-5 flex-shrink-0" />
    @else
        <x-phosphor-info class="mt-0.5 h-5 w-5 flex-shrink-0" />
    @endif
    <div class="min-w-0 [&_p]:text-inherit [&_a]:underline">{{ $slot }}</div>
</div>
