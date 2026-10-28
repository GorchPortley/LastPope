<?php

namespace App\Services\Analytics;

use App\Jobs\SendGa4Event;
use App\Models\Order;
use App\Models\SellerOrder;
use App\Support\Analytics\Ga4;
use Illuminate\Support\Facades\Log;

/**
 * Server-side GA4 events (docs/PLAN.md 12): `purchase` when an order is paid and `refund` when money goes back.
 *
 *     app(Ga4Reporter::class)->purchase($order);                                    // OrderFulfillment::markPaid
 *     app(Ga4Reporter::class)->refund($order, 1500, 're_123', [$sellerOrder]);      // RefundService
 *
 * Both only queue a SendGa4Event job and never throw, so checkout and the Stripe webhooks are not affected.
 * Nothing is sent without GA4_MEASUREMENT_ID + GA4_API_SECRET, nor when the buyer did not allow analytics at
 * checkout (orders.analytics_consent) or no GA client id was captured.
 */
class Ga4Reporter
{
    public function purchase(Order $order): void
    {
        $this->queue('purchase:'.$order->number, $order, 'purchase', function () use ($order) {
            $order->loadMissing('items.purchasable');

            return [
                'transaction_id' => $order->number,
                'currency' => Ga4::currency(),
                'value' => Ga4::amount((int) $order->subtotal_cents),
                'tax' => Ga4::amount((int) $order->tax_cents),
                'shipping' => Ga4::amount((int) $order->shipping_cents),
                'items' => $order->items->map(fn ($item) => Ga4::orderItem($item))->values()->all(),
            ];
        }, $order->paid_at);
    }

    /**
     * @param  string  $refundKey  unique per refund (the Stripe refund id, or the order's cumulative refunded amount)
     * @param  array<int, SellerOrder>  $fullyRefunded  seller orders refunded completely: their items are listed
     */
    public function refund(Order $order, int $amountCents, string $refundKey, array $fullyRefunded = []): void
    {
        if ($amountCents <= 0) {
            return;
        }

        $this->queue('refund:'.$refundKey, $order, 'refund', function () use ($order, $amountCents, $fullyRefunded) {
            $items = collect($fullyRefunded)
                ->flatMap(fn (SellerOrder $sellerOrder) => $sellerOrder->items()->with('purchasable')->get())
                ->map(fn ($item) => Ga4::orderItem($item))
                ->values()
                ->all();

            return array_filter([
                'transaction_id' => $order->number,
                'currency' => Ga4::currency(),
                'value' => Ga4::amount($amountCents),
                'items' => $items,
            ], fn ($value) => $value !== []);
        });
    }

    /** @param  \Closure(): array<string, mixed>  $params */
    private function queue(string $key, Order $order, string $event, \Closure $params, $at = null): void
    {
        try {
            if (! Ga4::canSendFromServer() || $order->analytics_consent !== true || blank($order->ga_client_id)) {
                return;
            }

            $body = [
                'client_id' => $order->ga_client_id,
                'user_id' => Ga4::userId($order->user),
                'events' => [[
                    'name' => $event,
                    'params' => $params() + ['engagement_time_msec' => 1],
                ]],
            ];

            if ($at !== null) {
                $body['timestamp_micros'] = (int) ($at->getTimestamp() * 1_000_000);
            }

            SendGa4Event::dispatch($key, array_filter($body, fn ($value) => $value !== null));
        } catch (\Throwable $e) {
            report($e);
            Log::warning('A GA4 server event could not be queued.', ['key' => $key]);
        }
    }
}
