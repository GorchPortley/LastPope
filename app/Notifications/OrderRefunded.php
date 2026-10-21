<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\SellerOrder;
use App\Notifications\Concerns\DescribesOrders;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the buyer: a refund was issued. Mail + database, queued. */
class OrderRefunded extends Notification implements ShouldQueue
{
    use DescribesOrders;
    use Queueable;

    /** @param  SellerOrder|null  $sellerOrder  set for a partial refund of one seller's part */
    public function __construct(
        public Order $order,
        public int $amountCents,
        public ?SellerOrder $sellerOrder = null,
    ) {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Refund for order {$this->order->number}")
            ->greeting('Your refund is on its way')
            ->line($this->amount($this->amountCents, $this->order)." was refunded for order {$this->order->number}.");

        if ($this->sellerOrder !== null) {
            foreach ($this->itemLines($this->sellerOrder->items()->get(), $this->order) as $line) {
                $mail->line($line);
            }
        }

        return $mail
            ->line('It usually appears on your statement within 5 to 10 business days. Refunded digital downloads are removed from your library.')
            ->action('View your orders', url('/dashboard/orders'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return $this->sender() + [
            'body' => $this->amount($this->amountCents, $this->order)." was refunded for order {$this->order->number}.",
            'link' => url('/dashboard/orders'),
            'kind' => 'order_refunded',
            'order_id' => $this->order->getKey(),
            'order_number' => $this->order->number,
            'amount_cents' => $this->amountCents,
        ];
    }
}
