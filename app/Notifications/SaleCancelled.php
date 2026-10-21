<?php

namespace App\Notifications;

use App\Models\SellerOrder;
use App\Notifications\Concerns\DescribesOrders;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * To the seller: a paid order could not be fulfilled (the stock was gone by the time it was paid) and
 * the buyer was refunded automatically. Mail + database, queued.
 */
class SaleCancelled extends Notification implements ShouldQueue
{
    use DescribesOrders;
    use Queueable;

    public function __construct(public SellerOrder $sellerOrder, public string $reason)
    {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->sellerOrder->order;

        $mail = (new MailMessage)
            ->subject("Sale cancelled: order {$order->number}")
            ->greeting('A sale was cancelled')
            ->line("Order {$order->number} was paid, but it could not be fulfilled: {$this->reason}")
            ->line('The buyer was refunded automatically and you will not be paid for it. Affected items:');

        $mail->line(new HtmlString($this->itemsTable($this->sellerOrder->items()->get(), $order)));

        return $mail
            ->line('Please check the stock of your listings so this does not happen again.')
            ->action('Open your listings', route('dashboard.listings'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $order = $this->sellerOrder->order;

        return $this->payload(
            'sale_cancelled',
            'Sale cancelled',
            "Order {$order->number} was refunded automatically: {$this->reason}",
            route('selling.orders'),
            [
                'order_id' => $order->getKey(),
                'order_number' => $order->number,
                'seller_order_id' => $this->sellerOrder->getKey(),
            ],
        );
    }
}
