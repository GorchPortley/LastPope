<?php

namespace App\Services\Payments\Concerns;

use App\Models\Order;
use Carbon\CarbonInterface;
use LogicException;

/**
 * The ledger side of FakePaymentGateway (docs/PLAN.md 11, R6): Stripe's own books, in memory, in the shape of the real API
 * objects (balance_transaction, charge, refund, transfer, transfer_reversal, payout, dispute, balance; field names and
 * nesting checked against Stripe's OpenAPI spec and stripe-mock, see tests/Feature/Marketplace/Ledger/StripeMockTest.php).
 *
 * Things Stripe does on its own (the buyer's charge, a dispute, a payout to the bank) are recorded by the test helpers
 * recordCharge(), recordOrderCharge(), recordSubscriptionCharge(), recordDispute(), recordPayout() ... Things the site asks
 * for through the gateway leave their ledger lines by themselves, like at Stripe: createTransfer() books the transfer on
 * the platform and the payment on the seller's account, reverseTransfer() the reversal on both, refund() the refund
 * (when the payment intent is a charge recorded here). So a test can run the real flow (pay, ship, release the payout,
 * refund) and then sync a ledger that matches it, or change the ledger afterwards to make the two disagree.
 *
 * Balance transactions keep their `source` as an id; the list calls expand it from the current object, like Stripe's
 * `expand[]=data.source`, so a charge shows its amount_refunded as of now.
 */
trait SimulatesStripeLedger
{
    /** @var array<string, array<string, array<string, mixed>>> account key => txn id => balance_transaction (source as id) */
    private array $ledger = [];

    /** @var array<string, array<int, string>> account key => the ids of its payouts (the objects are in $ledgerObjects) */
    private array $ledgerPayouts = [];

    /** @var array<string, array<string, mixed>> id => charge / refund / transfer / reversal / dispute (the source objects) */
    private array $ledgerObjects = [];

    /** @var array<string, int> txn or payout id => insertion number (ties between objects created in the same second) */
    private array $ledgerSequence = [];

    private int $ledgerCounter = 0;

    // --- The PaymentGateway read methods ----------------------------------------------------------------------------

    public function listBalanceTransactions(?string $account, ?int $createdSince = null, ?string $startingAfter = null, int $limit = 100): array
    {
        $this->record(__FUNCTION__, compact('account', 'createdSince', 'startingAfter', 'limit'));

        $rows = array_filter($this->ledger[$this->ledgerKey($account)] ?? [], fn (array $bt) => $createdSince === null || $bt['created'] >= $createdSince);

        return $this->ledgerPage($rows, $startingAfter, $limit, function (array $bt): array {
            $bt['source'] = $this->ledgerExpand($bt['source']);
            $bt['status'] = $bt['available_on'] > $this->ledgerNow() ? 'pending' : 'available';

            return $bt;
        });
    }

    public function listPayouts(?string $account, ?int $createdSince = null, ?string $startingAfter = null, int $limit = 100): array
    {
        $this->record(__FUNCTION__, compact('account', 'createdSince', 'startingAfter', 'limit'));

        $rows = array_filter(
            array_map(fn (string $id) => $this->ledgerObjects[$id], $this->ledgerPayouts[$this->ledgerKey($account)] ?? []),
            fn (array $payout) => $createdSince === null || $payout['created'] >= $createdSince,
        );

        return $this->ledgerPage($rows, $startingAfter, $limit, function (array $payout) use ($account): array {
            $payout['destination'] = $this->bankAccountObject((string) $payout['destination'], $account);

            return $payout;
        });
    }

