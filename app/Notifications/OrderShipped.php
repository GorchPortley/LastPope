<?php

namespace App\Notifications;

use App\Models\SellerOrder;
use App\Notifications\Concerns\DescribesOrders;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the buyer: a seller shipped their part of the order. Mail + database, queued. */
class OrderShipped extends Notification implements ShouldQueue
{
    use DescribesOrders;
    use Queueable;

    public function __construct(public SellerOrder $sellerOrder)
    {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $sellerOrder = $this->sellerOrder;
        $order = $sellerOrder->order;

        $mail = (new MailMessage)
            ->subject("Your order {$order->number} has shipped")
            ->greeting('Good news!')
            ->line("The seller shipped your items from order {$order->number}.");

        foreach ($this->itemLines($sellerOrder->items()->where('is_digital', false)->get(), $order) as $line) {
            $mail->line($line);
        }

        $mail->line('Carrier: '.$sellerOrder->carrier)
            ->line('Tracking number: '.$sellerOrder->tracking_number)
            ->line('When the parcel arrives, please confirm receipt in your orders.');

        if (filled($sellerOrder->tracking_url)) {
            $mail->action('Track your parcel', $sellerOrder->tracking_url);
        } else {
            $mail->action('View your order', url('/dashboard/orders'));
        }

        return $mail;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $order = $this->sellerOrder->order;

        return $this->sender() + [
            'body' => "Your order {$order->number} has shipped with {$this->sellerOrder->carrier} (tracking {$this->sellerOrder->tracking_number}).",
            'link' => url('/dashboard/orders'),
            'kind' => 'order_shipped',
            'order_id' => $order->getKey(),
            'order_number' => $order->number,
            'seller_order_id' => $this->sellerOrder->getKey(),
            'tracking_url' => $this->sellerOrder->tracking_url,
        ];
    }
}
