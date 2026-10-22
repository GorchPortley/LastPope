<?php

namespace App\Notifications;

use App\Enums\IssueResolution;
use App\Models\OrderIssue;
use App\Notifications\Concerns\DescribesIssues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the people on a problem report (not the one who closed it): it is resolved, and how. Mail + database, queued. */
class IssueResolved extends Notification implements ShouldQueue
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
        $issue = $this->issue->loadMissing(['order', 'buyer', 'seller']);
        $number = $issue->order->number;

        $mail = (new MailMessage)
            ->subject("Problem resolved - order {$number}")
            ->greeting('The problem is resolved');

        foreach ($this->lines($issue, $notifiable) as $line) {
            $mail->line($line);
        }

        return $mail->action('View the problem', $this->issueUrl($issue, $notifiable));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $issue = $this->issue->loadMissing(['order', 'buyer', 'seller']);

        return $this->issuePayload(
            'issue_resolved',
            'Problem resolved',
            implode(' ', $this->lines($issue, $notifiable)),
            $issue,
            $notifiable,
            ['resolution' => $issue->resolution?->value, 'refunded_cents' => $issue->refunded_cents],
        );
    }

    /** @return array<int, string> */
    private function lines(OrderIssue $issue, object $notifiable): array
    {
        $number = $issue->order->number;
        $amount = money($issue->refunded_cents, $issue->order->currency);
        $buyer = $issue->isBuyer($notifiable);

        return match ($issue->resolution) {
            IssueResolution::FULL_REFUND, IssueResolution::RETURN_REFUND, IssueResolution::PARTIAL_REFUND => $buyer
                ? ["The problem with order {$number} was resolved with {$this->phrase($issue)}: {$amount} is refunded. It usually appears on your statement within 5 to 10 business days."]
                : ["The problem with order {$number} was resolved with {$this->phrase($issue)}: {$amount} was refunded to the buyer and your payout is adjusted by that amount."],
            IssueResolution::WITHDRAWN => ["The buyer withdrew the problem report for order {$number}. Nothing else is needed"
                .($buyer ? '.' : ' and your payout continues as planned.')],
            IssueResolution::CHARGEBACK_WON => ["The bank dispute for order {$number} was decided in our favour. Payouts for the order continue as planned."],
            IssueResolution::CHARGEBACK_LOST => ["The bank dispute for order {$number} was lost: the buyer's bank returned the money to them. An admin decides whether it is taken back from the seller's payout."],
            default => ["The problem with order {$number} was closed without a refund. "
                .($buyer ? 'Open the problem to read the decision.' : 'Your payout continues as planned.')],
        };
    }

    private function phrase(OrderIssue $issue): string
    {
        return match ($issue->resolution) {
            IssueResolution::FULL_REFUND => 'a full refund',
            IssueResolution::RETURN_REFUND => 'a refund after the return',
            default => 'a partial refund',
        };
    }
}
