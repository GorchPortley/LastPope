<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Marketplace\OrderFulfillment;
use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\PaymentGateway;
use Illuminate\Console\Command;

/**
 * Development helper: without Stripe keys the local environment uses the fake payment gateway, whose
 * checkout URL leads nowhere. This marks a pending order as paid the way the webhook would, so the rest
 * of the flow (entitlements, shipping, payouts) can be clicked through. Refuses to run against real Stripe.
 */
class MarketplaceSimulatePayment extends Command
{
    protected $signature = 'marketplace:simulate-payment {order : Order number, e.g. SDL-7KQ2M9}';

    protected $description = 'Development only: mark a pending order as paid (fake payment gateway only)';

    public function handle(PaymentGateway $gateway, OrderFulfillment $fulfillment): int
    {
        if (! $gateway instanceof FakePaymentGateway) {
            $this->error('Only available with the fake payment gateway (no STRIPE_SECRET_KEY in the local environment).');

            return self::FAILURE;
        }

        $order = Order::where('number', $this->argument('order'))->first();

        if ($order === null) {
            $this->error('No such order.');

            return self::FAILURE;
        }

        $fulfilled = $fulfillment->markPaid($order, 'ch_simulated_'.$order->getKey(), 'pi_simulated_'.$order->getKey());

        $this->info($fulfilled ? "Order {$order->number} is paid and fulfilled." : "Order {$order->number} was already paid.");

        return self::SUCCESS;
    }
}
