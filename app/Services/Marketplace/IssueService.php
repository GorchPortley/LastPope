<?php

namespace App\Services\Marketplace;

use App\Enums\IssueReason;
use App\Enums\IssueResolution;
use App\Enums\IssueStatus;
use App\Enums\SellerOrderStatus;
use App\Exceptions\Marketplace\InvalidOrderStateException;
use App\Models\Order;
use App\Models\OrderIssue;
use App\Models\OrderIssueMessage;
use App\Models\SellerOrder;
use App\Models\User;
use App\Notifications\DisputeOpened;
use App\Notifications\IssueEscalated;
use App\Notifications\IssueMessagePosted;
use App\Notifications\IssueOfferAnswered;
use App\Notifications\IssueOfferMade;
use App\Notifications\IssueOpened;
use App\Notifications\IssueResolved;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * "Report a problem" (docs/PLAN.md 7, R2.2): the whole life of an OrderIssue.
 *
 *   open()                 buyer reports a problem with one seller's part of an order (reason, text, up to 5 photos).
 *                          SDLabs' own (store) orders go straight to ESCALATED: the platform is the seller
 *   reply() / internalNote()   the thread; admins may also write notes only admins see
 *   makeOffer()            seller: full refund, partial refund (amount) or return for a refund
 *   acceptOffer() / declineOffer()   buyer; accepting a refund runs it at once, accepting a return waits for the goods
 *   addReturnTracking() / confirmReturnReceived()   buyer sends the goods back, seller confirms: full refund
 *   withdraw()             buyer gives up
 *   escalate()             buyer or seller, once issue_escalation_days passed without agreement: admins decide
 *   adminResolve()         admin: full refund, partial refund or no action
 *   recordDispute()        Stripe bank dispute opened / closed (webhook): ESCALATED issue per seller order, payouts held
 *   recoverFromSeller()    admin: take a lost dispute's money back from the seller
 *
 * Every user action authorizes through OrderIssuePolicy / SellerOrderPolicy (403 AuthorizationException) and then checks
 * the state rules, refusing with InvalidOrderStateException (message safe to show) or ValidationException. All money moves
 * through RefundService, as the system (no acting user is needed there). A refund that Stripe refuses propagates as
 * PaymentGatewayException and leaves the issue as it was. While an issue is unresolved the seller order is not paid out
 * and does not complete by itself (PayoutService / ShipmentService); resolving releases the hold.
 *
 * Notifications never go to the author of an action.
 */
class IssueService
{
    public function __construct(
        private readonly RefundService $refunds,
        private readonly PayoutService $payouts,
    ) {
    }

    // --- Rules the UI needs ----------------------------------------------------------------------------------------

    /**
     * Until when the buyer can report a problem with this seller order: issue_window_days after shipping (physical)
     * or after the payment (digital). Null = no end yet: a physical order that was not shipped can be reported at any time.
     */
    public function windowEndsAt(SellerOrder $sellerOrder): ?CarbonInterface
    {
        $sellerOrder->loadMissing(['order', 'items']);
        $days = max(0, (int) config('marketplace.issue_window_days', 14));

        if ($sellerOrder->hasPhysicalItems()) {
            return $sellerOrder->shipped_at?->copy()->addDays($days);
        }

        return $sellerOrder->order?->paid_at?->copy()->addDays($days);
    }

    /** Why the buyer cannot report a problem with this seller order right now, or null when they can. */
    public function reportBlocker(SellerOrder $sellerOrder): ?string
    {
        $sellerOrder->loadMissing(['order', 'items']);

        if ($sellerOrder->order === null || ! $sellerOrder->order->isPaid()) {
            return 'This order has not been paid.';
        }

        if ($sellerOrder->status === SellerOrderStatus::REFUNDED || $sellerOrder->refundableCents() <= 0) {
            return 'This part of the order was refunded already.';
        }

        if (! in_array($sellerOrder->status, [
            SellerOrderStatus::AWAITING_SHIPMENT,
            SellerOrderStatus::PARTIALLY_SHIPPED,
            SellerOrderStatus::SHIPPED,
            SellerOrderStatus::DELIVERED,
            SellerOrderStatus::COMPLETED,
        ], true)) {
            return 'A problem cannot be reported for this part of the order.';
        }

        if ($sellerOrder->hasUnresolvedIssue()) {
            return 'There is already an open problem report for this part of the order.';
        }

        $end = $this->windowEndsAt($sellerOrder);
        if ($end !== null && $end->lt(now())) {
            return 'The time to report a problem ended on '.$end->format('F j, Y').'.';
        }

        return null;
    }

    /** The buyer can report a problem with this seller order now (the window is open and nothing is open already). */
    public function canReport(SellerOrder $sellerOrder): bool
    {
        return $this->reportBlocker($sellerOrder) === null;
    }

