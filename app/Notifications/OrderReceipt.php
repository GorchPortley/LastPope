<?php

namespace App\Notifications;

use App\Models\Order;
use App\Notifications\Concerns\DescribesOrders;
use App\Services\Marketplace\ParcelPlanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

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

        $mail->line(new HtmlString($this->itemsTable($items, $order)));

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

            // An order that travels in several parcels (several sellers, or a store order with items a supplier ships): say what comes when.
            if (($parcels = $this->parcelsHtml($order)) !== null) {
                $mail->line('Your items ship in separate parcels:')->line(new HtmlString($parcels));
            }
        }

        return $mail->action($digital && ! $physical ? 'Open your library' : 'View your order', $digital && ! $physical ? route('dashboard.library') : $this->buyerOrderUrl($order, $notifiable));
    }

    /**
     * The parcels of the order as a list ("Ships from SDLabs: 2 x Woofer", "Ships separately (5–8 business days): Tweeter -
     * expected to ship between Oct 28 and Nov 3"), or null when everything travels in one parcel. Never names a supplier.
     */
    private function parcelsHtml(Order $order): ?string
    {
        $planner = app(ParcelPlanner::class);
        $parcels = $order->sellerOrders()->with(['seller.sellerProfile', 'items.supplierOrderItem', 'items.supplier', 'shipments.items'])->get()
            ->filter(fn ($sellerOrder) => $sellerOrder->hasPhysicalItems())
            ->flatMap(fn ($sellerOrder) => $planner->parcels($sellerOrder));

        if ($parcels->count() < 2) {
            return null;
        }

        return '<ul>'.$parcels->map(function ($parcel) use ($order) {
            $items = $parcel->items->map(fn ($item) => ($item->quantity > 1 ? $item->quantity.' x ' : '').$item->title)->implode(', ');
            $expected = $parcel->expectedText($order->paid_at);

            return '<li><strong>'.e($parcel->label()).'</strong>: '.e($items).($expected ? ' - '.e(lcfirst($expected)) : '').'</li>';
        })->implode('').'</ul>';
    }

    /** @return array<string, mixed> same shape as the other notifications shown on /notifications */
    public function toArray(object $notifiable): array
    {
        return $this->payload(
            'order_receipt',
            'Order confirmed',
            "Your order {$this->order->number} is paid. Total ".$this->amount($this->order->total_cents, $this->order).'.',
            $this->buyerOrderUrl($this->order, $notifiable),
            [
                'order_id' => $this->order->getKey(),
                'order_number' => $this->order->number,
            ],
        );
    }
}
