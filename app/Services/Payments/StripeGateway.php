<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\SellerProfile;
use App\Services\Payments\Exceptions\InvalidWebhookException;
use App\Services\Payments\Exceptions\PaymentGatewayException;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Stripe Connect Express with separate charges and transfers (see docs/PLAN.md section 3.8).
 *
 * The buyer pays the platform once (a Checkout Session with a transfer_group); afterwards the
 * platform transfers each seller's share to their connected account with `source_transaction`.
 * Mutating calls carry an idempotency key so a retry after a crash never double-spends.
 */
class StripeGateway implements PaymentGateway
{
    private ?StripeClient $client;

    public function __construct(private readonly ?string $secretKey, ?StripeClient $client = null)
    {
        $this->client = $client;
    }

    public function createConnectedAccount(SellerProfile $profile): string
    {
        $profile->loadMissing('user');

        $account = $this->call(fn () => $this->client()->accounts->create([
            'type' => 'express',
            'country' => strtoupper((string) $profile->country),
            'email' => $profile->user?->email,
            'capabilities' => [
                'card_payments' => ['requested' => true],
                'transfers' => ['requested' => true],
            ],
            'business_profile' => ['name' => $profile->shop_name],
            'metadata' => [
                'seller_profile_id' => (string) $profile->getKey(),
                'user_id' => (string) $profile->user_id,
            ],
        ], ['idempotency_key' => 'seller-profile-'.$profile->getKey().'-account']));

        return (string) $account->id;
    }

    public function createOnboardingLink(string $accountId, string $returnUrl, string $refreshUrl): string
    {
        $link = $this->call(fn () => $this->client()->accountLinks->create([
            'account' => $accountId,
            'type' => 'account_onboarding',
            'return_url' => $returnUrl,
            'refresh_url' => $refreshUrl,
        ]));

        return (string) $link->url;
    }

    public function createLoginLink(string $accountId): string
    {
        $link = $this->call(fn () => $this->client()->accounts->createLoginLink($accountId));

        return (string) $link->url;
    }

    public function retrieveAccountStatus(string $accountId): array
    {
        $account = $this->call(fn () => $this->client()->accounts->retrieve($accountId));

        return [
            'charges_enabled' => (bool) $account->charges_enabled,
            'payouts_enabled' => (bool) $account->payouts_enabled,
            'details_submitted' => (bool) $account->details_submitted,
        ];
    }

    public function createCheckoutSession(Order $order, array $lineItems, string $successUrl, string $cancelUrl): array
    {
        $order->loadMissing('user');

        $metadata = [
            'order_id' => (string) $order->getKey(),
            'order_number' => $order->number,
        ];

        $params = [
            'mode' => 'payment',
            'line_items' => $lineItems,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => $order->number,
            'customer_email' => $order->user?->email,
            'metadata' => $metadata,
            'payment_intent_data' => [
                // Ties the later transfers to this payment; also the order number shown in the dashboard.
                'transfer_group' => $order->number,
                'description' => 'Order '.$order->number,
                'metadata' => $metadata,
            ],
        ];

        // Shipping details on the PaymentIntent (fraud checks, receipts); the buyer is not asked again.
        $address = $order->shipping_address;
        if (is_array($address) && filled($address['line1'] ?? null)) {
            $params['payment_intent_data']['shipping'] = array_filter([
                'name' => $address['name'] ?? null,
                'phone' => $address['phone'] ?? null,
                'address' => array_filter([
                    'line1' => $address['line1'] ?? null,
                    'line2' => $address['line2'] ?? null,
                    'city' => $address['city'] ?? null,
                    'state' => $address['state'] ?? null,
                    'postal_code' => $address['postal_code'] ?? null,
                    'country' => $address['country'] ?? null,
                ], fn ($v) => filled($v)),
            ], fn ($v) => filled($v));
        }

        // The session lives as long as the stock hold of the order (orders.reserved_until). Stripe only accepts
        // 30 minutes to 24 hours after creation, and the clock keeps running while this request is on its way.
        if ($order->reserved_until !== null) {
            $params['expires_at'] = min(
                max($order->reserved_until->getTimestamp(), time() + 1800 + 60),
                time() + 86400 - 60,
            );
        }

        if (config('marketplace.stripe_tax')) {
            $params['automatic_tax'] = ['enabled' => true];
            $params['billing_address_collection'] = 'required';
        }

        $session = $this->call(fn () => $this->client()->checkout->sessions->create(
            $params,
            ['idempotency_key' => 'checkout-order-'.$order->number],
        ));

        return ['id' => (string) $session->id, 'url' => (string) $session->url];
    }

