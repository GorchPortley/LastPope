<?php
    use function Laravel\Folio\{name};
    name('blog.post');
?>

@php
    // Drafts are for admins only (they preview them here); everyone else gets a 404.
    abort_unless($post->status === 'PUBLISHED' || auth()->user()?->hasRole('admin'), 404);

    $words = str_word_count(strip_tags((string) $post->body));
    $more = \Wave\Post::published()->whereKeyNot($post->getKey())
        ->when($post->category_id, fn ($q) => $q->where('category_id', $post->category_id))
        ->with(['category', 'user'])->latest()->take(3)->get();
@endphp

<x-layouts.marketing
    :seo="[
        'title' => ($post->seo_title ?: $post->title).' - '.config('app.name'),
        'description' => $post->meta_description ?: $post->teaser(),
        'image' => $post->image(),
    ]">
    <x-marketplace.page :crumbs="array_values(array_filter([
        ['Home', url('/')],
        ['Blog', route('blog')],
        $post->category ? [$post->category->name, route('blog.category', ['category' => $post->category])] : null,
        [$post->title, null],
    ]))">
        <article id="post-{{ $post->id }}" class="mx-auto max-w-3xl pb-12">
            @if ($post->status !== 'PUBLISHED')
                <p class="mb-4"><x-marketplace.badge color="warning">{{ ucfirst(strtolower($post->status)) }}: only admins can see this</x-marketplace.badge></p>
            @endif

            @if ($post->category)
                <a href="{{ route('blog.category', ['category' => $post->category]) }}" class="sd-pill inline-block no-underline hover:bg-brand-800">{{ $post->category->name }}</a>
            @endif

            <h1 class="sd-title mt-3 text-[clamp(32px,5vw,46px)] leading-[0.98]">{{ $post->title }}</h1>

            <p class="mt-3 text-[15px] text-ink-2">
                @if ($post->user)By {{ $post->user->name }} &middot; @endif
                <time datetime="{{ $post->created_at?->toIso8601String() }}">{{ $post->created_at?->format('F j, Y') }}</time>
                &middot; {{ max(1, (int) round($words / 220)) }} min read
            </p>

            <img src="{{ $post->image() }}" alt="{{ $post->title }}" class="mt-6 aspect-video w-full border border-rule bg-hair object-cover">

            {{-- the same text styles as design and driver pages (.sd-rich, app.css) --}}
            <div class="sd-sheet mt-6 px-5 py-6 sm:px-8 sm:py-8">
                <div class="sd-rich" data-post-body>
                    {!! $post->body !!}
                </div>
            </div>
        </article>

        @if ($more->isNotEmpty())
            <x-marketplace.section title="More from the blog" id="more-posts">
                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    <x-marketing.posts-loop :posts="$more" />
                </div>
            </x-marketplace.section>
        @endif
    </x-marketplace.page>
</x-layouts.marketing>
