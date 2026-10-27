@props(['upcoming', 'note' => null])

{{-- The payouts a seller has not been paid yet (SellerEarnings::upcoming(): seller order + PayoutService::outlook()). Used by the selling
     dashboard and the Earnings page. --}}
<p class="text-sm text-gray-600">{{ $note }}</p>

@if ($upcoming->isEmpty())
    <p class="mt-4 text-sm text-gray-500">Nothing is waiting to be paid out.</p>
@else
    <ul class="mt-4 divide-y divide-gray-100">
        @foreach ($upcoming as $row)
            @php
                $so = $row['sellerOrder'];
                $outlook = $row['outlook'];
            @endphp
            <li class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm" data-payout-row="{{ $so->getKey() }}" data-payout-state="{{ $outlook['state'] }}">
                <div class="min-w-0">
                    <p class="font-medium text-gray-950">{{ $so->order->number }} <span class="font-normal text-gray-500">&middot; {{ $so->items->first()?->title }}{{ $so->items->count() > 1 ? ' + '.($so->items->count() - 1).' more' : '' }}</span></p>
                    <p class="text-gray-500">
                        @switch($outlook['state'])
                            @case('held')
                                On hold until the problem reported by the buyer is resolved.
                                @if ($so->activeIssue)
                                    <a href="{{ $so->activeIssue->url() }}" class="font-medium text-brand-700 underline hover:text-brand-900">Open the problem</a>
                                @endif
                                @break
                            @case('scheduled')
                                @if ($outlook['at']->isPast())
                                    Due now: it is released within the hour.
                                @else
                                    Released on {{ $outlook['at']->format('M j, Y') }} unless the buyer reports a problem.
                                @endif
                                @break
                            @default Released after you mark the parcel as shipped.
                        @endswitch
                    </p>
                </div>
                <div class="flex flex-shrink-0 items-center gap-3">
                    @if ($outlook['state'] === 'held')
                        <x-marketplace.badge color="danger">On hold</x-marketplace.badge>
                    @elseif ($outlook['state'] === 'scheduled')
                        <x-marketplace.badge color="info">{{ $outlook['at']->isPast() ? 'Due now' : $outlook['at']->format('M j') }}</x-marketplace.badge>
                    @else
                        <x-marketplace.badge color="warning">After shipping</x-marketplace.badge>
                    @endif
                    <span class="font-semibold text-gray-950">{{ money($so->payout_cents, $so->order->currency) }}</span>
                </div>
            </li>
        @endforeach
    </ul>
@endif
