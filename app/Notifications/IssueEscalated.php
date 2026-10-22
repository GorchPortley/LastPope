<?php

namespace App\Notifications;

use App\Models\OrderIssue;
use App\Models\User;
use App\Notifications\Concerns\DescribesIssues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A problem report went to the admins to decide: to the admins (who have to act) and to the other side (who is told).
 * $by is who escalated it; null when a Stripe bank dispute did (the seller hears that their payout is on hold).
 * Mail + database, queued.
 */
class IssueEscalated extends Notification implements ShouldQueue
{
    use DescribesIssues;
    use Queueable;

    public function __construct(public OrderIssue $issue, public ?User $by = null)
    {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $issue = $this->issue->loadMissing(['order']);
        $order = $issue->order;
        $admin = $this->isAdmin($notifiable);

        $mail = (new MailMessage)
            ->subject(($admin ? 'Needs a decision' : 'Escalated')." - problem on order {$order->number}")
            ->greeting($admin ? 'A problem needs a decision' : 'The '.config('app.name').' team was called in');

        foreach ($this->lines($issue, $admin) as $line) {
            $mail->line($line);
        }

        return $mail->action($admin ? 'Review the case' : 'Open the problem', $this->issueUrl($issue, $notifiable));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $issue = $this->issue->loadMissing('order');
        $admin = $this->isAdmin($notifiable);

        return $this->issuePayload(
            'issue_escalated',
            $admin ? 'A problem needs a decision' : 'Problem escalated',
            implode(' ', $this->lines($issue, $admin)),
            $issue,
            $notifiable,
            ['dispute' => $issue->isDispute()],
        );
    }

    /** @return array<int, string> */
    private function lines(OrderIssue $issue, bool $admin): array
    {
        $number = $issue->order->number;
        $app = config('app.name');

        if ($this->by === null) {
            return $admin
                ? ["A bank dispute on order {$number} opened a case for you to review. Payouts for the order are on hold."]
                : ["The buyer's bank opened a dispute on order {$number}. Your payout for it is on hold until {$app} and the bank have decided."];
        }

        $who = $this->personName($this->by, 'Somebody');

        return $admin
            ? ["{$who} asked for a decision on the problem with order {$number} ({$issue->reason->label()}). Buyer and seller did not agree."]
            : ["{$who} asked the {$app} team to decide the problem with order {$number}. You can keep adding messages and photos to the thread; the team looks at all of it."];
    }

    private function isAdmin(object $notifiable): bool
    {
        return method_exists($notifiable, 'isPlatformSeller') && $notifiable->isPlatformSeller();
    }
}