    public function retrieveBalance(?string $account): array
    {
        $this->record(__FUNCTION__, compact('account'));

        $now = $this->ledgerNow();
        $sums = ['available' => [], 'pending' => []];
        foreach ($this->ledger[$this->ledgerKey($account)] ?? [] as $bt) {
            $bucket = $bt['available_on'] > $now ? 'pending' : 'available';
            $sums[$bucket][$bt['currency']] = ($sums[$bucket][$bt['currency']] ?? 0) + $bt['net'];
        }

        // Stripe always reports the account's default currency, even at zero.
        $currencies = array_values(array_unique(array_merge(array_keys($sums['available']), array_keys($sums['pending']), [$this->ledgerCurrency()])));
        $amounts = fn (array $bucket): array => array_map(fn (string $currency) => [
            'amount' => $bucket[$currency] ?? 0,
            'currency' => $currency,
            'source_types' => ['card' => $bucket[$currency] ?? 0],
        ], $currencies);

        return array_filter([
            'object' => 'balance',
            'available' => $amounts($sums['available']),
            'connect_reserved' => $account === null ? array_map(fn (string $currency) => ['amount' => 0, 'currency' => $currency], $currencies) : null,
            'livemode' => false,
            'pending' => $amounts($sums['pending']),
        ], fn ($value) => $value !== null);
    }

    // --- Things Stripe does on its own (test helpers) ---------------------------------------------------------------

    /**
     * A successful payment on the platform account. Options: payment_intent, metadata, transfer_group, description, fee (cents,
     * default 2.9 % + 30 as in config), created (Unix time or Carbon). Returns the balance transaction.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function recordCharge(string $chargeId, int $amountCents, array $options = []): array
    {
        $created = $this->ledgerTime($options['created'] ?? null);
        $txn = $this->nextId('txn');
        $fee = (int) ($options['fee'] ?? $this->ledgerEstimatedFee($amountCents));

        $this->ledgerObjects[$chargeId] = [
            'amount' => $amountCents,
            'amount_captured' => $amountCents,
            'amount_refunded' => 0,
            'application' => null,
            'application_fee' => null,
            'application_fee_amount' => null,
            'balance_transaction' => $txn,
            'billing_details' => [
                'address' => ['city' => null, 'country' => 'US', 'line1' => null, 'line2' => null, 'postal_code' => '90210', 'state' => null],
                'email' => 'buyer@example.test', 'name' => 'Jenny Rosen', 'phone' => null, 'tax_id' => null,
            ],
            'calculated_statement_descriptor' => 'SDLABS.TEST',
            'captured' => true,
            'created' => $created,
            'currency' => $this->ledgerCurrency(),
            'customer' => null,
            'description' => $options['description'] ?? null,
            'disputed' => false,
            'failure_balance_transaction' => null,
            'failure_code' => null,
            'failure_message' => null,
            'fraud_details' => [],
            'id' => $chargeId,
            'livemode' => false,
            'metadata' => $this->ledgerMetadata($options['metadata'] ?? []),
            'object' => 'charge',
            'on_behalf_of' => null,
            'outcome' => ['advice_code' => null, 'network_advice_code' => null, 'network_decline_code' => null, 'network_status' => 'approved_by_network', 'reason' => null, 'risk_level' => 'normal', 'seller_message' => 'Payment complete.', 'type' => 'authorized'],
            'paid' => true,
            'payment_intent' => $options['payment_intent'] ?? $this->nextId('pi'),
            'payment_method' => 'pm_fake_card',
            'payment_method_details' => ['card' => ['brand' => 'visa', 'country' => 'US', 'exp_month' => 8, 'exp_year' => 2030, 'funding' => 'credit', 'last4' => '4242', 'network' => 'visa'], 'type' => 'card'],
            'receipt_email' => null,
            'receipt_number' => null,
            'receipt_url' => 'https://pay.stripe.test/receipts/'.$chargeId,
            'refunded' => false,
            'refunds' => $this->ledgerList('/v1/charges/'.$chargeId.'/refunds'),
            'review' => null,
            'shipping' => null,
            'source_transfer' => null,
            'statement_descriptor' => null,
            'statement_descriptor_suffix' => null,
            'status' => 'succeeded',
            'transfer_data' => null,
            'transfer_group' => $options['transfer_group'] ?? null,
        ];

        // A card payment is available a couple of days later.
        return $this->ledgerAdd(null, $txn, 'charge', 'charge', $amountCents, $fee, $chargeId, $created, $created + 2 * 86400, $options['description'] ?? null, $fee > 0 ? [[
            'amount' => $fee, 'application' => null, 'currency' => $this->ledgerCurrency(), 'description' => 'Stripe processing fees', 'type' => 'stripe_fee',
        ]] : []);
    }

    /**
     * The payment of an order, as Checkout makes it: the order's charge and payment intent, its number as transfer group and
     * order_id / order_number in the metadata, for the order's total (tax included).
     *
     * @param  array<string, mixed>  $options  as recordCharge(), plus `amount` to charge another amount
     * @return array<string, mixed>
     */
    public function recordOrderCharge(Order $order, array $options = []): array
    {
        return $this->recordCharge((string) $order->stripe_charge_id, (int) ($options['amount'] ?? $order->total_cents), $options + [
            'payment_intent' => $order->stripe_payment_intent_id,
            'transfer_group' => $order->number,
            'description' => 'Order '.$order->number,
            'metadata' => ['order_id' => (string) $order->getKey(), 'order_number' => $order->number],
        ]);
    }

