{{-- The cards of a list of posts; the caller supplies the grid. --}}
@props(['posts'])

@foreach ($posts as $post)
    <x-marketing.post-card :post="$post" />
@endforeach
