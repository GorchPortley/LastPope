<?php
    use function Laravel\Folio\{name};
    use Livewire\Volt\Component;
    name('blog');

    new class extends Component
    {
        public function with(): array
        {
            return [
                'posts' => \Wave\Post::published()->with(['category', 'user'])->latest()->paginate(9),
                'categories' => \Wave\Category::orderBy('name')->get(),
            ];
        }
    }
?>

<x-layouts.marketing
    :seo="[
        'title' => 'Blog - '.config('app.name'),
        'description' => 'News, guides and build stories from '.config('app.name').'.',
    ]">
    @volt('blog')
    <x-marketplace.page :crumbs="[['Home', url('/')], ['Blog', null]]">
        <h1 class="text-3xl font-bold text-gray-900">From the blog</h1>
        <p class="mt-2 text-lg text-gray-600 italic">News, guides and build stories.</p>

        <div class="mt-6">
            <x-marketing.blog-categories :categories="$categories" />
        </div>

        @if ($posts->isEmpty())
            <x-marketplace.empty-state heading="Nothing here yet" description="The first posts are on their way." icon="phosphor-newspaper" class="mt-8" />
        @else
            <div class="mt-8 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                <x-marketing.posts-loop :posts="$posts" />
            </div>

            <div class="flex justify-center my-10">
                {{ $posts->links('theme::partials.pagination') }}
            </div>
        @endif
    </x-marketplace.page>
    @endvolt
</x-layouts.marketing>
