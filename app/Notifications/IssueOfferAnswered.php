<?php

namespace App\Notifications;

use App\Models\OrderIssue;
use App\Notifications\Concerns\DescribesIssues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the seller: the buyer accepted or declined their offer. Mail + database, queued. */
class IssueOfferAnswered extends Notification implements ShouldQueue
{
    use DescribesIssues;
    use Queueable;

    public function __construct(public OrderIssue $issue, public bool $accepted)
    {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $issue = $this->issue->loadMissing(['order', 'buyer']);
        $order = $issue->order;
        $buyer = $this->personName($issue->buyer, 'The buyer');

        $mail = (new MailMessage)
            ->subject(($this->accepted ? 'Offer accepted' : 'Offer declined')." for order {$order->number}")
            ->greeting($this->accepted ? 'Your offer was accepted' : 'Your offer was declined');

        foreach ($this->lines($issue, $buyer, $order->number) as $line) {
            $mail->line($line);
        }

        return $mail->action('Open the problem', $this->issueUrl($issue, $notifiable));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $issue = $this->issue->loadMissing(['order', 'buyer']);

        return $this->issuePayload(
            'issue_offer_answered',
            $this->accepted ? 'Your offer was accepted' : 'Your offer was declined',
            implode(' ', $this->lines($issue, $this->personName($issue->buyer, 'The buyer'), $issue->order->number)),
            $issue,
            $notifiable,
            ['accepted' => $this->accepted],
        );
    }

    /** @return array<int, string> */
    private function lines(OrderIssue $issue, string $buyer, string $number): array
    {
        if (! $this->accepted) {
            return ["{$buyer} declined your offer for order {$number}. The problem is open again: you can write back or make another offer."];
        }

        if ($issue->isUnresolved()) {
            return ["{$buyer} accepted your return offer for order {$number}. They will send the goods back and add the tracking number. Confirm when they arrive and the refund is paid."];
        }

        $amount = money($issue->refunded_cents, $issue->order->currency);

        return ["{$buyer} accepted your offer for order {$number}. {$amount} was refunded and the problem is closed. Your payout is adjusted by the refund."];
    }
}
