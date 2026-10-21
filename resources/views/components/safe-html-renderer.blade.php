{{-- resources/views/components/safe-html-renderer.blade.php: $html is already sanitised by SafeHtmlRenderer. --}}
@if ($html !== '')
    <div {{ $attributes->merge(['class' => 'safe-html-content prose max-w-none dark:prose-invert']) }}>
        {!! $html !!}
    </div>
@endif
