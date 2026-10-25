<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\OrderIssue;
use App\Notifications\Concerns\DescribesIssues;
use App\Support\RecipientLink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * To the admins: a buyer's bank opened a dispute (chargeback) on an order. The issues listed were opened or escalated for
 * it and their payouts are on hold. Evidence has to be sent in the Stripe dashboard before the due date. Mail + database, queued.
 */
class DisputeOpened extends Notification implements ShouldQueue
{
    use DescribesIssues;
    use Queueable;

    /**
     * @param  array<int, OrderIssue>  $issues
     * @param  array<string, mixed>  $dispute  the Stripe dispute object
     */
    public function __construct(public Order $order, public array $issues, public array $dispute)
    {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Bank dispute on order {$this->order->number}")
            ->greeting('A bank dispute was opened')
            ->line($this->summary())
            ->line('Payouts for this order are on hold. Respond to the dispute with your evidence in the Stripe dashboard'.$this->dueText().'.');

        return $mail->action('Review the case', $this->url($notifiable));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return $this->payload(
            'dispute_opened',
            'Bank dispute opened',
            $this->summary().' Payouts are on hold.',
            $this->url($notifiable),
            [
                'order_id' => $this->order->getKey(),
                'order_number' => $this->order->number,
                'issue_ids' => array_map(fn (OrderIssue $issue) => $issue->getKey(), $this->issues),
                'stripe_dispute_id' => $this->dispute['id'] ?? null,
            ],
        );
    }

    private function summary(): string
    {
        $amount = isset($this->dispute['amount']) ? ' of '.money((int) $this->dispute['amount'], $this->order->currency) : '';
        $reason = str_replace('_', ' ', (string) ($this->dispute['reason'] ?? 'unknown'));

        return "The buyer's bank opened a dispute{$amount} on order {$this->order->number} (reason: {$reason}, dispute ".($this->dispute['id'] ?? '?').').';
    }

    private function dueText(): string
    {
        $due = $this->dispute['evidence_details']['due_by'] ?? null;

        return $due ? ' by '.Carbon::createFromTimestamp((int) $due)->format('F j, Y') : '';
    }

    private function url(object $notifiable): string
    {
        $first = $this->issues[0] ?? null;

        return $first ? $this->issueUrl($first, $notifiable) : RecipientLink::to(route('dashboard.orders.show', ['order' => $this->order->number]), $notifiable);
    }
}
