{{--
    The problem-report panel (App\Http\Livewire\OrderIssuePanel): status, what happens next, the offer / return cards,
    the thread and the reply form. Light theme like the other marketplace dashboard pages (Filament sections).
--}}
@php
    use App\Enums\IssueResolution;
    use App\Enums\IssueStatus;

    $order = $issue->order;
    $currency = $order->currency;
    $isBuyer = $issue->isBuyer($user);
    $isSeller = $issue->isSeller($user);
    $isAdmin = $user->isPlatformSeller();
    $app = config('app.name');

    $can = fn (string $action): bool => $service->can($user, $issue, $action);

    $name = function (?\App\Models\User $person) use ($user, $app): string {
        if ($person === null) {
            return $app;
        }
        if ($person->is($user)) {
            return 'You';
        }

        return $person->sellerProfile?->shop_name ?: $person->name;
    };

    // [label, colour] of the author of a message
    $role = function (?\App\Models\User $person) use ($issue): array {
        return match (true) {
            $person === null => ['System', 'gray'],
            $issue->isBuyer($person) => ['Buyer', 'info'],
            $issue->isSeller($person) => [$issue->seller?->isPlatformSeller() ? config('app.name') : 'Seller', 'primary'],
            default => [config('app.name').' team', 'warning'],
        };
    };

    $eventLabels = [
        'offer' => 'Offer', 'offer_accepted' => 'Offer accepted', 'offer_declined' => 'Offer declined',
        'return_tracking' => 'Return tracking', 'return_received' => 'Goods received', 'escalated' => 'Escalated',
        'withdrawn' => 'Withdrawn', 'resolved' => 'Resolved', 'dispute' => 'Bank dispute', 'recovered' => 'Recovered',
    ];

    $status = $issue->status;
    $offerLabel = $issue->offerLabel();
    $escalateDate = $issue->escalatableAt();
    $negotiating = in_array($status, [IssueStatus::OPEN, IssueStatus::OFFER_PENDING, IssueStatus::AWAITING_RETURN], true);

    // What the person should do (or wait for) next, by role.
    $next = match (true) {
        $status === IssueStatus::RESOLVED => null,
        $status === IssueStatus::ESCALATED && $issue->isDispute() => $isAdmin
            ? ['warning', 'A bank dispute is open on this order. Send your evidence in the Stripe dashboard before the due date; Stripe tells us the outcome.']
            : ['warning', 'The buyer\'s bank opened a dispute on this order. The payout is on hold until it is decided.'],
        $status === IssueStatus::ESCALATED => $isAdmin
            ? ['warning', 'Buyer and seller could not agree. Read the thread and decide: full refund, partial refund or no action.']
            : ['warning', 'The '.$app.' team is looking at this case and will decide. You can keep adding messages and photos here.'],
        $status === IssueStatus::OFFER_PENDING && $isBuyer => ['info', 'The seller made you an offer. Accept it to settle this, or decline and keep talking.'],
        $status === IssueStatus::OFFER_PENDING && $isSeller => ['info', 'Waiting for the buyer to answer your offer.'],
        $status === IssueStatus::AWAITING_RETURN && $isBuyer => filled($issue->return_tracking_number)
            ? ['info', 'You sent the goods back. The seller refunds you as soon as they arrive.']
            : ['info', 'Send the goods back, then add the tracking number so the seller can follow the parcel.'],
        $status === IssueStatus::AWAITING_RETURN && $isSeller => filled($issue->return_tracking_number)
            ? ['info', 'The buyer says the goods are on their way. Confirm when they arrive and the refund is paid.']
            : ['info', 'Waiting for the buyer to send the goods back.'],
        $status === IssueStatus::OPEN && $isSeller => ['warning', 'Your payout for this order is on hold until this is resolved. Answer the buyer or make an offer.'],
        $status === IssueStatus::OPEN && $isBuyer => ['info', 'The seller has been notified. They can answer here or make you an offer.'],
        default => ['info', 'Buyer and seller are talking. The case moves to the '.$app.' team if they cannot agree.'],
    };

    $files = fn ($message) => $message->files();
@endphp

