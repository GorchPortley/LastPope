{{--
    One blog post as a card, in the style of the design and listing cards: picture on top (the woofer placeholder when
    the post has none), category pill, title, teaser, author and date. Eager-load category and user for a grid.
--}}
@props(['post'])

<article {{ $attributes->class(['group relative flex h-full flex-col overflow-hidden rounded-lg border-2 border-gray-100 bg-white shadow-sm hover:shadow-lg']) }} id="post-{{ $post->id }}">
    <div class="relative aspect-video overflow-hidden bg-brand-950">
        <img src="{{ filled($post->image) ? \App\Support\Thumbnails::url($post->image, 480) : $post->image() }}" @if (filled($post->image)) srcset="{{ \App\Support\Thumbnails::srcset($post->image, [480, 960]) }}" sizes="(min-width: 1024px) 400px, (min-width: 640px) 50vw, 100vw" @endif alt="" loading="lazy" decoding="async" class="h-full w-full object-cover transition duration-300 group-hover:scale-105">
        @if ($post->category)
            <span class="absolute left-3 top-3 max-w-[70%] truncate rounded-full bg-brand-950 px-2 py-1 text-xs font-semibold text-white">{{ $post->category->name }}</span>
        @endif
    </div>

    <div class="flex flex-1 flex-col p-4">
        <h2 class="text-lg font-semibold leading-snug text-gray-900 line-clamp-2">
            <a href="{{ $post->link() }}" class="focus:outline-none">
                <span class="absolute inset-0" aria-hidden="true"></span>
                {{ $post->title }}
            </a>
        </h2>
        <p class="mt-2 text-sm leading-6 text-gray-600 line-clamp-3">{{ $post->teaser() }}</p>

        <p class="mt-auto pt-4 text-xs text-gray-500">
            @if ($post->user){{ $post->user->name }} &middot; @endif
            <time datetime="{{ $post->created_at?->toIso8601String() }}">{{ $post->created_at?->format('M j, Y') }}</time>
        </p>
    </div>
</article>
