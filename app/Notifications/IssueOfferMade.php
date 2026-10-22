<?php

namespace App\Notifications;

use App\Models\OrderIssue;
use App\Notifications\Concerns\DescribesIssues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the buyer: the seller offered a way to settle the problem (refund, partial refund or return). Mail + database, queued. */
class IssueOfferMade extends Notification implements ShouldQueue
{
    use DescribesIssues;
    use Queueable;

    public function __construct(public OrderIssue $issue)
    {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $issue = $this->issue->loadMissing(['order', 'seller']);
        $order = $issue->order;

        return (new MailMessage)
            ->subject("The seller made you an offer for order {$order->number}")
            ->greeting('You have an offer')
            ->line("{$this->personName($issue->seller, 'The seller')} offers {$issue->offerLabel()} for order {$order->number}.")
            ->line('Accept it and it is settled at once, or decline it and keep talking. If you do not agree you can ask the '.config('app.name').' team to step in after '.(int) config('marketplace.issue_escalation_days', 3).' days.')
            ->action('Review the offer', $this->issueUrl($issue, $notifiable));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $issue = $this->issue->loadMissing('order');

        return $this->issuePayload(
            'issue_offer',
            'The seller made you an offer',
            "For order {$issue->order->number}: {$issue->offerLabel()}.",
            $issue,
            $notifiable,
            ['offer_type' => $issue->offer_type?->value, 'offer_cents' => $issue->offer_cents],
        );
    }
}