    /**
     * Whether $user may do $action on $issue right now: the policy (who) and the state rules (when).
     * Actions: reply, internalNote, makeOffer, acceptOffer, declineOffer, addReturnTracking, confirmReturnReceived,
     * withdraw, escalate, decide, recover. The service methods enforce the same rules; the UI uses this to decide
     * which buttons to show.
     */
    public function can(User $user, OrderIssue $issue, string $action): bool
    {
        $ability = match ($action) {
            'acceptOffer', 'declineOffer' => 'respondToOffer',
            default => $action,
        };

        if (! Gate::forUser($user)->allows($ability, $issue)) {
            return false;
        }

        return $this->stateAllows($issue, $action);
    }

    /** The state half of can(): is this action possible for the issue at all. */
    public function stateAllows(OrderIssue $issue, string $action): bool
    {
        return match ($action) {
            'reply' => $issue->isUnresolved(),
            'internalNote' => true,
            'makeOffer' => in_array($issue->status, [IssueStatus::OPEN, IssueStatus::OFFER_PENDING], true) && $issue->sellerOrder?->status !== SellerOrderStatus::REFUNDED,
            'acceptOffer', 'declineOffer' => $issue->hasPendingOffer(),
            'addReturnTracking' => $issue->status === IssueStatus::AWAITING_RETURN,
            'confirmReturnReceived' => $issue->status === IssueStatus::AWAITING_RETURN && filled($issue->return_tracking_number),
            'withdraw' => $issue->isUnresolved() && ! $issue->isDispute(),
            'escalate' => $issue->escalationOpen(),
            'decide' => $issue->isUnresolved(),
            'recover' => $issue->resolution === IssueResolution::CHARGEBACK_LOST
                && $issue->recovered_at === null
                && $issue->seller?->isPlatformSeller() === false,
            default => false,
        };
    }

    // --- Opening ---------------------------------------------------------------------------------------------------

    /**
     * The buyer reports a problem. Photos are validated (images, issue_max_photos of at most issue_photo_max_kb each)
     * and kept on the private disk. The seller is told; a platform seller's (SDLabs store) order goes straight to the admins.
     *
     * @param  array<int, UploadedFile>  $photos
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException  not the buyer of this order
     * @throws \Illuminate\Validation\ValidationException  reason / text / photos are not acceptable
     * @throws InvalidOrderStateException  the window is closed, an issue is open already, the part was refunded...
     */
    public function open(User $buyer, SellerOrder $sellerOrder, IssueReason $reason, string $description, array $photos = []): OrderIssue
    {
        Gate::forUser($buyer)->authorize('reportIssue', $sellerOrder);

        $sellerOrder->loadMissing(['order', 'items', 'seller']);

        Validator::make(
            ['reason' => $reason->value, 'description' => trim($description)],
            [
                'reason' => ['required', 'in:'.implode(',', array_map(fn (IssueReason $r) => $r->value, IssueReason::forSellerOrder($sellerOrder)))],
                'description' => ['required', 'string', 'min:10', 'max:5000'],
            ],
            ['reason.in' => 'That reason does not fit this order.', 'description.min' => 'Please describe the problem in a few words (at least 10 characters).'],
        )->validate();
        $this->validatePhotos($photos);

        $notify = [];
        $stored = [];

        try {
            $issue = DB::transaction(function () use ($buyer, $sellerOrder, $reason, $description, $photos, &$notify, &$stored) {
                /** @var SellerOrder $locked */
                $locked = SellerOrder::query()->whereKey($sellerOrder->getKey())->lockForUpdate()->firstOrFail();
                $locked->setRelation('order', $sellerOrder->order)->setRelation('items', $sellerOrder->items);

                if ($blocker = $this->reportBlocker($locked)) {
                    throw new InvalidOrderStateException($blocker);
                }

                $platformSeller = $sellerOrder->seller?->isPlatformSeller() === true;

                $issue = OrderIssue::create([
                    'order_id' => $locked->order_id,
                    'seller_order_id' => $locked->getKey(),
                    'buyer_id' => $buyer->getKey(),
                    'seller_id' => $locked->seller_id,
                    'reason' => $reason,
                    'status' => $platformSeller ? IssueStatus::ESCALATED : IssueStatus::OPEN,
                    'escalated_at' => $platformSeller ? now() : null,
                ]);

                $stored = $this->storePhotos($issue, $photos);
                $this->message($issue, $buyer, trim($description), 'opened', $stored);

                if ($platformSeller) {
                    $this->message($issue, null, config('app.name').' is the seller of this order, so our team looks at it directly. We will reply here.', 'escalated');
                    $notify[] = [$this->admins($buyer), new IssueEscalated($issue, $buyer)];
                } else {
                    $notify[] = [[$sellerOrder->seller], new IssueOpened($issue)];
                }

                return $issue;
            });
        } catch (\Throwable $e) {
            $this->deleteFiles($stored);

            throw $e;
        }

        $this->send($notify);

        return $issue->fresh(['order', 'sellerOrder', 'buyer', 'seller']);
    }

    // --- The thread ------------------------------------------------------------------------------------------------

