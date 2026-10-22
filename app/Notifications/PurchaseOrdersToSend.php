<?php

namespace App\Notifications;

use App\Models\Order;
use App\Notifications\Concerns\DescribesOrders;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the admins: a store order was paid and N purchase orders to suppliers wait to be sent ("New store order: 2 purchase
 * orders to send"). Not sent for suppliers whose purchase orders go out automatically. Mail + database, queued.
 */
class PurchaseOrdersToSend extends Notification implements ShouldQueue
{
    use DescribesOrders;
    use Queueable;

    public function __construct(public Order $order, public int $count)
    {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title()." ({$this->order->number})")
            ->greeting($this->title())
            ->line("Order {$this->order->number} was paid and includes items that suppliers ship. Send the purchase order(s) to the supplier, or mark them as placed if you ordered in the supplier's portal.")
            ->action('Open purchase orders', $this->url());
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return $this->payload(
            'purchase_orders_to_send',
            $this->title(),
            "Order {$this->order->number}: ".$this->count.' purchase '.($this->count === 1 ? 'order' : 'orders').' waiting to be sent to the supplier.',
            $this->url(),
            [
                'order_id' => $this->order->getKey(),
                'order_number' => $this->order->number,
                'purchase_orders' => $this->count,
            ],
        );
    }

    /** "New store order: 2 purchase orders to send" */
    public function title(): string
    {
        return 'New store order: '.$this->count.' purchase '.($this->count === 1 ? 'order' : 'orders').' to send';
    }

    /** The purchase orders list in the admin panel (falls back to the panel's home while that resource does not exist). */
    private function url(): string
    {
        $resource = \App\Filament\Resources\SupplierOrderResource::class;

        return class_exists($resource) ? $resource::getUrl('index') : url('/admin');
    }
}
