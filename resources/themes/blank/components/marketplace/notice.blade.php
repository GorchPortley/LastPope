@props(['level' => 'info'])

<div {{ $attributes->class([
    'flex items-start gap-3 border px-4 py-3 text-sm',
    'border-green-300 bg-green-50 text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-200' => $level === 'success',
    'border-blue-300 bg-blue-50 text-blue-800 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-200' => $level === 'info',
    'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200' => $level === 'warning',
    'border-red-300 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200' => $level === 'error',
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