    /**
     * A Wave subscription payment: the platform account is shared with Wave's billing, so charges that are not marketplace
     * orders are in the ledger too (no order metadata).
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function recordSubscriptionCharge(int $amountCents = 1000, ?string $chargeId = null, array $options = []): array
    {
        return $this->recordCharge($chargeId ?? $this->nextId('ch'), $amountCents, $options + ['description' => 'Subscription creation']);
    }

    /**
     * Money back to the buyer for a recorded charge (all that is left when $amountCents is null). Also what refund() does for a
     * payment intent that is a recorded charge. Options: id (re_...), metadata, created. Returns the refund object.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function recordRefund(string $chargeId, ?int $amountCents = null, array $options = []): array
    {
        $charge = $this->ledgerObjects[$chargeId] ?? throw new LogicException("The fake ledger has no charge {$chargeId}.");
        $amount = $amountCents ?? ($charge['amount'] - $charge['amount_refunded']);
        $created = $this->ledgerTime($options['created'] ?? null);
        $id = $options['id'] ?? $this->nextId('re');
        $txn = $this->nextId('txn');

        $this->ledgerObjects[$id] = [
            'amount' => $amount,
            'balance_transaction' => $txn,
            'charge' => $chargeId,
            'created' => $created,
            'currency' => $charge['currency'],
            'customer' => null,
            'customer_account' => null,
            'destination_details' => ['card' => ['type' => 'reversal'], 'type' => 'card'],
            'id' => $id,
            'metadata' => $this->ledgerMetadata($options['metadata'] ?? []),
            'object' => 'refund',
            'payment_intent' => $charge['payment_intent'],
            'payment_method' => $charge['payment_method'],
            'reason' => null,
            'receipt_number' => null,
            'source_transfer_reversal' => null,
            'status' => 'succeeded',
            'transfer_reversal' => null,
        ];

        $this->ledgerObjects[$chargeId]['amount_refunded'] += $amount;
        $this->ledgerObjects[$chargeId]['refunded'] = $this->ledgerObjects[$chargeId]['amount_refunded'] >= $charge['amount'];

        // Stripe does not give its processing fee back on a refund.
        $this->ledgerAdd(null, $txn, 'refund', 'refund', -$amount, 0, $id, $created, $created, 'REFUND FOR CHARGE ('.($charge['description'] ?? $chargeId).')');

        return $this->ledgerObjects[$id];
    }

    /**
     * A bank dispute (chargeback) on a recorded charge: the amount and the dispute fee leave the balance at once
     * (an `adjustment` balance transaction with the dispute as its source). Options: id (du_...), reason, fee (default 1500), created.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed> the dispute object
     */
    public function recordDispute(string $chargeId, ?int $amountCents = null, array $options = []): array
    {
        $charge = $this->ledgerObjects[$chargeId] ?? throw new LogicException("The fake ledger has no charge {$chargeId}.");
        $amount = $amountCents ?? $charge['amount'];
        $created = $this->ledgerTime($options['created'] ?? null);
        $id = $options['id'] ?? $this->nextId('du');
        $txn = $this->nextId('txn');

        $this->ledgerObjects[$id] = [
            'amount' => $amount,
            'balance_transactions' => [],
            'charge' => $chargeId,
            'created' => $created,
            'currency' => $charge['currency'],
            'enhanced_eligibility_types' => [],
            'evidence' => array_fill_keys(['access_activity_log', 'billing_address', 'cancellation_policy', 'cancellation_policy_disclosure', 'cancellation_rebuttal', 'customer_communication', 'customer_email_address', 'customer_name', 'customer_purchase_ip', 'customer_signature', 'duplicate_charge_documentation', 'duplicate_charge_explanation', 'duplicate_charge_id', 'product_description', 'receipt', 'refund_policy', 'refund_policy_disclosure', 'refund_refusal_explanation', 'service_date', 'service_documentation', 'shipping_address', 'shipping_carrier', 'shipping_date', 'shipping_documentation', 'shipping_tracking_number', 'uncategorized_file', 'uncategorized_text'], null) + ['enhanced_evidence' => []],
            'evidence_details' => ['due_by' => $created + 9 * 86400, 'enhanced_eligibility' => [], 'has_evidence' => false, 'past_due' => false, 'submission_count' => 0],
            'id' => $id,
            'is_charge_refundable' => false,
            'livemode' => false,
            'metadata' => [],
            'object' => 'dispute',
            'payment_intent' => $charge['payment_intent'],
            'payment_method_details' => ['card' => ['brand' => 'visa', 'case_type' => 'chargeback', 'network' => 'visa', 'network_reason_code' => '10.4'], 'type' => 'card'],
            'reason' => $options['reason'] ?? 'fraudulent',
            'status' => 'needs_response',
        ];
        $this->ledgerObjects[$chargeId]['disputed'] = true;

        $fee = (int) ($options['fee'] ?? 1500);
        $this->ledgerAdd(null, $txn, 'adjustment', 'dispute', -$amount, $fee, $id, $created, $created, 'Chargeback withdrawal for '.$chargeId, [[
            'amount' => $fee, 'application' => null, 'currency' => $charge['currency'], 'description' => 'Dispute fee', 'type' => 'stripe_fee',
        ]]);
        $this->ledgerObjects[$id]['balance_transactions'] = [$txn];

        return $this->ledgerObjects[$id];
    }

