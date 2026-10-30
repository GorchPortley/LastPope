{{--
    The last two sheets of the design page: Reviews (with the form) and Discussion (the forum thread).
    The other sections (response, about, drivers, parts, files, builds) are drawn by the page itself.
--}}
@php
    $forum = app(\App\Services\Forum\ForumClient::class);
    $thread = $design->forum_slug && $forum->enabled();
@endphp

<div>
    <x-design.section id="reviews" title="Reviews" data-design-reviews>
        <x-slot:header>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                @if ($designRating)
                    <span class="inline-flex items-center gap-2 text-sm text-ink-2">
                        <span class="sd-stars" style="--p: {{ round($designRating) * 20 }}%" role="img" aria-label="{{ number_format($designRating, 1) }} out of 5"></span>
                        <span class="sd-num text-xs">{{ number_format($designRating, 1) }} ({{ $designReviewQty }})</span>
                    </span>
                @else
                    <span class="text-sm text-ink-3">No ratings yet</span>
                @endif
                @if (Auth::check())
                    <a href="#design-review-form" class="sd-btn sd-btn-sm">Write a Review</a>
                @else
                    <a href="{{ route('login') }}" class="sd-btn sd-btn-sm">Write a Review</a>
                @endif
            </div>
        </x-slot:header>

        <div class="max-w-4xl">
            <div class="mb-8">
                @forelse ($designReviews as $review)
                    <article class="grid gap-1 border-b border-hair py-3 first:pt-0">
                        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                            <span class="sd-stars" style="--p: {{ (int) $review['rating'] * 20 }}%" role="img" aria-label="{{ (int) $review['rating'] }} out of 5"></span>
                            <span class="sd-num text-xs text-ink-3">{{ \Carbon\Carbon::parse($review['created_at'])->format('M d, Y') }}</span>
                        </div>
                        <h3 class="text-base font-semibold text-ink">{{ $review['title'] }}</h3>
                        <p class="whitespace-pre-line text-[15px] !text-ink-2">{{ $review['review'] }}</p>
                        <p class="sd-num text-xs text-ink-3">By {{ $review['author']['name'] ?? 'Anonymous' }}</p>
                    </article>
                @empty
                    <p class="text-sm text-ink-3">No reviews yet. Be the first to review!</p>
                @endforelse
            </div>

            @if (Auth::check())
                <div id="design-review-form" class="scroll-mt-16 border border-rule bg-paper p-4">
                    <h3 class="sd-title mb-3 text-lg">Write a Review</h3>

                    @if (session()->has('message'))
                        <div class="mb-4 border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-800">
                            {{ session('message') }}
                        </div>
                    @endif

                    <form wire:submit.prevent="submitReview" class="grid gap-4">
                        <div>
                            <label for="reviewTitle" class="sd-label mb-1.5 block">Review Title</label>
                            <input wire:model.defer="reviewTitle" type="text" id="reviewTitle" class="block w-full rounded-none border-rule bg-white p-2.5 text-sm text-ink focus:border-ink focus:ring-0" placeholder="Summarize your experience" required>
                            @error('reviewTitle') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <span class="sd-label mb-1.5 block" id="reviewRatingLabel">Rating</span>
                            <div class="flex items-center gap-1" role="group" aria-labelledby="reviewRatingLabel" x-data="{ temporaryRating: @entangle('reviewRating') }">
                                @for ($i = 1; $i <= 5; $i++)
                                    <button
                                        type="button"
                                        wire:click="$set('reviewRating', {{ $i }})"
                                        @click="temporaryRating = {{ $i }}"
                                        aria-label="{{ $i }} {{ $i === 1 ? 'star' : 'stars' }}"
                                        class="focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent-500">
                                        <svg class="!w-8 h-8" :class="{{ $i }} <= temporaryRating ? 'text-accent-500' : 'text-hair'" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                                        </svg>
                                    </button>
                                @endfor
                            </div>
                            @error('reviewRating') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label for="reviewContent" class="sd-label mb-1.5 block">Review Content</label>
                            <textarea wire:model.defer="reviewContent" id="reviewContent" rows="4" class="block w-full rounded-none border-rule bg-white p-2.5 text-sm text-ink focus:border-ink focus:ring-0" placeholder="Share your experience with this design" required></textarea>
                            @error('reviewContent') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <button type="submit" class="sd-btn sd-btn-go">Submit Review</button>
                        </div>
                    </form>
                </div>
            @else
                <div class="border border-rule bg-paper p-4">
                    <p class="mb-3 text-sm text-ink-2">You need to be logged in to write a review.</p>
                    <a href="{{ route('login') }}" class="sd-btn sd-btn-go">Log in to write a review</a>
                </div>
            @endif
        </div>
    </x-design.section>

    <x-design.section id="discussion" title="Discussion" data-design-discussion>
        @if ($thread)
            @if ($this->canSeeDiscussion)
                <iframe src="{{ $forum->embedUrl($design->forum_slug) }}" title="Discussion of {{ $design->name }}" loading="lazy"
                        class="block h-[720px] w-full border border-hair max-sm:h-[600px]"
                        x-data
                        x-init="if ('IntersectionObserver' in window) { const seen = new IntersectionObserver((entries) => { if (entries.some((e) => e.isIntersecting)) { $dispatch('ga4', {name: 'forum_open', params: {content_type: 'design', item_id: 'design-{{ $design->getKey() }}'}}); seen.disconnect(); } }, {threshold: 0.2}); seen.observe($el); }"></iframe>
            @else
                <p class="text-sm text-ink-2" data-discussion-locked>The discussion for this design is open to buyers. {{ auth()->check() ? 'Buy this design to join it.' : 'Log in to buy this design and join it.' }}</p>
            @endif
        @else
            <p class="text-sm text-ink-3">No discussion data available</p>
        @endif
    </x-design.section>
</div>
