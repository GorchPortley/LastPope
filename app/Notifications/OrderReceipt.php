<?php

namespace App\Notifications;

use App\Models\Order;
use App\Notifications\Concerns\DescribesOrders;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the buyer: the payment went through. Mail + database, queued. */
class OrderReceipt extends Notification implements ShouldQueue
{
    use DescribesOrders;
    use Queueable;

    public function __construct(public Order $order)
    {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->order;
        $items = $order->items()->get();
        $physical = $items->contains(fn ($item) => ! $item->is_digital);
        $digital = $items->contains(fn ($item) => $item->is_digital);

        $mail = (new MailMessage)
            ->subject("Your order {$order->number}")
            ->greeting('Thank you for your order!')
            ->line("Order {$order->number} is paid.");

        foreach ($this->itemLines($items, $order) as $line) {
            $mail->line($line);
        }

        if ($order->shipping_cents > 0) {
            $mail->line('Shipping - '.$this->amount($order->shipping_cents, $order));
        }

        if ($order->tax_cents > 0) {
            $mail->line('Tax - '.$this->amount($order->tax_cents, $order));
        }

        $mail->line('Total: '.$this->amount($order->total_cents, $order));

        if ($digital) {
            $mail->line('Your digital downloads are ready in your library.');
        }

        if ($physical) {
            $mail->line('The sellers have been told to ship your items'.($order->shipping_address ? ' to: '.implode(', ', $this->addressLines($order->shipping_address)) : '').'. You get an e-mail with the tracking details when they do.');
        }

        return $mail->action($digital && ! $physical ? 'Open your library' : 'View your order', url($digital && ! $physical ? '/dashboard/library' : '/dashboard/orders'));
    }

    /** @return array<string, mixed> same shape as the other notifications shown on /notifications */
    public function toArray(object $notifiable): array
    {
        return $this->sender() + [
            'body' => "Your order {$this->order->number} is paid. Total ".$this->amount($this->order->total_cents, $this->order).'.',
            'link' => url('/dashboard/orders'),
            'kind' => 'order_receipt',
            'order_id' => $this->order->getKey(),
            'order_number' => $this->order->number,
        ];
    }
}