    /**
     * Post a message (with optional photos) to an unresolved issue. Buyer, seller and admins; the other people involved
     * are notified (admins too once the issue is escalated).
     *
     * @param  array<int, UploadedFile>  $photos
     */
    public function reply(User $author, OrderIssue $issue, string $body, array $photos = []): OrderIssueMessage
    {
        Gate::forUser($author)->authorize('reply', $issue);

        $body = trim($body);
        Validator::make(['body' => $body], ['body' => [$photos === [] ? 'required' : 'nullable', 'string', 'max:5000']])->validate();
        $this->validatePhotos($photos);

        $notify = [];
        $stored = [];

        try {
            $message = DB::transaction(function () use ($author, $issue, $body, $photos, &$notify, &$stored) {
                $locked = $this->lock($issue);

                if (! $this->stateAllows($locked, 'reply')) {
                    throw new InvalidOrderStateException('This problem has been resolved, so it cannot take new messages.');
                }

                $stored = $this->storePhotos($locked, $photos);
                $message = $this->message($locked, $author, $body !== '' ? $body : 'Added '.count($stored).' '.Str::plural('photo', count($stored)).'.', null, $stored);
                $notify[] = [$this->participants($locked, $author, withAdmins: $locked->isEscalated()), new IssueMessagePosted($locked, $message)];

                return $message;
            });
        } catch (\Throwable $e) {
            $this->deleteFiles($stored);

            throw $e;
        }

        $this->send($notify);

        return $message;
    }

    /** An admin-only note: kept in the thread for the admins, never shown to the buyer or the seller, nobody is notified. */
    public function internalNote(User $admin, OrderIssue $issue, string $body): OrderIssueMessage
    {
        Gate::forUser($admin)->authorize('internalNote', $issue);

        $body = trim($body);
        Validator::make(['body' => $body], ['body' => ['required', 'string', 'max:5000']])->validate();

        return $this->message($issue, $admin, $body, null, [], internal: true);
    }

    // --- Offers ----------------------------------------------------------------------------------------------------