    /** The bank decided: a won dispute returns the money (`dispute_reversal`), a lost one only closes the case. */
    public function closeDispute(string $disputeId, bool $won): array
    {
        $dispute = $this->ledgerObjects[$disputeId] ?? throw new LogicException("The fake ledger has no dispute {$disputeId}.");
        $this->ledgerObjects[$disputeId]['status'] = $won ? 'won' : 'lost';

        if ($won) {
            $created = $this->ledgerNow();
            $txn = $this->nextId('txn');
            $this->ledgerAdd(null, $txn, 'adjustment', 'dispute_reversal', $dispute['amount'], 0, $disputeId, $created, $created, 'Chargeback reversal for '.$dispute['charge']);
            $this->ledgerObjects[$disputeId]['balance_transactions'][] = $txn;
        }

        return $this->ledgerObjects[$disputeId];
    }

    /**
     * A payout of an account's balance to its bank account (money out: a negative `payout` balance transaction). Options: id (po_...),
     * status (default paid), arrival (Unix time, default two days on), failure_code, failure_message, created. A failed payout
     * returns its money to the balance with a `payout_failure` transaction. Returns the payout object.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function recordPayout(?string $account, int $amountCents, array $options = []): array
    {
        $created = $this->ledgerTime($options['created'] ?? null);
        $id = $options['id'] ?? $this->nextId('po');
        $txn = $this->nextId('txn');
        $status = $options['status'] ?? 'paid';

        $this->ledgerObjects[$id] = [
            'amount' => $amountCents,
            'application_fee' => null,
            'application_fee_amount' => null,
            'arrival_date' => $this->ledgerTime($options['arrival'] ?? $created + 2 * 86400),
            'automatic' => true,
            'balance_transaction' => $txn,
            'created' => $created,
            'currency' => $this->ledgerCurrency(),
            'description' => 'STRIPE PAYOUT',
            'destination' => 'ba_fake_'.substr(sha1((string) $account), 0, 8),
            'failure_balance_transaction' => null,
            'failure_code' => null,
            'failure_message' => null,
            'id' => $id,
            'livemode' => false,
            'metadata' => [],
            'method' => 'standard',
            'object' => 'payout',
            'original_payout' => null,
            'payout_method' => null,
            'reconciliation_status' => 'not_applicable',
            'reversed_by' => null,
            'source_type' => 'card',
            'statement_descriptor' => null,
            'status' => 'in_transit',
            'trace_id' => ['status' => 'pending', 'value' => null],
            'type' => 'bank_account',
        ];
        $this->ledgerAdd($account, $txn, 'payout', 'payout', -$amountCents, 0, $id, $created, $created, 'STRIPE PAYOUT');
        $this->ledgerPayouts[$this->ledgerKey($account)][] = $id;
        $this->ledgerSequence[$id] = ++$this->ledgerCounter;

        match ($status) {
            'failed' => $this->failPayout($id, $options['failure_code'] ?? 'account_closed', $options['failure_message'] ?? 'The bank account has been closed.'),
            default => $this->ledgerObjects[$id]['status'] = $status,
        };

        return $this->ledgerObjects[$id];
    }

    /** The bank sent the payout back: failed, with Stripe's reason, and the money is in the balance again. */
    public function failPayout(string $payoutId, string $code = 'account_closed', string $message = 'The bank account has been closed.'): array
    {
        $payout = $this->ledgerObjects[$payoutId] ?? throw new LogicException("The fake ledger has no payout {$payoutId}.");
        $account = $this->ledgerAccountOf($payoutId);
        $txn = $this->nextId('txn');

        $this->ledgerObjects[$payoutId]['status'] = 'failed';
        $this->ledgerObjects[$payoutId]['failure_code'] = $code;
        $this->ledgerObjects[$payoutId]['failure_message'] = $message;
        $this->ledgerObjects[$payoutId]['failure_balance_transaction'] = $txn;
        $this->ledgerAdd($account, $txn, 'payout_failure', 'payout_reversal', $payout['amount'], 0, $payoutId, $this->ledgerNow(), $this->ledgerNow(), 'PAYOUT FAILURE');

        return $this->ledgerObjects[$payoutId];
    }

