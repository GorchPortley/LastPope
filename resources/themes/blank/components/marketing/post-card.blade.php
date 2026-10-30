{{--
    One blog post as a card, in the style of the design and listing cards: picture on top (the woofer placeholder when
    the post has none), category pill, title, teaser, author and date. Eager-load category and user for a grid.
--}}
@props(['post'])

<article {{ $attributes->class(['group relative flex h-full flex-col overflow-hidden border border-rule bg-white transition-colors hover:border-ink']) }} id="post-{{ $post->id }}">
    <div class="relative aspect-video overflow-hidden bg-hair">
        <img src="{{ filled($post->image) ? \App\Support\Thumbnails::url($post->image, 480) : $post->image() }}" @if (filled($post->image)) srcset="{{ \App\Support\Thumbnails::srcset($post->image, [480, 960]) }}" sizes="(min-width: 1024px) 400px, (min-width: 640px) 50vw, 100vw" @endif alt="" loading="lazy" decoding="async" class="h-full w-full object-cover transition duration-300 group-hover:scale-105">
        @if ($post->category)
            <span class="sd-pill absolute left-2.5 top-2.5 max-w-[70%] truncate">{{ $post->category->name }}</span>
        @endif
    </div>

    <div class="flex flex-1 flex-col p-4">
        <h2 class="font-condensed text-[21px] font-bold leading-tight text-ink line-clamp-2">
            <a href="{{ $post->link() }}" class="focus:outline-none">
                <span class="absolute inset-0" aria-hidden="true"></span>
                {{ $post->title }}
            </a>
        </h2>
        <p class="mt-2 text-sm leading-6 text-ink-2 line-clamp-3">{{ $post->teaser() }}</p>

        <p class="mt-auto pt-4 text-xs text-ink-3">
            @if ($post->user){{ $post->user->name }} &middot; @endif
            <time datetime="{{ $post->created_at?->toIso8601String() }}">{{ $post->created_at?->format('M j, Y') }}</time>
        </p>
    </div>
</article>