    /**
     * The seller proposes how to settle it: a full refund, a partial refund of $amountCents (items + shipping, less than
     * what is left), or a full refund once the buyer sent the goods back. A new offer replaces a pending one.
     */
    public function makeOffer(User $seller, OrderIssue $issue, IssueResolution $type, ?int $amountCents = null, ?string $note = null): OrderIssue
    {
        Gate::forUser($seller)->authorize('makeOffer', $issue);

        $note = filled($note) ? trim((string) $note) : null;
        Validator::make(['type' => $type->value, 'note' => $note], [
            'type' => ['required', 'in:'.implode(',', array_map(fn (IssueResolution $r) => $r->value, IssueResolution::offerTypes()))],
            'note' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $notify = [];

        $result = DB::transaction(function () use ($seller, $issue, $type, $amountCents, $note, &$notify) {
            $locked = $this->lock($issue);

            if (! $this->stateAllows($locked, 'makeOffer')) {
                throw new InvalidOrderStateException('You cannot make an offer in the current state of this problem.');
            }

            $left = $locked->refundableCents();
            if ($left <= 0) {
                throw new InvalidOrderStateException('There is nothing left to refund on this order.');
            }

            if ($type === IssueResolution::PARTIAL_REFUND) {
                if ($amountCents === null || $amountCents < 1) {
                    throw new InvalidOrderStateException('Enter the amount you want to refund.');
                }
                if ($amountCents >= $left) {
                    throw new InvalidOrderStateException('A partial refund must be less than the '.money($left, $locked->order->currency).' that can be refunded. Offer a full refund instead.');
                }
            } else {
                $amountCents = null;
            }

            if ($type === IssueResolution::RETURN_REFUND && ! $locked->sellerOrder->hasPhysicalItems()) {
                throw new InvalidOrderStateException('Digital items cannot be sent back. Offer a refund instead.');
            }

            $locked->forceFill([
                'status' => IssueStatus::OFFER_PENDING,
                'offer_type' => $type,
                'offer_cents' => $amountCents,
                'offered_at' => now(),
            ])->save();

            $this->message($locked, $seller, 'Offer: '.$locked->offerLabel().'.'.($note ? "\n\n".$note : ''), 'offer');
            $notify[] = [[$locked->buyer], new IssueOfferMade($locked)];

            return $locked;
        });

        $this->send($notify);

        return $result;
    }

    /**
     * The buyer accepts the pending offer. A refund offer is paid at once (the issue is resolved); a return offer waits
     * for the buyer to send the goods back and add the tracking.
     */
    public function acceptOffer(User $buyer, OrderIssue $issue): OrderIssue
    {
        Gate::forUser($buyer)->authorize('respondToOffer', $issue);

        $notify = [];

        $result = DB::transaction(function () use ($buyer, $issue, &$notify) {
            $locked = $this->lock($issue);

            if (! $this->stateAllows($locked, 'acceptOffer')) {
                throw new InvalidOrderStateException('There is no offer to accept.');
            }

            $type = $locked->offer_type;

            if ($type === IssueResolution::RETURN_REFUND) {
                $locked->forceFill(['status' => IssueStatus::AWAITING_RETURN])->save();
                $this->message($locked, $buyer, 'Accepted the offer. I will send the goods back and add the tracking number here.', 'offer_accepted');
                $notify[] = [[$locked->seller], new IssueOfferAnswered($locked, accepted: true)];

                return $locked;
            }

            $amount = $type === IssueResolution::PARTIAL_REFUND ? (int) $locked->offer_cents : null;
            $refunded = $this->refund($locked, $amount, 'Problem report #'.$locked->getKey().': offer accepted by the buyer');

            $this->resolve($locked, $type, $buyer, $refunded);
            $this->message($locked, $buyer, 'Accepted the offer. '.money($refunded, $locked->order->currency).' is being refunded. Case closed.', 'offer_accepted');
            $this->message($locked, null, 'Resolved: '.$type->label().' of '.money($refunded, $locked->order->currency).'.', 'resolved');
            $notify[] = [[$locked->seller], new IssueOfferAnswered($locked, accepted: true)];

            return $locked;
        });

        $this->send($notify);
        $this->releaseHold($result);

        return $result;
    }

    /** The buyer declines the pending offer; the talk goes on and the seller may offer something else. */
    public function declineOffer(User $buyer, OrderIssue $issue, ?string $note = null): OrderIssue
    {
        Gate::forUser($buyer)->authorize('respondToOffer', $issue);

        $note = filled($note) ? trim((string) $note) : null;
        Validator::make(['note' => $note], ['note' => ['nullable', 'string', 'max:2000']])->validate();

        $notify = [];

        $result = DB::transaction(function () use ($buyer, $issue, $note, &$notify) {
            $locked = $this->lock($issue);

            if (! $this->stateAllows($locked, 'declineOffer')) {
                throw new InvalidOrderStateException('There is no offer to decline.');
            }

            $label = $locked->offerLabel();
            $locked->forceFill(['status' => IssueStatus::OPEN, 'offer_type' => null, 'offer_cents' => null, 'offered_at' => null])->save();
            $this->message($locked, $buyer, 'Declined the offer of '.$label.'.'.($note ? "\n\n".$note : ''), 'offer_declined');
            $notify[] = [[$locked->seller], new IssueOfferAnswered($locked, accepted: false)];

            return $locked;
        });

        $this->send($notify);

        return $result;
    }

    // --- Return for a refund ---------------------------------------------------------------------------------------

    /** The buyer sends the goods back and tells the seller how: carrier and tracking number (and a link). */
    public function addReturnTracking(User $buyer, OrderIssue $issue, string $carrier, string $trackingNumber, ?string $trackingUrl = null): OrderIssue
    {
        Gate::forUser($buyer)->authorize('addReturnTracking', $issue);

        $data = Validator::make(
            ['carrier' => trim($carrier), 'tracking_number' => trim($trackingNumber), 'tracking_url' => filled($trackingUrl) ? trim((string) $trackingUrl) : null],
            [
                'carrier' => ['required', 'string', 'max:100'],
                'tracking_number' => ['required', 'string', 'max:100'],
                'tracking_url' => ['nullable', 'url:http,https', 'max:500'],
            ],
        )->validate();

        $notify = [];

        $result = DB::transaction(function () use ($buyer, $issue, $data, &$notify) {
            $locked = $this->lock($issue);

            if (! $this->stateAllows($locked, 'addReturnTracking')) {
                throw new InvalidOrderStateException('There is no return to track: the seller has to offer one first and you have to accept it.');
            }

            $locked->forceFill([
                'return_carrier' => $data['carrier'],
                'return_tracking_number' => $data['tracking_number'],
                'return_tracking_url' => $data['tracking_url'],
                'return_shipped_at' => $locked->return_shipped_at ?? now(),
            ])->save();

            $message = $this->message($locked, $buyer, 'Sent the goods back with '.$data['carrier'].', tracking number '.$data['tracking_number'].'.', 'return_tracking');
            $notify[] = [[$locked->seller], new IssueMessagePosted($locked, $message)];

            return $locked;
        });

        $this->send($notify);

        return $result;
    }

    /** The seller got the goods back: the buyer is refunded in full and the issue is resolved. */
    public function confirmReturnReceived(User $seller, OrderIssue $issue): OrderIssue
    {
        Gate::forUser($seller)->authorize('confirmReturnReceived', $issue);

        $notify = [];

        $result = DB::transaction(function () use ($seller, $issue, &$notify) {
            $locked = $this->lock($issue);

            if (! $this->stateAllows($locked, 'confirmReturnReceived')) {
                throw new InvalidOrderStateException('The buyer has not sent anything back yet (no return tracking was added).');
            }

            $refunded = $this->refund($locked, null, 'Problem report #'.$locked->getKey().': goods returned');
            $locked->forceFill(['return_received_at' => now()])->save();
            $this->resolve($locked, IssueResolution::RETURN_REFUND, $seller, $refunded);
            $this->message($locked, $seller, 'Got the goods back. '.money($refunded, $locked->order->currency).' is refunded to the buyer.', 'return_received');
            $this->message($locked, null, 'Resolved: '.IssueResolution::RETURN_REFUND->label().' of '.money($refunded, $locked->order->currency).'.', 'resolved');
            $notify[] = [[$locked->buyer], new IssueResolved($locked)];

            return $locked;
        });

        $this->send($notify);
        $this->releaseHold($result);

        return $result;
    }

    // --- Ending it -------------------------------------------------------------------------------------------------

    /** The buyer withdraws the report (the problem is gone). Not possible while a bank dispute is open on it. */
    public function withdraw(User $buyer, OrderIssue $issue): OrderIssue
    {
        Gate::forUser($buyer)->authorize('withdraw', $issue);

        $notify = [];

        $result = DB::transaction(function () use ($buyer, $issue, &$notify) {
            $locked = $this->lock($issue);

            if (! $this->stateAllows($locked, 'withdraw')) {
                throw new InvalidOrderStateException($locked->isDispute()
                    ? 'A bank dispute is open on this order. It ends when your bank decides.'
                    : 'This problem has been resolved already.');
            }

            $wasEscalated = $locked->isEscalated();
            $this->resolve($locked, IssueResolution::WITHDRAWN, $buyer, 0);
            $this->message($locked, $buyer, 'Withdrew the report. The problem is solved.', 'withdrawn');
            $notify[] = [array_merge([$locked->seller], $wasEscalated ? $this->admins($buyer)->all() : []), new IssueResolved($locked)];

            return $locked;
        });

        $this->send($notify);
        $this->releaseHold($result);

        return $result;
    }

    /**
     * Hand the case to the admins. Buyer or seller, once issue_escalation_days passed since the report without an agreement.
     * Admins and the other side are notified.
     */
    public function escalate(User $user, OrderIssue $issue, ?string $note = null): OrderIssue
    {
        Gate::forUser($user)->authorize('escalate', $issue);

        $note = filled($note) ? trim((string) $note) : null;
        Validator::make(['note' => $note], ['note' => ['nullable', 'string', 'max:2000']])->validate();

        $notify = [];

        $result = DB::transaction(function () use ($user, $issue, $note, &$notify) {
            $locked = $this->lock($issue);

            if ($locked->isEscalated()) {
                throw new InvalidOrderStateException('This problem is already with the '.config('app.name').' team.');
            }

            if (! $locked->isUnresolved()) {
                throw new InvalidOrderStateException('This problem has been resolved already.');
            }

            if (! $this->stateAllows($locked, 'escalate')) {
                throw new InvalidOrderStateException('Buyer and seller get '.$this->escalationDays().' '.Str::plural('day', $this->escalationDays()).' to agree. You can ask the '.config('app.name').' team to step in from '.$locked->escalatableAt()->format('F j, Y').'.');
            }

            $locked->forceFill(['status' => IssueStatus::ESCALATED, 'escalated_at' => now(), 'escalated_by' => $user->getKey()])->save();
            $this->message($locked, $user, 'Asked the '.config('app.name').' team to step in.'.($note ? "\n\n".$note : ''), 'escalated');

            $other = $locked->isBuyer($user) ? $locked->seller : $locked->buyer;
            $notify[] = [array_merge($this->admins($user)->all(), $other && ! $other->is($user) ? [$other] : []), new IssueEscalated($locked, $user)];

            return $locked;
        });

        $this->send($notify);

        return $result;
    }

    /**
     * An admin decides: FULL_REFUND, PARTIAL_REFUND (with $amountCents) or NO_ACTION. Buyer and seller are told.
     *
     * @throws InvalidOrderStateException
     * @throws \App\Services\Payments\Exceptions\PaymentGatewayException  Stripe refused the refund; nothing changed
     */
    public function adminResolve(User $admin, OrderIssue $issue, IssueResolution $decision, ?int $amountCents = null, ?string $note = null): OrderIssue
    {
        Gate::forUser($admin)->authorize('decide', $issue);

        if (! in_array($decision, [IssueResolution::FULL_REFUND, IssueResolution::PARTIAL_REFUND, IssueResolution::NO_ACTION], true)) {
            throw new InvalidArgumentException('An admin decides: full refund, partial refund or no action.');
        }

        $note = filled($note) ? trim((string) $note) : null;
        Validator::make(['note' => $note], ['note' => ['nullable', 'string', 'max:2000']])->validate();

        $notify = [];

        $result = DB::transaction(function () use ($admin, $issue, $decision, $amountCents, $note, &$notify) {
            $locked = $this->lock($issue);

            if (! $this->stateAllows($locked, 'decide')) {
                throw new InvalidOrderStateException('This problem has been resolved already.');
            }

            if ($decision === IssueResolution::NO_ACTION && $locked->isDispute() && $locked->dispute_status !== null && ! in_array($locked->dispute_status, ['won', 'lost', 'warning_closed', 'charge_refunded'], true)) {
                throw new InvalidOrderStateException('The bank dispute is still open. Stripe tells us when it is decided; respond to it in the Stripe dashboard.');
            }

            $refunded = 0;

            if ($decision !== IssueResolution::NO_ACTION) {
                $left = $locked->refundableCents();
                if ($left <= 0) {
                    throw new InvalidOrderStateException('There is nothing left to refund on this order.');
                }

                if ($decision === IssueResolution::PARTIAL_REFUND) {
                    if ($amountCents === null || $amountCents < 1) {
                        throw new InvalidOrderStateException('Enter the amount to refund.');
                    }
                    if ($amountCents >= $left) {
                        throw new InvalidOrderStateException('A partial refund must be less than the '.money($left, $locked->order->currency).' that can be refunded. Choose a full refund instead.');
                    }
                } else {
                    $amountCents = null;
                }

                $refunded = $this->refund($locked, $amountCents, 'Problem report #'.$locked->getKey().': decided by '.config('app.name'), $admin);
            }

            $this->resolve($locked, $decision, $admin, $refunded);

            $summary = $decision === IssueResolution::NO_ACTION
                ? 'Decision: no action. No refund is due.'
                : 'Decision: '.$decision->label().' of '.money($refunded, $locked->order->currency).'.';
            $this->message($locked, $admin, $summary.($note ? "\n\n".$note : ''), 'resolved');
            $notify[] = [array_filter([$locked->buyer, $locked->seller], fn (?User $u) => $u !== null && ! $u->is($admin)), new IssueResolved($locked)];

            return $locked;
        });

        $this->send($notify);
        $this->releaseHold($result);

        return $result;
    }

    // --- Bank disputes ---------------------------------------------------------------------------------------------

    /**
     * Stripe told us about a bank dispute on this order's charge (webhook `charge.dispute.created` / `.closed`).
     *
     *   created  every seller order of the order gets an ESCALATED issue carrying the dispute id (an unresolved issue
     *            is joined, escalated if it was not): their payouts are on hold; admins and sellers are notified
     *   closed   the issues record the outcome: won (or the inquiry closed) = CHARGEBACK_WON, lost = CHARGEBACK_LOST;
     *            a won dispute releases the hold, a lost one keeps payouts blocked until an admin decides
     *            (recoverFromSeller())
     *
     * Idempotent. $dispute is the Stripe dispute object as an array (id, status, reason, amount, evidence_details.due_by).
     *
     * @param  'created'|'closed'  $event
     * @param  array<string, mixed>  $dispute
     * @return Collection<int, OrderIssue>  the issues the event touched
     */
    public function recordDispute(Order $order, string $event, array $dispute): Collection
    {
        $id = (string) ($dispute['id'] ?? '');
        if ($id === '') {
            return collect();
        }

        return match ($event) {
            'created' => $this->disputeCreated($order, $id, $dispute),
            'closed' => $this->disputeClosed($order, $id, $dispute),
            default => throw new InvalidArgumentException("Unknown dispute event [{$event}]."),
        };
    }

    /**
     * An admin takes a lost dispute's money back from the seller: the seller's transfer is reversed, or a payout that was
     * not made yet is cancelled. The seller is told.
     *
     * @throws InvalidOrderStateException
     * @throws \App\Services\Payments\Exceptions\PaymentGatewayException  Stripe refused the reversal; nothing changed
     */
    public function recoverFromSeller(User $admin, OrderIssue $issue): OrderIssue
    {
        Gate::forUser($admin)->authorize('recover', $issue);

        $notify = [];

        $result = DB::transaction(function () use ($admin, $issue, &$notify) {
            $locked = $this->lock($issue);

            if ($locked->resolution !== IssueResolution::CHARGEBACK_LOST) {
                throw new InvalidOrderStateException('Money can only be recovered from the seller after a lost bank dispute.');
            }

            if ($locked->recovered_at !== null) {
                throw new InvalidOrderStateException('The money was recovered from the seller already.');
            }

            if ($locked->seller?->isPlatformSeller()) {
                throw new InvalidOrderStateException(config('app.name').' is the seller here, there is nobody to recover money from.');
            }

            $transferred = $locked->sellerOrder->isTransferred();
            $recovered = $this->refunds->recoverPayout($locked->sellerOrder);

            $locked->forceFill(['recovered_cents' => $recovered, 'recovered_at' => now()])->save();

            $text = $recovered > 0
                ? ($transferred
                    ? money($recovered, $locked->order->currency).' of the payout was taken back from the seller because the bank dispute was lost.'
                    : 'The payout of '.money($recovered, $locked->order->currency).' was cancelled because the bank dispute was lost.')
                : 'Nothing was left to recover from the seller.';
            $message = $this->message($locked, null, $text, 'recovered');
            $notify[] = [[$locked->seller], new IssueMessagePosted($locked, $message)];

            return $locked;
        });

        $this->send($notify);

        return $result;
    }

    /** @param  array<string, mixed>  $dispute */
    private function disputeCreated(Order $order, string $id, array $dispute): Collection
    {
        $notify = [];
        $touched = collect();

        DB::transaction(function () use ($order, $id, $dispute, &$notify, &$touched) {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $currency = $locked->currency;
            $reason = (string) ($dispute['reason'] ?? 'unknown');
            $amount = isset($dispute['amount']) ? money((int) $dispute['amount'], $currency) : null;
            $text = 'The buyer\'s bank opened a dispute'.($amount ? ' for '.$amount : '').' (reason: '.str_replace('_', ' ', $reason).'). '
                .'Payouts for this order are on hold until it is decided.';
            $fields = [
                'stripe_dispute_id' => $id,
                'dispute_status' => $dispute['status'] ?? 'needs_response',
                'dispute_reason' => $dispute['reason'] ?? null,
                'dispute_due_at' => isset($dispute['evidence_details']['due_by']) ? \Illuminate\Support\Carbon::createFromTimestamp((int) $dispute['evidence_details']['due_by']) : null,
            ];

            foreach ($locked->sellerOrders()->with('seller')->get() as $sellerOrder) {
                if (OrderIssue::query()->where('seller_order_id', $sellerOrder->getKey())->where('stripe_dispute_id', $id)->exists()) {
                    continue; // redelivered
                }

                $issue = OrderIssue::query()->where('seller_order_id', $sellerOrder->getKey())->unresolved()->lockForUpdate()->first();

                if ($issue !== null) {
                    $issue->forceFill($fields + [
                        'status' => IssueStatus::ESCALATED,
                        'escalated_at' => $issue->escalated_at ?? now(),
                    ])->save();
                } else {
                    $issue = OrderIssue::create($fields + [
                        'order_id' => $locked->getKey(),
                        'seller_order_id' => $sellerOrder->getKey(),
                        'buyer_id' => $locked->user_id,
                        'seller_id' => $sellerOrder->seller_id,
                        'reason' => IssueReason::OTHER,
                        'status' => IssueStatus::ESCALATED,
                        'escalated_at' => now(),
                    ]);
                }

                $this->message($issue, null, $text, 'dispute');
                $touched->push($issue);

                if ($sellerOrder->seller !== null && ! $sellerOrder->seller->isPlatformSeller()) {
                    $notify[] = [[$sellerOrder->seller], new IssueEscalated($issue, null)];
                }
            }

            if ($touched->isNotEmpty()) {
                $notify[] = [$this->admins(), new DisputeOpened($locked, $touched->all(), $dispute)];
            }
        });

        $this->send($notify);

        return $touched;
    }

    /** @param  array<string, mixed>  $dispute */
    private function disputeClosed(Order $order, string $id, array $dispute): Collection
    {
        if (! OrderIssue::query()->where('stripe_dispute_id', $id)->exists()) {
            $this->disputeCreated($order, $id, $dispute); // we never heard about it opening
        }

        $status = (string) ($dispute['status'] ?? '');
        $resolution = match ($status) {
            'won', 'warning_closed' => IssueResolution::CHARGEBACK_WON,
            'lost' => IssueResolution::CHARGEBACK_LOST,
            default => IssueResolution::NO_ACTION, // charge_refunded: the buyer got the money back in some other way
        };

        $notify = [];
        $touched = collect();

        DB::transaction(function () use ($id, $status, $resolution, &$notify, &$touched) {
            $issues = OrderIssue::query()->where('stripe_dispute_id', $id)->lockForUpdate()->get();

            foreach ($issues as $issue) {
                if (! $issue->isUnresolved()) {
                    continue; // redelivered
                }

                $issue->forceFill(['dispute_status' => $status]);
                $this->resolve($issue, $resolution, null, 0);
                $this->message($issue, null, match ($resolution) {
                    IssueResolution::CHARGEBACK_WON => 'The bank dispute was decided in our favour. Nothing is taken back from anybody and the payout hold ends.',
                    IssueResolution::CHARGEBACK_LOST => 'The bank dispute was lost: the buyer got the money back from their bank. The payout stays on hold until an admin decides whether to recover it from the seller.',
                    default => 'The bank dispute was closed ('.str_replace('_', ' ', $status).').',
                }, 'resolved');
                $touched->push($issue);

                $recipients = $this->admins()->all();
                if ($issue->seller !== null && ! $issue->seller->isPlatformSeller()) {
                    $recipients[] = $issue->seller;
                }
                $notify[] = [$recipients, new IssueResolved($issue)];
            }
        });

        $this->send($notify);

        foreach ($touched as $issue) {
            $this->releaseHold($issue);
        }

        return $touched;
    }

    // --- Internals -------------------------------------------------------------------------------------------------

    /** The issue row, locked for this transaction and fresh from the database. */
    private function lock(OrderIssue $issue): OrderIssue
    {
        /** @var OrderIssue $locked */
        $locked = OrderIssue::query()->whereKey($issue->getKey())->lockForUpdate()->firstOrFail();
        $locked->load(['order', 'sellerOrder.items', 'buyer', 'seller']);

        return $locked;
    }

    /**
     * Refund (part of) the seller order for this issue through RefundService (as the system) and return the cents refunded.
     * $amountCents null = everything that is left.
     */
    private function refund(OrderIssue $issue, ?int $amountCents, string $reason, ?User $actor = null): int
    {
        $left = $issue->sellerOrder->refundableCents();
        $result = $this->refunds->refundForIssue($issue->sellerOrder->fresh(['order', 'items']), $amountCents, $reason, $actor);

        $issue->setRelation('sellerOrder', $issue->sellerOrder->fresh(['order', 'items']));

        return min($left, $amountCents ?? $left);
    }

    /** Mark the issue resolved: status, resolution, who, what was refunded. Money was moved before, by the caller. */
    private function resolve(OrderIssue $issue, IssueResolution $resolution, ?User $by, int $refundedCents): void
    {
        $issue->forceFill([
            'status' => IssueStatus::RESOLVED,
            'resolution' => $resolution,
            'resolved_at' => now(),
            'resolved_by' => $by?->getKey(),
            'refunded_cents' => $issue->refunded_cents + $refundedCents,
        ])->save();
    }

    /** A payout that was held back for this issue is released now, if it is due (the hourly job would do it too). */
    private function releaseHold(OrderIssue $issue): void
    {
        try {
            $this->payouts->transferIfDue($issue->sellerOrder()->first());
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Append a line to the thread and touch the issue.
     *
     * @param  array<int, array{path: string, name: string, size: int, mime: string}>  $attachments
     */
    private function message(OrderIssue $issue, ?User $author, string $body, ?string $event = null, array $attachments = [], bool $internal = false): OrderIssueMessage
    {
        $message = $issue->messages()->create([
            'author_id' => $author?->getKey(),
            'event' => $event,
            'body' => $body,
            'attachments' => $attachments === [] ? null : $attachments,
            'internal' => $internal,
        ]);

        if (! $internal) {
            $issue->forceFill(['last_message_at' => now()])->saveQuietly();
        }

        return $message;
    }

    /**
     * Everyone involved but $except: buyer, seller and, with $withAdmins, the admins.
     *
     * @return array<int, User>
     */
    private function participants(OrderIssue $issue, ?User $except = null, bool $withAdmins = false): array
    {
        $users = collect([$issue->buyer, $issue->seller]);

        if ($withAdmins) {
            $users = $users->merge($this->admins());
        }

        return $users
            ->filter(fn (?User $user) => $user !== null && ($except === null || ! $user->is($except)))
            ->unique(fn (User $user) => $user->getKey())
            ->values()
            ->all();
    }

    /** Every admin, without $except (the author of the action). @return Collection<int, User> */
    private function admins(?User $except = null): Collection
    {
        return User::role('admin')->get()->reject(fn (User $user) => $except !== null && $user->is($except))->values();
    }

    /**
     * Notifications to send after the transaction: [[recipients], notification] pairs. A failing mailer never fails the action.
     *
     * @param  array<int, array{0: iterable<User|null>, 1: Notification}>  $batches
     */
    private function send(array $batches): void
    {
        foreach ($batches as [$recipients, $notification]) {
            $seen = [];

            foreach ($recipients as $recipient) {
                if ($recipient === null || isset($seen[$recipient->getKey()])) {
                    continue;
                }
                $seen[$recipient->getKey()] = true;

                try {
                    $recipient->notify(clone $notification);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }
    }

    private function escalationDays(): int
    {
        return max(0, (int) config('marketplace.issue_escalation_days', 3));
    }

    /** @param  array<int, mixed>  $photos */
    private function validatePhotos(array $photos): void
    {
        if ($photos === []) {
            return;
        }

        Validator::make(['photos' => array_values($photos)], [
            'photos' => ['array', 'max:'.(int) config('marketplace.issue_max_photos', 5)],
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:'.(int) config('marketplace.issue_photo_max_kb', 8192)],
        ], [
            'photos.max' => 'You can attach up to '.(int) config('marketplace.issue_max_photos', 5).' photos.',
            'photos.*.max' => 'Each photo can be at most '.round((int) config('marketplace.issue_photo_max_kb', 8192) / 1024).' MB.',
            'photos.*.image' => 'Only pictures can be attached.',
            'photos.*.mimes' => 'Photos must be JPG, PNG, WebP or GIF.',
        ])->validate();
    }

    /**
     * Keep the photos on the private disk, under order-issues/{issue id}/.
     *
     * @param  array<int, UploadedFile>  $photos
     * @return array<int, array{path: string, name: string, size: int, mime: string}>
     */
    private function storePhotos(OrderIssue $issue, array $photos): array
    {
        $stored = [];
        $disk = Storage::disk(config('marketplace.private_disk', 'private'));

        foreach (array_values($photos) as $photo) {
            $extension = strtolower((string) ($photo->guessExtension() ?: $photo->getClientOriginalExtension() ?: 'jpg'));
            $name = Str::limit(preg_replace('/[^\w.\- ]+/u', '_', basename((string) $photo->getClientOriginalName())), 120, '');
            $mime = (string) ($photo->getMimeType() ?: 'image/jpeg');
            $size = (int) $photo->getSize();

            $path = $disk->putFileAs('order-issues/'.$issue->getKey(), $photo, Str::uuid().'.'.$extension);

            $stored[] = ['path' => (string) $path, 'name' => $name, 'size' => $size, 'mime' => $mime];
        }

        return $stored;
    }

    /** @param  array<int, array{path: string}>  $stored */
    private function deleteFiles(array $stored): void
    {
        if ($stored !== []) {
            Storage::disk(config('marketplace.private_disk', 'private'))->delete(Arr::pluck($stored, 'path'));
        }
    }
}
