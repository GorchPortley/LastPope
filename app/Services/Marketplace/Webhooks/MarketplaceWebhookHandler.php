<?php

namespace App\Services\Marketplace\Webhooks;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Marketplace\OrderFulfillment;
use App\Services\Marketplace\RefundService;
use App\Services\Payments\PaymentGateway;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Reacts to the events of the platform account (POST /stripe/marketplace/webhook):
 *
 *   checkout.session.completed            paid -> OrderFulfillment::markPaid (unpaid = async method, wait)
 *   checkout.session.async_payment_succeeded   the same, for delayed payment methods
 *   checkout.session.expired              cancel the pending order
 *   charge.refunded                       RefundService::syncFromCharge
 *
 * Every handler is idempotent, so a redelivered event is harmless. Anything unknown is ignored
 * (the endpoint may also receive Wave's subscription events). An exception means "retry later".
 */
class MarketplaceWebhookHandler
{
    public const HANDLED = 'handled';

    public const IGNORED = 'ignored';

    public function __construct(
        private readonly OrderFulfillment $fulfillment,
        private readonly RefundService $refunds,
        private readonly PaymentGateway $gateway,
    ) {
    }

    /** @return self::HANDLED|self::IGNORED */
    public function handle(object $event): string
    {
        $object = $this->payload($event);

        return match ($event->type) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => $this->sessionPaid($object),
            'checkout.session.expired' => $this->sessionExpired($object),
            'charge.refunded' => $this->chargeRefunded($object),
            default => self::IGNORED,
        };
    }

    /** @param  array<string, mixed>  $session */
    private function sessionPaid(array $session): string
    {
        if (($session['payment_status'] ?? null) !== 'paid') {
            // Delayed payment method: checkout.session.async_payment_succeeded will follow.
            return self::IGNORED;
        }

        $order = $this->orderForSession($session);
        if ($order === null) {
            return self::IGNORED;
        }

        $paymentIntent = $session['payment_intent'] ?? null;
        if (blank($paymentIntent)) {
            Log::warning('Paid checkout session without a payment intent.', ['session' => $session['id'] ?? null]);

            return self::IGNORED;
        }

        // A redelivered event (or the async twin of completed) already knows the charge: no call needed.
        $chargeId = $order->stripe_charge_id
            ?: $this->gateway->findChargeId((string) $paymentIntent)
            ?? throw new RuntimeException("Payment {$paymentIntent} has no charge yet.");

        if ($order->status === OrderStatus::PENDING) {
            $this->recordAmounts($order, $session);
        }

        $this->fulfillment->markPaid($order, $chargeId, (string) $paymentIntent);

        return self::HANDLED;
    }

    /** @param  array<string, mixed>  $session */
    private function sessionExpired(array $session): string
    {
        $order = $this->orderForSession($session);
        if ($order === null) {
            return self::IGNORED;
        }

        $this->fulfillment->cancelPending($order);

        return self::HANDLED;
    }

    /** @param  array<string, mixed>  $charge */
    private function chargeRefunded(array $charge): string
    {
        $order = Order::query()
            ->when(
                filled($charge['payment_intent'] ?? null),
                fn ($q) => $q->where('stripe_payment_intent_id', $charge['payment_intent']),
                fn ($q) => $q->where('stripe_charge_id', $charge['id'] ?? ''),
            )
            ->first();

        if ($order === null) {
            return self::IGNORED;
        }

        $this->refunds->syncFromCharge($order, (int) ($charge['amount'] ?? 0), (int) ($charge['amount_refunded'] ?? 0));

        return self::HANDLED;
    }

    /** @param  array<string, mixed>  $session */
    private function orderForSession(array $session): ?Order
    {
        $order = Order::query()->where('stripe_checkout_session_id', $session['id'] ?? '')->first();

        if ($order === null && filled($session['metadata']['order_id'] ?? null)) {
            $order = Order::query()
                ->whereKey($session['metadata']['order_id'])
                ->where('number', $session['metadata']['order_number'] ?? null)
                ->first();
        }

        return $order;
    }

    /**
     * Stripe Tax adds tax on the payment page: keep the amounts the buyer really paid.
     *
     * @param  array<string, mixed>  $session
     */
    private function recordAmounts(Order $order, array $session): void
    {
        $total = $session['amount_total'] ?? null;
        $tax = (int) ($session['total_details']['amount_tax'] ?? 0);

        if ($total === null) {
            return;
        }

        if ((int) $total - $tax !== $order->subtotal_cents + $order->shipping_cents) {
            Log::warning('Checkout session amount differs from the order.', ['order' => $order->number, 'session_total' => $total, 'tax' => $tax, 'order_total' => $order->total_cents]);
        }

        $order->forceFill(['tax_cents' => $tax, 'total_cents' => (int) $total])->save();
    }

    /** @return array<string, mixed> */
    private function payload(object $event): array
    {
        $object = $event->data->object ?? null;

        return match (true) {
            $object instanceof \Stripe\StripeObject => $object->toArray(),
            is_object($object) => json_decode(json_encode($object), true) ?: [],
            is_array($object) => $object,
            default => [],
        };
    }
}
