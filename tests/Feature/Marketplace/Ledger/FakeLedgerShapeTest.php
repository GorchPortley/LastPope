<?php

use App\Services\Payments\FakePaymentGateway;
use Tests\Support\OpenApiShape;

/*
| Opt-in: every object FakePaymentGateway puts in its ledger (balance_transaction with its expanded source, charge, py_ payment, refund,
| transfer, transfer_reversal, dispute, payout with its bank account, balance) is checked against Stripe's OpenAPI spec: required fields,
| types, enums, nullability and unknown fields. Skipped unless STRIPE_OPENAPI_SPEC points at a copy of the spec:
|
|     curl -o /tmp/spec3.json https://raw.githubusercontent.com/stripe/openapi/master/openapi/spec3.json
|     STRIPE_OPENAPI_SPEC=/tmp/spec3.json php artisan test --filter=FakeLedgerShape
*/

beforeEach(function () {
    $spec = getenv('STRIPE_OPENAPI_SPEC');

    if (! $spec || ! is_file($spec)) {
        $this->markTestSkipped('Set STRIPE_OPENAPI_SPEC to the path of Stripe\'s spec3.json to run this.');
    }

    $this->shape = new OpenApiShape($spec);
});

it('shapes everything the fake ledger serves like the real API', function () {
    $fake = new FakePaymentGateway;
    $fake->recordCharge('ch_1', 6900, ['payment_intent' => 'pi_1', 'transfer_group' => 'SDL-X', 'description' => 'Order SDL-X', 'metadata' => ['order_id' => '1', 'order_number' => 'SDL-X']]);
    $fake->recordSubscriptionCharge(1900, 'ch_wave');
    $transfer = $fake->createTransfer(6300, 'acct_seller', 'SDL-X', 'ch_1', ['order_id' => 1, 'seller_order_id' => 2]);
    $fake->reverseTransfer($transfer, 2000, ['seller_order_id' => 2]);
    $fake->refund('pi_1', 1000);
    $fake->recordDispute('ch_wave', null, ['id' => 'du_1']);
    $fake->closeDispute('du_1', true);
    $fake->recordPayout('acct_seller', 1000, ['id' => 'po_1', 'status' => 'in_transit']);
    $fake->recordPayout('acct_seller', 2000, ['id' => 'po_2', 'status' => 'failed', 'failure_code' => 'account_closed']);
    $fake->recordPayout(null, 3000, ['id' => 'po_3', 'status' => 'paid']);

    $checked = 0;
    $problems = [];
    $check = function (string $schema, array $object) use (&$checked, &$problems) {
        $checked++;
        foreach ($this->shape->problems($schema, $object) as $problem) {
            $problems[] = $schema.' '.($object['id'] ?? '').': '.$problem;
        }
    };

    foreach ([null, 'acct_seller'] as $account) {
        foreach ($fake->listBalanceTransactions($account)['data'] as $bt) {
            $check('balance_transaction', $bt);
            if (is_array($bt['source'])) {
                $check($bt['source']['object'], $bt['source']);
            }
        }

        foreach ($fake->listPayouts($account)['data'] as $payout) {
            $check('payout', $payout);
            $check('bank_account', $payout['destination']);
        }

        $check('balance', $fake->retrieveBalance($account));
    }

    expect($checked)->toBeGreaterThan(30)->and($problems)->toBe([]);
});

it('notices when an object is not shaped like the real one', function () {
    $broken = ['id' => 'txn_1', 'object' => 'balance_transaction', 'amount' => '100', 'type' => 'made_up', 'surprise' => true];

    $problems = $this->shape->problems('balance_transaction', $broken);

    expect($problems)->toContain('balance_transaction: unknown field surprise')
        ->and(implode("\n", $problems))->toContain('amount: expected integer')->toContain('missing required field currency')->toContain('"made_up" is not one of');
});
