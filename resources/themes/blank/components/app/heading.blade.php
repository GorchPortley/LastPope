@props([
    'title' => '',
    'description' => '',
    'border' => true
])

<div class="@if($border){{ 'pb-4 border-b border-rule' }}@endif space-y-1">
    <h3 class="text-xl font-semibold tracking-tight text-ink">{{ $title ?? '' }}</h3>
    <p class="text-sm text-ink-3">{{ $description ?? '' }}</p>
</div>