    /** Set the status of a recorded payout (in_transit, paid, canceled ...). */
    public function setPayoutStatus(string $payoutId, string $status): array
    {
        $this->ledgerObjects[$payoutId] ?? throw new LogicException("The fake ledger has no payout {$payoutId}.");
        $this->ledgerObjects[$payoutId]['status'] = $status;

        return $this->ledgerObjects[$payoutId];
    }

    // --- Things the site asks for (createTransfer, reverseTransfer, refund call these) -------------------------------

    /**
     * A transfer from the platform to a connected account: a negative `transfer` on the platform and a `payment` (the py_
     * charge, whose `source_transfer` is the transfer) on the seller's account.
     *
     * @param  array<string, scalar|null>  $metadata
     * @return array<string, mixed> the transfer object
     */
    public function recordTransfer(string $id, string $destination, int $amountCents, ?string $sourceCharge = null, ?string $transferGroup = null, array $metadata = [], ?int $created = null): array
    {
        $created ??= $this->ledgerNow();
        $payment = $this->nextId('py');
        $platformTxn = $this->nextId('txn');
        $sellerTxn = $this->nextId('txn');
        $currency = $this->ledgerCurrency();

        $this->ledgerObjects[$id] = [
            'amount' => $amountCents,
            'amount_reversed' => 0,
            'balance_transaction' => $platformTxn,
            'created' => $created,
            'currency' => $currency,
            'description' => null,
            'destination' => $destination,
            'destination_payment' => $payment,
            'id' => $id,
            'livemode' => false,
            'metadata' => $this->ledgerMetadata($metadata),
            'object' => 'transfer',
            'reversals' => $this->ledgerList('/v1/transfers/'.$id.'/reversals'),
            'reversed' => false,
            'source_transaction' => $sourceCharge,
            'source_type' => 'card',
            'transfer_group' => $transferGroup ?? ($sourceCharge !== null ? ($this->ledgerObjects[$sourceCharge]['transfer_group'] ?? null) : null),
        ];

        // The payment on the seller's account (Stripe calls it py_...; it is a charge object whose source is the transfer).
        $this->ledgerObjects[$payment] = [
            'amount' => $amountCents,
            'amount_captured' => $amountCents,
            'amount_refunded' => 0,
            'application' => null,
            'application_fee' => null,
            'application_fee_amount' => null,
            'balance_transaction' => $sellerTxn,
            'billing_details' => ['address' => ['city' => null, 'country' => null, 'line1' => null, 'line2' => null, 'postal_code' => null, 'state' => null], 'email' => null, 'name' => null, 'phone' => null, 'tax_id' => null],
            'calculated_statement_descriptor' => null,
            'captured' => true,
            'created' => $created,
            'currency' => $currency,
            'customer' => null,
            'description' => null,
            'disputed' => false,
            'failure_balance_transaction' => null,
            'failure_code' => null,
            'failure_message' => null,
            'fraud_details' => [],
            'id' => $payment,
            'livemode' => false,
            'metadata' => [],
            'object' => 'charge',
            'on_behalf_of' => null,
            'outcome' => null,
            'paid' => true,
            'payment_intent' => null,
            'payment_method' => null,
            'payment_method_details' => null,
            'receipt_email' => null,
            'receipt_number' => null,
            'receipt_url' => null,
            'refunded' => false,
            'refunds' => $this->ledgerList('/v1/charges/'.$payment.'/refunds'),
            'review' => null,
            'shipping' => null,
            'source_transfer' => $id,
            'statement_descriptor' => null,
            'statement_descriptor_suffix' => null,
            'status' => 'succeeded',
            'transfer_data' => null,
            'transfer_group' => $this->ledgerObjects[$id]['transfer_group'],
        ];

        $this->ledgerAdd(null, $platformTxn, 'transfer', 'transfer', -$amountCents, 0, $id, $created, $created, null);

        // With a source transaction the money is available on the seller's account when the buyer's payment is.
        $sourceTxn = $sourceCharge !== null ? ($this->ledgerObjects[$sourceCharge]['balance_transaction'] ?? null) : null;
        $availableOn = $sourceTxn !== null ? ($this->ledger['platform'][$sourceTxn]['available_on'] ?? $created) : $created;
        $this->ledgerAdd($destination, $sellerTxn, 'payment', 'charge', $amountCents, 0, $payment, $created, $availableOn, null);

        return $this->ledgerObjects[$id];
    }

