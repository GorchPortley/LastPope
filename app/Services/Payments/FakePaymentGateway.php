<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\SellerProfile;
use App\Services\Payments\Exceptions\InvalidWebhookException;
use App\Services\Payments\Exceptions\PaymentGatewayException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Webhook;

/**
 * In-memory gateway for tests and for local development without Stripe keys. It records every call
 * and returns deterministic ids (acct_fake_1, cs_fake_1, tr_fake_1, re_fake_1, trr_fake_1...).
 * Webhook signatures are verified for real (Stripe's own algorithm), so tests can sign payloads with
 * \Stripe\WebhookSignature::generateSignatureHeader().
 *
 *     $gateway = app(PaymentGateway::class);          // the fake in the test environment
 *     $gateway->calls('createTransfer');              // list of [method, args]
 *     $gateway->failOn('createTransfer');             // next call to that method throws once
 */
class FakePaymentGateway implements PaymentGateway
{
    /** @var array<int, array{method: string, args: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, int> */
    private array $counters = [];

    /** @var array<string, array{charges_enabled: bool, payouts_enabled: bool, details_submitted: bool}> */
    private array $accountStatuses = [];

    /** @var array<string, int> methods that should fail, with the number of remaining failures */
    private array $failures = [];

    /** @var array{charges_enabled: bool, payouts_enabled: bool, details_submitted: bool} */
    private array $defaultStatus = ['charges_enabled' => true, 'payouts_enabled' => true, 'details_submitted' => true];

    public function createConnectedAccount(SellerProfile $profile): string
    {
        $this->record(__FUNCTION__, ['seller_profile_id' => $profile->getKey(), 'country' => $profile->country]);

        return $this->nextId('acct');
    }

    public function createOnboardingLink(string $accountId, string $returnUrl, string $refreshUrl): string
    {
        $this->record(__FUNCTION__, compact('accountId', 'returnUrl', 'refreshUrl'));

        return 'https://connect.stripe.test/setup/'.$accountId;
    }

    public function createLoginLink(string $accountId): string
    {
        $this->record(__FUNCTION__, compact('accountId'));

        return 'https://connect.stripe.test/express/'.$accountId;
    }

    public function retrieveAccountStatus(string $accountId): array
    {
        $this->record(__FUNCTION__, compact('accountId'));

        return $this->accountStatuses[$accountId] ?? $this->defaultStatus;
    }

    public function createCheckoutSession(Order $order, array $lineItems, string $successUrl, string $cancelUrl): array
    {
        $this->record(__FUNCTION__, [
            'order_id' => $order->getKey(),
            'order_number' => $order->number,
            'lineItems' => $lineItems,
            'successUrl' => $successUrl,
            'cancelUrl' => $cancelUrl,
            'reservedUntil' => $order->reserved_until?->getTimestamp(),
        ]);

        $id = $this->nextId('cs');

        return ['id' => $id, 'url' => 'https://checkout.stripe.test/c/pay/'.$id];
    }

    public function createTransfer(int $amountCents, string $destination, string $transferGroup, ?string $sourceCharge, array $meta): string
    {
        $this->record(__FUNCTION__, compact('amountCents', 'destination', 'transferGroup', 'sourceCharge', 'meta'));

        return $this->nextId('tr');
    }

    public function refund(string $paymentIntentId, ?int $amountCents, array $meta = []): string
    {
        $this->record(__FUNCTION__, compact('paymentIntentId', 'amountCents', 'meta'));

        return $this->nextId('re');
    }

    public function reverseTransfer(string $transferId, ?int $amountCents, array $meta = []): string
    {
        $this->record(__FUNCTION__, compact('transferId', 'amountCents', 'meta'));

        return $this->nextId('trr');
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
        $this->record(__FUNCTION__, compact('paymentIntentId'));

        return 'ch_for_'.$paymentIntentId;
    }

    // --- Test helpers ----------------------------------------------------------------------------

    /**
     * Calls made so far, optionally only to one method.
     *
     * @return array<int, array{method: string, args: array<string, mixed>}>
     */
    public function calls(?string $method = null): array
    {
        return $method === null
            ? $this->calls
            : array_values(array_filter($this->calls, fn (array $call) => $call['method'] === $method));
    }

    public function callCount(string $method): int
    {
        return count($this->calls($method));
    }

    /** Arguments of the last call to $method, or null. */
    public function lastCall(string $method): ?array
    {
        $calls = $this->calls($method);

        return $calls === [] ? null : end($calls)['args'];
    }

    /** What retrieveAccountStatus() answers for $accountId. */
    public function setAccountStatus(string $accountId, bool $chargesEnabled, bool $payoutsEnabled, bool $detailsSubmitted = true): static
    {
        $this->accountStatuses[$accountId] = [
            'charges_enabled' => $chargesEnabled,
            'payouts_enabled' => $payoutsEnabled,
            'details_submitted' => $detailsSubmitted,
        ];

        return $this;
    }

    /** Make the next $times calls to $method throw a PaymentGatewayException. */
    public function failOn(string $method, int $times = 1): static
    {
        $this->failures[$method] = $times;

        return $this;
    }

    public function reset(): static
    {
        $this->calls = $this->counters = $this->accountStatuses = $this->failures = [];

        return $this;
    }

    private function record(string $method, array $args): void
    {
        $this->calls[] = ['method' => $method, 'args' => $args];

        if (($this->failures[$method] ?? 0) > 0) {
            $this->failures[$method]--;

            throw new PaymentGatewayException("Fake gateway failure in {$method}().");
        }
    }

    private function nextId(string $prefix): string
    {
        $this->counters[$prefix] = ($this->counters[$prefix] ?? 0) + 1;

        return $prefix.'_fake_'.$this->counters[$prefix];
    }
}
