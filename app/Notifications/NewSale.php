<?php

namespace App\Notifications;

use App\Models\SellerOrder;
use App\Notifications\Concerns\DescribesOrders;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

/** To the seller: someone paid for their items. Mail + database, queued. */
class NewSale extends Notification implements ShouldQueue
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
        $items = $sellerOrder->items()->get();
        $physical = $items->contains(fn ($item) => ! $item->is_digital);

        $mail = (new MailMessage)
            ->subject("New sale: order {$order->number}")
            ->greeting('You made a sale!')
            ->line("Order {$order->number} includes:");

        $mail->line(new HtmlString($this->itemsTable($items, $order)));

        $mail->line('Items: '.$this->amount($sellerOrder->subtotal_cents, $order));

        if ($sellerOrder->shipping_cents > 0) {
            $mail->line('Shipping paid by the buyer: '.$this->amount($sellerOrder->shipping_cents, $order));
        }

        if ($sellerOrder->payout_cents > 0) {
            $mail->line('Platform fee: '.$this->amount($sellerOrder->platform_fee_cents, $order).'. Your payout: '.$this->amount($sellerOrder->payout_cents, $order).'.');
        }

        if ($physical) {
            $mail->line('Please ship to: '.implode(', ', $this->addressLines($order->shipping_address)).'.')
                ->line('Add the carrier and tracking number in your seller dashboard once it is on its way'.(config('marketplace.physical_payout_trigger') === 'shipped' ? '; your payout is released then.' : '.'));
        }

        return $mail->action('Open your sales', route('selling.orders'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $order = $this->sellerOrder->order;

        return $this->payload(
            'new_sale',
            'New sale',
            "New sale on order {$order->number}: ".$this->amount($this->sellerOrder->subtotal_cents + $this->sellerOrder->shipping_cents, $order).'.',
            route('selling.orders'),
            [
                'order_id' => $order->getKey(),
                'order_number' => $order->number,
                'seller_order_id' => $this->sellerOrder->getKey(),
            ],
        );
    }
}