    public function createTransfer(int $amountCents, string $destination, string $transferGroup, ?string $sourceCharge, array $meta): string
    {
        $params = [
            'amount' => $amountCents,
            'currency' => strtolower((string) config('marketplace.currency', 'usd')),
            'destination' => $destination,
            'metadata' => array_filter($meta, fn ($v) => $v !== null),
        ];

        if ($sourceCharge !== null) {
            // The transfer inherits the charge's transfer_group (the PaymentIntent was created with it).
            $params['source_transaction'] = $sourceCharge;
        } else {
            $params['transfer_group'] = $transferGroup;
        }

        $key = isset($meta['seller_order_id'])
            ? 'transfer-seller-order-'.$meta['seller_order_id']
            : 'transfer-'.sha1($destination.'|'.$transferGroup.'|'.$amountCents);

        $transfer = $this->call(fn () => $this->client()->transfers->create($params, ['idempotency_key' => $key]));

        return (string) $transfer->id;
    }

    public function refund(string $paymentIntentId, ?int $amountCents, array $meta = []): string
    {
        $key = $meta['idempotency_key'] ?? null;
        unset($meta['idempotency_key']);

        $params = ['payment_intent' => $paymentIntentId, 'metadata' => array_filter($meta, fn ($v) => $v !== null)];
        if ($amountCents !== null) {
            $params['amount'] = $amountCents;
        }

        $refund = $this->call(fn () => $this->client()->refunds->create($params, $key ? ['idempotency_key' => (string) $key] : []));

        return (string) $refund->id;
    }

    public function reverseTransfer(string $transferId, ?int $amountCents, array $meta = []): string
    {
        $key = $meta['idempotency_key'] ?? null;
        unset($meta['idempotency_key']);

        $params = ['metadata' => array_filter($meta, fn ($v) => $v !== null)];
        if ($amountCents !== null) {
            $params['amount'] = $amountCents;
        }

        $reversal = $this->call(fn () => $this->client()->transfers->createReversal($transferId, $params, $key ? ['idempotency_key' => (string) $key] : []));

        return (string) $reversal->id;
    }

    public function constructWebhookEvent(string $payload, string $signature, string $secret): object
    {
        try {
            return Webhook::constructEvent($payload, $signature, $secret);
        } catch (SignatureVerificationException|UnexpectedValueException|\UnexpectedValueException $e) {
            throw new InvalidWebhookException($e->getMessage(), 0, $e);
        }
    }

    public function findChargeId(string $paymentIntentId): ?string
    {
        $intent = $this->call(fn () => $this->client()->paymentIntents->retrieve($paymentIntentId));
        $charge = $intent->latest_charge ?? null;

        if (is_object($charge)) {
            $charge = $charge->id ?? null;
        }

        return $charge ? (string) $charge : null;
    }

    private function client(): StripeClient
    {
        if ($this->client === null) {
            if (blank($this->secretKey)) {
                throw PaymentGatewayException::notConfigured();
            }

            $this->client = new StripeClient($this->secretKey);
        }

        return $this->client;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function call(callable $callback): mixed
    {
        // stripe-php raises Stripe's advisory "Stripe-Notice" response header (e.g. "We recommend building
        // your integration using Accounts v2") as an E_USER_WARNING *after* the API call succeeded. Laravel
        // turns warnings into exceptions, which would throw away a successful result, so log them instead.
        $previous = set_error_handler(function (int $level, string $message, string $file = '', int $line = 0) use (&$previous) {
            if (in_array($level, [E_USER_WARNING, E_USER_NOTICE], true) && str_contains($file, 'stripe-php')) {
                Log::notice('Stripe notice: '.$message);

                return true;
            }

            return $previous ? $previous($level, $message, $file, $line) : false;
        });

        try {
            return $callback();
        } catch (ApiErrorException $e) {
            throw PaymentGatewayException::wrap($e);
        } finally {
            restore_error_handler();
        }
    }
}
