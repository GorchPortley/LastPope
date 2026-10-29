{{-- The category tabs above the blog grid ("All" plus every category), in the tab style of the design page. --}}
@props(['categories' => null, 'active' => null])

@php
    $categories ??= \Wave\Category::orderBy('name')->get();
    $items = ['all' => ['All posts', route('blog')]];
    foreach ($categories as $cat) {
        $items[$cat->slug] = [$cat->name, route('blog.category', ['category' => $cat])];
    }
@endphp

<x-marketplace.tabs :items="$items" :active="$active ?? 'all'" label="Blog categories" />
