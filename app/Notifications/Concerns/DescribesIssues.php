<?php

namespace App\Notifications\Concerns;

use App\Filament\Resources\OrderIssueResource;
use App\Models\OrderIssue;
use Illuminate\Support\Str;

/** Shared bits of the problem-report notifications (mail + database, like the other marketplace ones). */
trait DescribesIssues
{
    use DescribesOrders;

    /** Where the person lands: admins in the Filament queue, everybody else on the shared issue page. */
    protected function issueUrl(OrderIssue $issue, object $notifiable): string
    {
        if (method_exists($notifiable, 'isPlatformSeller') && $notifiable->isPlatformSeller()) {
            return OrderIssueResource::getUrl('view', ['record' => $issue]);
        }

        return $issue->url();
    }

    /** "SDL-7KQ2M9: Peerless 830869" */
    protected function issueSubject(OrderIssue $issue): string
    {
        $issue->loadMissing(['order', 'sellerOrder.items']);

        return $issue->order->number;
    }

    protected function personName(?\App\Models\User $user, string $fallback = 'The system'): string
    {
        if ($user === null) {
            return $fallback;
        }

        return $user->sellerProfile?->shop_name ?: $user->name;
    }

    protected function excerpt(?string $text, int $limit = 400): string
    {
        return Str::limit(trim((string) $text), $limit);
    }

    /**
     * The database payload for an issue notification: title, body, url, plus the ids the /notifications page can use.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function issuePayload(string $kind, string $title, string $body, OrderIssue $issue, object $notifiable, array $extra = []): array
    {
        $issue->loadMissing('order');

        return $this->payload($kind, $title, $body, $this->issueUrl($issue, $notifiable), [
            'issue_id' => $issue->getKey(),
            'order_id' => $issue->order_id,
            'order_number' => $issue->order->number,
            'seller_order_id' => $issue->seller_order_id,
        ] + $extra);
    }
}