    /**
     * Take money back from a transfer: a positive `transfer_refund` on the platform and a negative `payment_refund` (a
     * refund of the py_ charge, whose `source_transfer_reversal` is the reversal) on the seller's account.
     *
     * @param  array<string, scalar|null>  $metadata
     * @return array<string, mixed> the transfer reversal object
     */
    public function recordTransferReversal(string $reversalId, string $transferId, ?int $amountCents = null, array $metadata = []): array
    {
        $transfer = $this->ledgerObjects[$transferId] ?? throw new LogicException("The fake ledger has no transfer {$transferId}.");
        $amount = $amountCents ?? ($transfer['amount'] - $transfer['amount_reversed']);
        $created = $this->ledgerNow();
        $platformTxn = $this->nextId('txn');
        $sellerTxn = $this->nextId('txn');
        $refund = $this->nextId('pyr');
        $payment = $transfer['destination_payment'];

        $this->ledgerObjects[$reversalId] = [
            'amount' => $amount,
            'balance_transaction' => $platformTxn,
            'created' => $created,
            'currency' => $transfer['currency'],
            'destination_payment_refund' => $refund,
            'id' => $reversalId,
            'metadata' => $this->ledgerMetadata($metadata),
            'object' => 'transfer_reversal',
            'source_refund' => null,
            'transfer' => $transferId,
        ];
        $this->ledgerObjects[$refund] = [
            'amount' => $amount,
            'balance_transaction' => $sellerTxn,
            'charge' => $payment,
            'created' => $created,
            'currency' => $transfer['currency'],
            'customer' => null,
            'customer_account' => null,
            'id' => $refund,
            'metadata' => [],
            'object' => 'refund',
            'payment_intent' => null,
            'payment_method' => null,
            'reason' => null,
            'receipt_number' => null,
            'source_transfer_reversal' => $reversalId,
            'status' => 'succeeded',
            'transfer_reversal' => null,
        ];

        $this->ledgerObjects[$transferId]['amount_reversed'] += $amount;
        $this->ledgerObjects[$transferId]['reversed'] = $this->ledgerObjects[$transferId]['amount_reversed'] >= $transfer['amount'];
        $this->ledgerObjects[$transferId]['reversals']['data'][] = $this->ledgerObjects[$reversalId];
        $this->ledgerObjects[$payment]['amount_refunded'] += $amount;
        $this->ledgerObjects[$payment]['refunded'] = $this->ledgerObjects[$payment]['amount_refunded'] >= $this->ledgerObjects[$payment]['amount'];

        $this->ledgerAdd(null, $platformTxn, 'transfer_refund', 'transfer_reversal', $amount, 0, $reversalId, $created, $created, null);
        $this->ledgerAdd($transfer['destination'], $sellerTxn, 'payment_refund', 'refund', -$amount, 0, $refund, $created, $created, null);

        return $this->ledgerObjects[$reversalId];
    }

