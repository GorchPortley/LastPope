<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\SellerProfile;
use App\Services\Payments\Exceptions\InvalidWebhookException;
use App\Services\Payments\Exceptions\PaymentGatewayException;

/**
 * Everything the marketplace needs from the payment provider (Stripe Connect, separate charges and
 * transfers). Bound in MarketplaceServiceProvider: StripeGateway in production, FakePaymentGateway
 * in tests. Every method throws PaymentGatewayException when the provider fails.
 */
interface PaymentGateway
{
    /** Create an Express connected account (card_payments + transfers) for the seller; returns acct_... */
    public function createConnectedAccount(SellerProfile $profile): string;

    /** Hosted onboarding (or "continue onboarding") URL for the connected account. */
    public function createOnboardingLink(string $accountId, string $returnUrl, string $refreshUrl): string;

    /** One-time login URL for the seller's Express dashboard. */
    public function createLoginLink(string $accountId): string;

    /** @return array{charges_enabled: bool, payouts_enabled: bool, details_submitted: bool} */
    public function retrieveAccountStatus(string $accountId): array;

    /**
     * Hosted Checkout Session (mode=payment) for the order. $lineItems are in Stripe's `line_items`
     * shape (price_data in the marketplace currency, one extra line per seller that has shipping).
     *
     * @param  array<int, array<string, mixed>>  $lineItems
     * @return array{id: string, url: string}
     */
    public function createCheckoutSession(Order $order, array $lineItems, string $successUrl, string $cancelUrl): array;

    /**
     * Move money to a connected account. $sourceCharge ties the transfer to the buyer's charge so it
     * works before the funds are available. $meta carries order_id / seller_order_id (also used as
     * the idempotency key by StripeGateway). Returns tr_...
     *
     * @param  array<string, scalar|null>  $meta
     */
    public function createTransfer(int $amountCents, string $destination, string $transferGroup, ?string $sourceCharge, array $meta): string;

    /**
     * Refund a payment (all of it when $amountCents is null). $meta may carry an `idempotency_key`
     * and descriptive metadata. Returns re_...
     *
     * @param  array<string, scalar|null>  $meta
     */
    public function refund(string $paymentIntentId, ?int $amountCents, array $meta = []): string;

    /**
     * Take money back from a connected account (all of the transfer when $amountCents is null). Returns trr_...
     *
     * @param  array<string, scalar|null>  $meta
     */
    public function reverseTransfer(string $transferId, ?int $amountCents, array $meta = []): string;

    /**
     * Verify the signature and parse a webhook body into a Stripe event object.
     *
     * @throws InvalidWebhookException
     */
    public function constructWebhookEvent(string $payload, string $signature, string $secret): object;

    /**
     * The charge (ch_...) behind a PaymentIntent. Needed for `source_transaction` transfers: the
     * checkout.session.completed event only carries the PaymentIntent id. Null if not charged yet.
     *
     * @throws PaymentGatewayException
     */
    public function findChargeId(string $paymentIntentId): ?string;

    // --- Ledger (docs/PLAN.md 11, R6): read-only views of Stripe's own books --------------------------------------------

    /**
     * One page of an account's balance transactions, newest first, each with its `source` expanded (the charge, refund,
     * transfer, transfer reversal, payout or dispute behind it). $account null = the platform account, else a connected
     * account (the Stripe-Account header). $createdSince is a Unix time (inclusive); $startingAfter the id of the last
     * transaction of the previous page. Objects come back as the arrays of Stripe's API.
     *
     * @return array{data: array<int, array<string, mixed>>, has_more: bool}
     *
     * @throws PaymentGatewayException
     */
    public function listBalanceTransactions(?string $account, ?int $createdSince = null, ?string $startingAfter = null, int $limit = 100): array;

    /**
     * One page of an account's payouts to its bank account, newest first (`destination` expanded: the bank name and last
     * four digits). Same arguments as listBalanceTransactions().
     *
     * @return array{data: array<int, array<string, mixed>>, has_more: bool}
     *
     * @throws PaymentGatewayException
     */
    public function listPayouts(?string $account, ?int $createdSince = null, ?string $startingAfter = null, int $limit = 100): array;

    /**
     * Stripe's balance object of an account: `available` and `pending` amounts per currency (null = the platform).
     *
     * @return array<string, mixed>
     *
     * @throws PaymentGatewayException
     */
    public function retrieveBalance(?string $account): array;
}