<div class="space-y-4" data-issue-panel="{{ $issue->getKey() }}" data-issue-status="{{ $status->value }}">
    <x-filament::section>
        <x-slot name="heading">Problem: {{ $issue->reason->label() }}</x-slot>
        <x-slot name="description">
            Reported {{ $issue->created_at->format('M j, Y') }}
            @if ($issue->resolved_at)
                &middot; resolved {{ $issue->resolved_at->format('M j, Y') }}
            @endif
        </x-slot>
        <x-slot name="headerEnd">
            <div class="flex flex-wrap items-center justify-end gap-2">
                @if ($issue->isDispute())
                    <x-marketplace.badge color="danger">Bank dispute</x-marketplace.badge>
                @endif
                <x-marketplace.badge :color="$status->color()" data-issue-badge>{{ $status->label() }}</x-marketplace.badge>
                @if ($issue->resolution)
                    <x-marketplace.badge :color="$issue->resolution->color()">{{ $issue->resolution->label() }}</x-marketplace.badge>
                @endif
            </div>
        </x-slot>

        <div class="space-y-4">
            @if ($next)
                <div @class([
                    'flex items-start gap-3 rounded-lg px-4 py-3 text-sm',
                    'bg-blue-50 text-blue-900' => $next[0] === 'info',
                    'bg-amber-50 text-amber-900' => $next[0] === 'warning',
                ]) data-next-step>
                    @if ($next[0] === 'warning')
                        <x-phosphor-warning class="mt-0.5 h-5 w-5 flex-shrink-0" />
                    @else
                        <x-phosphor-info class="mt-0.5 h-5 w-5 flex-shrink-0" />
                    @endif
                    <p class="min-w-0">{{ $next[1] }}</p>
                </div>
            @elseif ($issue->resolution)
                <div class="flex items-start gap-3 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-900">
                    <x-phosphor-check-circle class="mt-0.5 h-5 w-5 flex-shrink-0" />
                    <p class="min-w-0">
                        Resolved: <strong>{{ $issue->resolution->label() }}</strong>.
                        @if ($issue->refunded_cents > 0)
                            {{ money($issue->refunded_cents, $currency) }} {{ $isBuyer ? 'was refunded to you' : 'was refunded to the buyer' }}.
                        @endif
                        @if ($issue->recovered_at)
                            {{ money($issue->recovered_cents, $currency) }} was recovered from the seller's payout.
                        @elseif ($issue->resolution === IssueResolution::CHARGEBACK_LOST && $isAdmin)
                            Decide whether to recover the money from the seller (see the actions of this case).
                        @endif
                    </p>
                </div>
            @endif

            {{-- the seller's offer --}}
            @if ($issue->hasPendingOffer())
                <div class="rounded-lg border-2 border-gray-100 p-4" data-offer>
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $isBuyer ? 'The seller\'s offer' : 'Your offer' }}</p>
                    <p class="mt-1 text-lg font-semibold text-gray-950">{{ ucfirst($offerLabel) }}</p>
                    @if ($issue->offer_type === IssueResolution::RETURN_REFUND)
                        <p class="mt-1 text-sm text-gray-600">You send the goods back, then {{ money($issue->refundableCents(), $currency) }} is refunded to you.</p>
                    @else
                        <p class="mt-1 text-sm text-gray-600">You keep the goods. It is paid straight away when you accept.</p>
                    @endif
                    <div class="mt-3 flex flex-wrap gap-2">
                        @if ($can('acceptOffer'))
                            {{ $this->acceptAction }}
                            {{ $this->declineAction }}
                        @endif
                        @if ($can('makeOffer'))
                            {{ $this->offerAction }}
                        @endif
                    </div>
                </div>
            @endif

            {{-- the return --}}
            @if ($status === IssueStatus::AWAITING_RETURN)
                <div class="rounded-lg border-2 border-gray-100 p-4" data-return>
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Return for a refund</p>
                    @if (filled($issue->return_tracking_number))
                        <p class="mt-1 text-sm text-gray-800">
                            <x-phosphor-truck class="mr-1 inline h-4 w-4 align-text-bottom" />
                            Sent back {{ $issue->return_shipped_at?->format('M j') }} with <strong>{{ $issue->return_carrier }}</strong>,
                            tracking <span class="font-mono">{{ $issue->return_tracking_number }}</span>
                            @if (filled($issue->return_tracking_url))
                                &middot; <a href="{{ $issue->return_tracking_url }}" target="_blank" rel="noopener nofollow" class="font-medium text-brand-700 underline hover:text-brand-900">Track parcel</a>
                            @endif
                        </p>
                    @else
                        <p class="mt-1 text-sm text-gray-600">No tracking yet.</p>
                    @endif
                    @if ($can('addReturnTracking') || $can('confirmReturnReceived'))
                        <div class="mt-3 flex flex-wrap gap-2">
                            @if ($can('addReturnTracking'))
                                {{ $this->returnTrackingAction }}
                            @endif
                            @if ($can('confirmReturnReceived'))
                                {{ $this->confirmReturnAction }}
                            @endif
                        </div>
                    @endif
                </div>
            @endif

            {{-- actions that are not tied to a card --}}
            @php
                $showOffer = $can('makeOffer') && ! $issue->hasPendingOffer();
                $showEscalate = $can('escalate');
                $showWithdraw = $can('withdraw');
                $waitForEscalation = ($isBuyer || $isSeller) && $negotiating && ! $showEscalate;
            @endphp
            @if ($showOffer || $showEscalate || $showWithdraw || $waitForEscalation)
                <div class="flex flex-wrap items-center gap-2" data-issue-actions>
                    @if ($showOffer)
                        {{ $this->offerAction }}
                    @endif
                    @if ($showEscalate)
                        {{ $this->escalateAction }}
                    @endif
                    @if ($showWithdraw)
                        {{ $this->withdrawAction }}
                    @endif
                    @if ($waitForEscalation)
                        <p class="text-sm text-gray-500">
                            Not agreed? From {{ $escalateDate->format('M j') }} you can ask {{ $app }} to decide.
                        </p>
                    @endif
                </div>
            @endif

            {{-- the thread --}}
            <div>
                <h3 class="mb-2 text-sm font-semibold text-gray-950">Conversation</h3>
                <ol class="space-y-3" data-thread>
                    @foreach ($issue->messages as $message)
                        @continue($message->internal && ! $isAdmin)

                        @if ($message->author_id === null)
                            <li class="flex items-start gap-2 px-1 text-sm text-gray-500" data-message="system">
                                <x-phosphor-info class="mt-0.5 h-4 w-4 flex-shrink-0" />
                                <span class="min-w-0 flex-1 whitespace-pre-line">{{ $message->body }}</span>
                                <time class="flex-shrink-0 text-xs" datetime="{{ $message->created_at->toIso8601String() }}">{{ $message->created_at->format('M j, H:i') }}</time>
                            </li>
                        @else
                            @php([$roleLabel, $roleColor] = $role($message->author))
                            <li @class([
                                'rounded-lg border p-3',
                                'border-gray-200 bg-white' => ! $message->internal,
                                'border-amber-200 bg-amber-50' => $message->internal,
                            ]) data-message="{{ $message->internal ? 'internal' : 'public' }}">
                                <div class="flex flex-wrap items-center gap-2 text-sm">
                                    <span class="font-semibold text-gray-950">{{ $name($message->author) }}</span>
                                    <x-marketplace.badge :color="$roleColor">{{ $roleLabel }}</x-marketplace.badge>
                                    @if ($message->internal)
                                        <x-marketplace.badge color="warning">Internal note</x-marketplace.badge>
                                    @elseif ($message->event && isset($eventLabels[$message->event]) && $message->event !== 'opened')
                                        <x-marketplace.badge>{{ $eventLabels[$message->event] }}</x-marketplace.badge>
                                    @endif
                                    <time class="ml-auto text-xs text-gray-500" datetime="{{ $message->created_at->toIso8601String() }}">{{ $message->created_at->format('M j, H:i') }}</time>
                                </div>
                                <p class="mt-1 whitespace-pre-line text-sm text-gray-800">{{ $message->body }}</p>
                                @if ($files($message) !== [])
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        @foreach ($files($message) as $index => $file)
                                            <a href="{{ route('dashboard.issues.attachment', ['orderIssue' => $issue->getKey(), 'message' => $message->getKey(), 'index' => $index]) }}" target="_blank" rel="noopener"
                                               class="block h-20 w-20 overflow-hidden rounded-lg border border-gray-200 bg-gray-50" title="{{ $file['name'] }}">
                                                <img src="{{ route('dashboard.issues.attachment', ['orderIssue' => $issue->getKey(), 'message' => $message->getKey(), 'index' => $index]) }}" alt="{{ $file['name'] }}" loading="lazy" class="h-full w-full object-cover">
                                            </a>
                                        @endforeach
                                    </div>
                                @endif
                            </li>
                        @endif
                    @endforeach
                </ol>
            </div>

            {{-- reply --}}
            @if ($can('reply') || $isAdmin)
                <form wire:submit="send" class="space-y-3 border-t border-gray-100 pt-4" data-reply-form>
                    {{ $this->form }}
                    <div class="flex flex-wrap items-center gap-3">
                        <x-filament::button type="submit" wire:target="send">Send</x-filament::button>
                        @if (! $can('reply'))
                            <span class="text-sm text-gray-500">This case is closed. You can still add internal notes.</span>
                        @endif
                    </div>
                </form>
            @endif
        </div>
    </x-filament::section>

    <x-filament-actions::modals />
</div>
