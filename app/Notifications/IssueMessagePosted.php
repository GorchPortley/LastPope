<?php

namespace App\Notifications;

use App\Models\OrderIssue;
use App\Models\OrderIssueMessage;
use App\Notifications\Concerns\DescribesIssues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the other people on a problem report: somebody wrote in the thread (or the system noted something). Mail + database, queued. */
class IssueMessagePosted extends Notification implements ShouldQueue
{
    use DescribesIssues;
    use Queueable;

    public function __construct(public OrderIssue $issue, public OrderIssueMessage $message)
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
        $order = $issue->order;
        $author = $this->authorName();

        return (new MailMessage)
            ->subject("New message about order {$order->number}")
            ->greeting('There is news about your order problem')
            ->line($this->message->isSystem()
                ? "Order {$order->number}:"
                : "{$author} wrote on the problem report for order {$order->number}:")
            ->line('"'.$this->excerpt($this->message->body, 600).'"')
            ->action('Open the problem', $this->issueUrl($issue, $notifiable));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $issue = $this->issue->loadMissing('order');

        return $this->issuePayload(
            'issue_message',
            'New message about your order problem',
            ($this->message->isSystem() ? '' : $this->authorName().': ').$this->excerpt($this->message->body, 160),
            $issue,
            $notifiable,
            ['message_id' => $this->message->getKey()],
        );
    }

    private function authorName(): string
    {
        $author = $this->message->author;

        if ($author === null) {
            return config('app.name');
        }

        if ($author->isPlatformSeller() && ! $this->issue->isBuyer($author)) {
            return config('app.name').' team';
        }

        return $this->personName($author);
    }
}
