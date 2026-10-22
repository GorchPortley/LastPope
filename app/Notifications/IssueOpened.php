<?php

namespace App\Notifications;

use App\Models\OrderIssue;
use App\Notifications\Concerns\DescribesIssues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

/** To the seller: a buyer reported a problem with their part of an order. Mail + database, queued. */
class IssueOpened extends Notification implements ShouldQueue
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
        $issue = $this->issue->loadMissing(['order', 'sellerOrder.items', 'buyer', 'messages']);
        $order = $issue->order;
        $first = $issue->messages->first();

        $mail = (new MailMessage)
            ->subject("Problem reported on order {$order->number}")
            ->greeting('A buyer reported a problem')
            ->line("{$this->personName($issue->buyer, 'The buyer')} reported a problem with order {$order->number}: ".strtolower($issue->reason->label()).'.');

        $mail->line(new HtmlString($this->itemsTable($issue->sellerOrder->items, $order)));

        if ($first !== null) {
            $mail->line('"'.$this->excerpt($first->body).'"');
        }

        return $mail
            ->line('Your payout for this order is on hold until the problem is resolved. You can answer the buyer, offer a refund or a return, or ask for the goods to be sent back.')
            ->action('Open the problem', $this->issueUrl($issue, $notifiable));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $issue = $this->issue->loadMissing('order');

        return $this->issuePayload(
            'issue_opened',
            'Problem reported',
            "A buyer reported a problem with order {$issue->order->number}: ".strtolower($issue->reason->label()).'. Your payout is on hold until it is resolved.',
            $issue,
            $notifiable,
            ['reason' => $issue->reason->value],
        );
    }
}