    // --- Looking at and bending the ledger (tests) -------------------------------------------------------------------

    /**
     * The raw balance transactions of an account (null = platform), oldest first, `source` as id.
     *
     * @return array<int, array<string, mixed>>
     */
    public function ledgerTransactions(?string $account = null): array
    {
        $rows = array_values($this->ledger[$this->ledgerKey($account)] ?? []);
        usort($rows, fn (array $a, array $b) => [$a['created'], $this->ledgerSequence[$a['id']]] <=> [$b['created'], $this->ledgerSequence[$b['id']]]);

        return $rows;
    }

    /** The balance transaction of $type whose source is $sourceId. @return array<string, mixed>|null */
    public function ledgerTransactionFor(?string $account, string $type, string $sourceId): ?array
    {
        foreach ($this->ledger[$this->ledgerKey($account)] ?? [] as $bt) {
            if ($bt['type'] === $type && $bt['source'] === $sourceId) {
                return $bt;
            }
        }

        return null;
    }

    /** A source object (charge, refund, transfer, reversal, payout, dispute) by id. @return array<string, mixed>|null */
    public function ledgerObject(string $id): ?array
    {
        return $this->ledgerObjects[$id] ?? null;
    }

    /** Change fields of a balance transaction (amount, fee, net, available_on ...) to make the ledger disagree with the site. */
    public function alterLedgerTransaction(string $txnId, array $changes): static
    {
        foreach ($this->ledger as $key => $rows) {
            if (isset($rows[$txnId])) {
                $this->ledger[$key][$txnId] = array_replace($rows[$txnId], $changes);
            }
        }

        return $this;
    }

    /** Change fields of a source object (a charge's metadata, a transfer's destination ...). */
    public function alterLedgerObject(string $id, array $changes): static
    {
        $this->ledgerObjects[$id] = array_replace($this->ledgerObjects[$id] ?? throw new LogicException("The fake ledger has no object {$id}."), $changes);

        return $this;
    }

    /** Remove a balance transaction, as if Stripe never had it. */
    public function forgetLedgerTransaction(string $txnId): static
    {
        foreach ($this->ledger as $key => $rows) {
            unset($this->ledger[$key][$txnId]);
        }

        return $this;
    }

    public function resetLedger(): static
    {
        $this->ledger = $this->ledgerPayouts = $this->ledgerObjects = $this->ledgerSequence = [];
        $this->ledgerCounter = 0;

        return $this;
    }

    // --- Internals ----------------------------------------------------------------------------------------------------

    /** Stripe keeps metadata as strings and drops empty values. @return array<string, string> */
    private function ledgerMetadata(array $metadata): array
    {
        unset($metadata['idempotency_key']);

        return array_map('strval', array_filter($metadata, fn ($value) => $value !== null));
    }

    /** The object behind a balance transaction as the API shows it with `expand[]=data.source`; a dispute lists its own transactions in full. */
    private function ledgerExpand(string $id): array|string
    {
        $object = $this->ledgerObjects[$id] ?? null;

        if ($object === null) {
            return $id;
        }

        if (($object['object'] ?? null) === 'dispute') {
            $object['balance_transactions'] = array_values(array_filter(array_map(function (string $txn): ?array {
                foreach ($this->ledger as $rows) {
                    if (isset($rows[$txn])) {
                        return $rows[$txn] + [];
                    }
                }

                return null;
            }, $object['balance_transactions'])));
        }

        return $object;
    }

