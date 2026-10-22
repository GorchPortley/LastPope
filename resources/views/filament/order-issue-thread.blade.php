{{-- The conversation of a problem report in the admin panel, internal notes included. $getRecord() is the OrderIssue. --}}
@php
    $issue = $getRecord()->loadMissing(['messages.author.sellerProfile', 'buyer', 'seller']);
    $eventLabels = [
        'opened' => 'Reported', 'offer' => 'Offer', 'offer_accepted' => 'Offer accepted', 'offer_declined' => 'Offer declined',
        'return_tracking' => 'Return tracking', 'return_received' => 'Goods received', 'escalated' => 'Escalated',
        'withdrawn' => 'Withdrawn', 'resolved' => 'Resolved', 'dispute' => 'Bank dispute', 'recovered' => 'Recovered',
    ];
@endphp

<ol class="space-y-3 text-sm" data-thread>
    @forelse ($issue->messages as $message)
        @php
            $author = $message->author;
            $role = match (true) {
                $author === null => 'System',
                $issue->isBuyer($author) => 'Buyer',
                $issue->isSeller($author) => $issue->seller?->isPlatformSeller() ? config('app.name') : 'Seller',
                default => config('app.name').' team',
            };
        @endphp
        @if ($author === null)
            <li class="flex items-start gap-2 text-gray-500 dark:text-gray-400" data-message="system">
                <span class="min-w-0 flex-1 whitespace-pre-line">{{ $message->body }}</span>
                <time class="flex-shrink-0 text-xs">{{ $message->created_at->format('M j, H:i') }}</time>
            </li>
        @else
            <li @class([
                'rounded-lg border p-3',
                'border-gray-200 dark:border-white/10' => ! $message->internal,
                'border-amber-300 bg-amber-50 dark:border-amber-500/40 dark:bg-amber-500/10' => $message->internal,
            ]) data-message="{{ $message->internal ? 'internal' : 'public' }}">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-semibold text-gray-950 dark:text-white">{{ $author->sellerProfile?->shop_name && $issue->isSeller($author) ? $author->sellerProfile->shop_name : $author->name }}</span>
                    <span class="rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 dark:bg-white/10 dark:text-gray-300">{{ $role }}</span>
                    @if ($message->internal)
                        <span class="rounded-md bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">Internal note</span>
                    @elseif ($message->event && isset($eventLabels[$message->event]) && $message->event !== 'opened')
                        <span class="rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 dark:bg-white/10 dark:text-gray-300">{{ $eventLabels[$message->event] }}</span>
                    @endif
                    <time class="ml-auto text-xs text-gray-500">{{ $message->created_at->format('M j, Y H:i') }}</time>
                </div>
                <p class="mt-1 whitespace-pre-line text-gray-800 dark:text-gray-200">{{ $message->body }}</p>
                @if ($message->files() !== [])
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($message->files() as $index => $file)
                            @php($url = route('dashboard.issues.attachment', ['orderIssue' => $issue->getKey(), 'message' => $message->getKey(), 'index' => $index]))
                            <a href="{{ $url }}" target="_blank" rel="noopener" class="block h-20 w-20 overflow-hidden rounded-lg border border-gray-200 dark:border-white/10" title="{{ $file['name'] }}">
                                <img src="{{ $url }}" alt="{{ $file['name'] }}" loading="lazy" class="h-full w-full object-cover">
                            </a>
                        @endforeach
                    </div>
                @endif
            </li>
        @endif
    @empty
        <li class="text-gray-500">No messages yet.</li>
    @endforelse
</ol>
