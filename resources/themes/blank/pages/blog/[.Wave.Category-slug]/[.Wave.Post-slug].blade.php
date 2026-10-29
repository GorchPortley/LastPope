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
                <a href="{{ route('blog.category', ['category' => $post->category]) }}" class="inline-block rounded-full bg-brand-950 px-2 py-1 text-xs font-semibold text-white hover:bg-brand-800">{{ $post->category->name }}</a>
            @endif

            <h1 class="mt-3 text-3xl font-bold leading-tight text-gray-900 sm:text-4xl">{{ $post->title }}</h1>

            <p class="mt-3 text-gray-600 italic">
                @if ($post->user)By {{ $post->user->name }} &middot; @endif
                <time datetime="{{ $post->created_at?->toIso8601String() }}">{{ $post->created_at?->format('F j, Y') }}</time>
                &middot; {{ max(1, (int) round($words / 220)) }} min read
            </p>

            <img src="{{ $post->image() }}" alt="{{ $post->title }}" class="mt-6 aspect-video w-full rounded-lg border-2 border-gray-100 bg-brand-950 object-cover shadow-sm">

            <div class="prose prose-lg mt-8 max-w-none prose-headings:font-semibold prose-headings:text-gray-900 prose-a:text-brand-700 hover:prose-a:text-brand-900 prose-img:rounded-lg prose-blockquote:border-l-accent-400">
                {!! $post->body !!}
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
