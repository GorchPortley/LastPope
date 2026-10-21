<?php

namespace App\Notifications;

use App\Models\SellerOrder;
use App\Notifications\Concerns\DescribesOrders;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the seller: the money for an order was transferred to their Stripe account. Mail + database, queued. */
class PayoutSent extends Notification implements ShouldQueue
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
        $order = $this->sellerOrder->order;

        return (new MailMessage)
            ->subject('Payout sent for order '.$order->number)
            ->greeting('Your payout is on its way')
            ->line($this->amount($this->sellerOrder->payout_cents, $order)." was transferred to your Stripe account for order {$order->number}.")
            ->line('Items '.$this->amount($this->sellerOrder->subtotal_cents, $order).' + shipping '.$this->amount($this->sellerOrder->shipping_cents, $order).' - platform fee '.$this->amount($this->sellerOrder->platform_fee_cents, $order).'.')
            ->line('Stripe pays it out to your bank on your payout schedule.')
            ->action('Open your sales', route('selling.orders'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $order = $this->sellerOrder->order;

        return $this->payload(
            'payout_sent',
            'Payout sent',
            'Payout of '.$this->amount($this->sellerOrder->payout_cents, $order)." sent for order {$order->number}.",
            route('selling.orders'),
            [
                'order_id' => $order->getKey(),
                'order_number' => $order->number,
                'seller_order_id' => $this->sellerOrder->getKey(),
                'payout_cents' => $this->sellerOrder->payout_cents,
            ],
        );
    }
}
