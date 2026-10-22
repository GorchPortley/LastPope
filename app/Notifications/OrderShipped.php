<?php

namespace App\Notifications;

use App\Models\SellerOrder;
use App\Models\Shipment;
use App\Notifications\Concerns\DescribesOrders;
use App\Services\Marketplace\ParcelPlanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * To the buyer: a seller shipped (a parcel of) their part of the order. Mail + database, queued.
 *
 * With a $shipment it tells about that one parcel - its items, carrier and tracking - and, when the order ships in
 * several parcels, "parcel 2 of 3" and whether more are to come. Without one (an order that was shipped before
 * shipments existed) it describes the seller order's own tracking, as it always did.
 */
class OrderShipped extends Notification implements ShouldQueue
{
    use DescribesOrders;
    use Queueable;

    /** Which parcel this is (1-based) and how many the buyer should expect. */
    public int $parcel = 1;

    public int $parcels = 1;

    public function __construct(public SellerOrder $sellerOrder, public ?Shipment $shipment = null)
    {
        if ($shipment !== null) {
            $this->parcels = app(ParcelPlanner::class)->expectedParcelCount($sellerOrder);
            $this->parcel = ((int) array_search($shipment->getKey(), $sellerOrder->shipments()->pluck('id')->all(), true)) + 1;
            $this->parcels = max($this->parcels, $this->parcel);
        }
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
        $several = $this->parcels > 1;

        $mail = (new MailMessage)
            ->subject("Your order {$order->number} has shipped".($several ? " (parcel {$this->parcel} of {$this->parcels})" : ''))
            ->greeting('Good news!')
            ->line($several
                ? "Parcel {$this->parcel} of {$this->parcels} from order {$order->number} is on its way."
                : "The seller shipped your items from order {$order->number}.");

        $mail->line(new HtmlString($this->itemsTable($this->shippedItems(), $order)));

        $mail->line('Carrier: '.$this->carrier())
            ->line('Tracking number: '.$this->trackingNumber());

        if ($several) {
            $mail->line($this->parcel < $this->parcels
                ? 'The rest of your order ships separately. You get another e-mail when the next parcel is on its way.'
                : 'That was the last parcel of your order.');
        }

        $complete = $sellerOrder->shipped_at !== null;
        $mail->line($complete
            ? ($several ? 'When everything has arrived, please confirm receipt in your orders.' : 'When the parcel arrives, please confirm receipt in your orders.')
            : 'Once all parcels have arrived, you can confirm receipt in your orders.')
            ->line('If something is wrong with it, you can report a problem on your order page'.($complete
                ? ' until '.$sellerOrder->shipped_at->copy()->addDays(max(0, (int) config('marketplace.issue_window_days', 14)))->format('F j, Y').'.'
                : ' (until '.max(0, (int) config('marketplace.issue_window_days', 14)).' days after the last parcel has shipped).'));

        if (filled($this->trackingUrl())) {
            $mail->action('Track your parcel', $this->trackingUrl());
        } else {
            $mail->action('View your order', $this->buyerOrderUrl($order));
        }

        return $mail;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $order = $this->sellerOrder->order;
        $several = $this->parcels > 1;

        return $this->payload(
            'order_shipped',
            'Your order has shipped',
            ($several ? "Parcel {$this->parcel} of {$this->parcels} of your order {$order->number}" : "Your order {$order->number}")
                ." has shipped with {$this->carrier()} (tracking {$this->trackingNumber()}).",
            $this->buyerOrderUrl($order),
            [
                'order_id' => $order->getKey(),
                'order_number' => $order->number,
                'seller_order_id' => $this->sellerOrder->getKey(),
                'shipment_id' => $this->shipment?->getKey(),
                'parcel' => $this->parcel,
                'parcels' => $this->parcels,
                'tracking_url' => $this->trackingUrl(),
            ],
        );
    }

    /** The physical items in this parcel (the whole seller order's physical items without a shipment). */
    private function shippedItems()
    {
        if ($this->shipment === null) {
            return $this->sellerOrder->items()->where('is_digital', false)->get();
        }

        return $this->shipment->items()->with('orderItem')->get()->map(function ($row) {
            $item = $row->orderItem->replicate();
            $item->quantity = $row->quantity;
            $item->line_total_cents = $row->orderItem->unit_price_cents * $row->quantity;

            return $item;
        });
    }

    private function carrier(): ?string
    {
        return $this->shipment?->carrier ?? $this->sellerOrder->carrier;
    }

    private function trackingNumber(): ?string
    {
        return $this->shipment?->tracking_number ?? $this->sellerOrder->tracking_number;
    }

    private function trackingUrl(): ?string
    {
        return $this->shipment?->tracking_url ?? $this->sellerOrder->tracking_url;
    }
}