    private function ledgerKey(?string $account): string
    {
        return $account ?? 'platform';
    }

    private function ledgerNow(): int
    {
        return now()->getTimestamp();
    }

    private function ledgerTime(int|CarbonInterface|null $time): int
    {
        return $time instanceof CarbonInterface ? $time->getTimestamp() : ($time ?? $this->ledgerNow());
    }

    private function ledgerCurrency(): string
    {
        return strtolower((string) config('marketplace.currency', 'usd'));
    }

    /** What Stripe charges for a card payment, from the same 2.9 % + 30 cents as the margin report's estimate. */
    private function ledgerEstimatedFee(int $amountCents): int
    {
        return intdiv($amountCents * (int) config('marketplace.stripe_fee_bps', 290) + 5000, 10000) + (int) config('marketplace.stripe_fee_fixed_cents', 30);
    }

    /** @return array<string, mixed> an empty Stripe list object */
    private function ledgerList(string $url): array
    {
        return ['data' => [], 'has_more' => false, 'object' => 'list', 'url' => $url];
    }

    /**
     * Book a balance transaction on an account. `source` is the id of the object behind it.
     *
     * @param  array<int, array<string, mixed>>  $feeDetails
     * @return array<string, mixed>
     */
    private function ledgerAdd(?string $account, string $txnId, string $type, string $reportingCategory, int $amountCents, int $feeCents, string $source, int $created, int $availableOn, ?string $description, array $feeDetails = []): array
    {
        $bt = [
            'amount' => $amountCents,
            'available_on' => $availableOn,
            'balance_type' => 'payments',
            'created' => $created,
            'currency' => $this->ledgerCurrency(),
            'description' => $description,
            'exchange_rate' => null,
            'fee' => $feeCents,
            'fee_details' => $feeDetails,
            'id' => $txnId,
            'net' => $amountCents - $feeCents,
            'object' => 'balance_transaction',
            'reporting_category' => $reportingCategory,
            'source' => $source,
            'status' => $availableOn > $this->ledgerNow() ? 'pending' : 'available',
            'type' => $type,
        ];

        $this->ledger[$this->ledgerKey($account)][$txnId] = $bt;
        $this->ledgerSequence[$txnId] = ++$this->ledgerCounter;

        return $bt;
    }

    /** The account whose payouts hold $payoutId. */
    private function ledgerAccountOf(string $payoutId): ?string
    {
        foreach ($this->ledgerPayouts as $key => $payouts) {
            if (in_array($payoutId, $payouts, true)) {
                return $key === 'platform' ? null : $key;
            }
        }

        return null;
    }

    /**
     * Newest first, then the page after $startingAfter.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @return array{data: array<int, array<string, mixed>>, has_more: bool}
     */
    private function ledgerPage(array $rows, ?string $startingAfter, int $limit, callable $shape): array
    {
        $rows = array_values($rows);
        usort($rows, fn (array $a, array $b) => [$b['created'], $this->ledgerSequence[$b['id']] ?? 0] <=> [$a['created'], $this->ledgerSequence[$a['id']] ?? 0]);

        if ($startingAfter !== null) {
            $position = array_search($startingAfter, array_column($rows, 'id'), true);
            $rows = $position === false ? [] : array_slice($rows, $position + 1);
        }

        $limit = max(1, min(100, $limit));

        return [
            'data' => array_map($shape, array_slice($rows, 0, $limit)),
            'has_more' => count($rows) > $limit,
        ];
    }

    /** @return array<string, mixed> */
    private function bankAccountObject(string $id, ?string $account): array
    {
        return [
            'account' => $account,
            'account_holder_name' => 'Jane Seller',
            'account_holder_type' => 'individual',
            'account_type' => 'checking',
            'available_payout_methods' => ['standard'],
            'bank_name' => 'STRIPE TEST BANK',
            'country' => 'US',
            'currency' => $this->ledgerCurrency(),
            'default_for_currency' => true,
            'fingerprint' => substr(sha1($id), 0, 16),
            'id' => $id,
            'last4' => '6789',
            'metadata' => [],
            'object' => 'bank_account',
            'routing_number' => '110000000',
            'status' => 'new',
        ];
    }
}
