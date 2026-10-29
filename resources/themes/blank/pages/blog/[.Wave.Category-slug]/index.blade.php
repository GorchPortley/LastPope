<?php
    use function Laravel\Folio\{name};
    name('blog.category');
?>

@php
    $posts = $category->posts()->published()->with(['category', 'user'])->latest()->paginate(9);
@endphp

<x-layouts.marketing
    :seo="[
        'title' => $category->name.' - Blog - '.config('app.name'),
        'description' => 'Posts about '.$category->name.' on the '.config('app.name').' blog.',
    ]">
    <x-marketplace.page :crumbs="[['Home', url('/')], ['Blog', route('blog')], [$category->name, null]]">
        <h1 class="text-3xl font-bold text-gray-900">{{ $category->name }}</h1>
        <p class="mt-2 text-lg text-gray-600 italic">The latest {{ $category->name }} posts.</p>

        <div class="mt-6">
            <x-marketing.blog-categories :active="$category->slug" />
        </div>

        @if ($posts->isEmpty())
            <x-marketplace.empty-state heading="No posts in {{ $category->name }} yet" description="Have a look at the other categories in the meantime." icon="phosphor-newspaper" class="mt-8" />
        @else
            <div class="mt-8 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                <x-marketing.posts-loop :posts="$posts" />
            </div>

            <div class="flex justify-center my-10">
                {{ $posts->links('theme::partials.pagination') }}
            </div>
        @endif
    </x-marketplace.page>
</x-layouts.marketing>
