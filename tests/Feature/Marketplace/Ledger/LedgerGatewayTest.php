<?php

use App\Services\Payments\Exceptions\PaymentGatewayException;
use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\StripeGateway;
use Tests\Support\FakeStripeHttpClient;

/*
| R6.1 gateway: the read methods (balance transactions, payouts, balance) of StripeGateway - request parameters, the Stripe-Account
| header for connected accounts, errors - and of FakePaymentGateway, whose in-memory ledger books what the site asks for.
*/

// --- StripeGateway ---------------------------------------------------------------------------------------------------------

describe('StripeGateway', function () {
    beforeEach(function () {
        $this->http = FakeStripeHttpClient::install();
        $this->gateway = new StripeGateway('sk_test_unit');
    });

    afterEach(fn () => FakeStripeHttpClient::uninstall());

    it('lists balance transactions of the platform with the source expanded, newest first, from a time, after a cursor', function () {
        $this->http->respond('GET /v1/balance_transactions', [
            'object' => 'list', 'url' => '/v1/balance_transactions', 'has_more' => true,
            'data' => [[
                'id' => 'txn_1', 'object' => 'balance_transaction', 'amount' => 6900, 'fee' => 230, 'net' => 6670, 'currency' => 'usd', 'type' => 'charge',
                'created' => 1790000000, 'available_on' => 1790172800, 'reporting_category' => 'charge', 'fee_details' => [],
                'source' => ['id' => 'ch_1', 'object' => 'charge', 'amount' => 6900, 'metadata' => ['order_id' => '7']],
            ]],
        ]);

        $page = $this->gateway->listBalanceTransactions(null, 1789990000, 'txn_0', 50);

        $request = $this->http->last('GET /v1/balance_transactions');
        expect($request['params'])->toMatchArray(['limit' => 50, 'starting_after' => 'txn_0'])
            ->and($request['params']['created'])->toBe(['gte' => 1789990000])
            ->and($request['params']['expand'])->toBe(['data.source'])
            ->and($request['headers'])->not->toHaveKey('stripe-account')
            ->and($page['has_more'])->toBeTrue()
            ->and($page['data'])->toHaveCount(1)
            ->and($page['data'][0]['id'])->toBe('txn_1')
            ->and($page['data'][0]['source']['metadata']['order_id'])->toBe('7');
    });

    it('asks for a connected account through the Stripe-Account header, and for no time or cursor when none is given', function () {
        $this->http->respond('GET /v1/balance_transactions', ['object' => 'list', 'url' => '/v1/balance_transactions', 'has_more' => false, 'data' => []]);

        $page = $this->gateway->listBalanceTransactions('acct_seller_9');

        $request = $this->http->last('GET /v1/balance_transactions');
        expect($request['headers']['stripe-account'])->toBe('acct_seller_9')
            ->and($request['params'])->not->toHaveKey('created')->not->toHaveKey('starting_after')
            ->and($request['params']['limit'])->toBe(100)
            ->and($page)->toBe(['data' => [], 'has_more' => false]);
    });

    it('never asks for more than Stripe allows per page', function () {
        $this->http->respond('GET /v1/balance_transactions', ['object' => 'list', 'url' => '/v1/balance_transactions', 'has_more' => false, 'data' => []]);

        $this->gateway->listBalanceTransactions(null, null, null, 5000);
        expect($this->http->last('GET /v1/balance_transactions')['params']['limit'])->toBe(100);

        $this->gateway->listBalanceTransactions(null, null, null, 0);
        expect($this->http->last('GET /v1/balance_transactions')['params']['limit'])->toBe(1);
    });

    it('lists payouts with the destination expanded', function () {
        $this->http->respond('GET /v1/payouts', [
            'object' => 'list', 'url' => '/v1/payouts', 'has_more' => false,
            'data' => [['id' => 'po_1', 'object' => 'payout', 'amount' => 1100, 'status' => 'paid', 'destination' => ['id' => 'ba_1', 'object' => 'bank_account', 'last4' => '6789', 'bank_name' => 'STRIPE TEST BANK']]],
        ]);

        $page = $this->gateway->listPayouts('acct_seller_9', 1789990000, 'po_0', 20);

        $request = $this->http->last('GET /v1/payouts');
        expect($request['headers']['stripe-account'])->toBe('acct_seller_9')
            ->and($request['params'])->toMatchArray(['limit' => 20, 'starting_after' => 'po_0'])
            ->and($request['params']['created'])->toBe(['gte' => 1789990000])
            ->and($request['params']['expand'])->toBe(['data.destination'])
            ->and($page['data'][0]['destination']['last4'])->toBe('6789');
    });

    it('retrieves the balance of the platform or of a connected account', function () {
        $this->http->respond('GET /v1/balance', [
            'object' => 'balance', 'livemode' => false,
            'available' => [['amount' => 7000, 'currency' => 'usd', 'source_types' => ['card' => 7000]]],
            'pending' => [['amount' => 2500, 'currency' => 'usd']],
        ]);

        $platform = $this->gateway->retrieveBalance(null);
        expect($this->http->last('GET /v1/balance')['headers'])->not->toHaveKey('stripe-account')
            ->and($platform['available'][0]['amount'])->toBe(7000)->and($platform['pending'][0]['amount'])->toBe(2500)->and($platform['livemode'])->toBeFalse();

        $this->gateway->retrieveBalance('acct_seller_9');
        expect($this->http->last('GET /v1/balance')['headers']['stripe-account'])->toBe('acct_seller_9');
    });

    it('turns Stripe errors into gateway exceptions, and says which ones are worth trying again', function () {
        $this->http->respond('GET /v1/balance_transactions', ['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such account: acct_gone']], 404);
        $permanent = null;
        try {
            $this->gateway->listBalanceTransactions('acct_gone');
        } catch (PaymentGatewayException $e) {
            $permanent = $e;
        }
        expect($permanent)->toBeInstanceOf(PaymentGatewayException::class)->and($permanent->getMessage())->toContain('No such account')->and($permanent->isPermanent())->toBeTrue();

        $this->http->respond('GET /v1/balance_transactions', ['error' => ['type' => 'invalid_request_error', 'message' => 'The provided key does not have access']], 403);
        expect(fn () => $this->gateway->listBalanceTransactions('acct_foreign'))->toThrow(fn (PaymentGatewayException $e) => $e->isPermanent() === true);

        $this->http->respond('GET /v1/balance', ['error' => ['type' => 'rate_limit_error', 'message' => 'Too many requests']], 429);
        $transient = null;
        try {
            $this->gateway->retrieveBalance(null);
        } catch (PaymentGatewayException $e) {
            $transient = $e;
        }
        expect($transient->isPermanent())->toBeFalse();
    });

    it('keeps Stripe\'s advisory notices from throwing away a successful read', function () {
        $this->http->respond('GET /v1/balance', ['object' => 'balance', 'available' => [], 'pending' => [], 'livemode' => false], 200, ['Stripe-Notice' => 'We recommend building your integration using Accounts v2']);

        expect($this->gateway->retrieveBalance(null)['object'])->toBe('balance');
    });

    it('is not usable without a secret key', function () {
        expect(fn () => (new StripeGateway(null))->retrieveBalance(null))->toThrow(PaymentGatewayException::class, 'STRIPE_SECRET_KEY');
    });
});

// --- FakePaymentGateway ledger ----------------------------------------------------------------------------------------------

describe('FakePaymentGateway ledger', function () {
    beforeEach(function () {
        $this->fake = fakeGateway();
        $this->travelTo(now()->startOfSecond());
    });

    it('serves its balance transactions newest first in pages, filtered by time', function () {
        foreach (range(1, 5) as $i) {
            $this->fake->recordSubscriptionCharge(1000 + $i, 'ch_'.$i, ['created' => now()->subMinutes(10 - $i)]);
        }

        $first = $this->fake->listBalanceTransactions(null, null, null, 2);
        $second = $this->fake->listBalanceTransactions(null, null, $first['data'][1]['id'], 2);
        $recent = $this->fake->listBalanceTransactions(null, now()->subMinutes(7)->getTimestamp(), null, 100);

        expect(collect($first['data'])->pluck('source.id')->all())->toBe(['ch_5', 'ch_4'])->and($first['has_more'])->toBeTrue()
            ->and(collect($second['data'])->pluck('source.id')->all())->toBe(['ch_3', 'ch_2'])->and($second['has_more'])->toBeTrue()
            ->and(collect($recent['data'])->pluck('source.id')->all())->toBe(['ch_5', 'ch_4', 'ch_3'])->and($recent['has_more'])->toBeFalse()
            ->and($this->fake->listBalanceTransactions(null, null, 'txn_unknown')['data'])->toBe([]);
    });

    it('expands the source and shows it as it is now', function () {
        $this->fake->recordSubscriptionCharge(2000, 'ch_expand');
        $before = $this->fake->listBalanceTransactions(null)['data'][0];
        $this->fake->recordRefund('ch_expand', 500);
        $after = collect($this->fake->listBalanceTransactions(null)['data'])->firstWhere('type', 'charge');

        expect($before['source']['object'])->toBe('charge')->and($before['source']['amount_refunded'])->toBe(0)
            ->and($after['source']['amount_refunded'])->toBe(500);
    });

    it('keeps the ledgers of different accounts apart', function () {
        $this->fake->recordPayout('acct_a', 1000, ['id' => 'po_a']);
        $this->fake->recordPayout('acct_b', 2000, ['id' => 'po_b']);
        $this->fake->recordPayout(null, 3000, ['id' => 'po_p']);

        expect(collect($this->fake->listPayouts('acct_a')['data'])->pluck('id')->all())->toBe(['po_a'])
            ->and(collect($this->fake->listPayouts('acct_b')['data'])->pluck('id')->all())->toBe(['po_b'])
            ->and(collect($this->fake->listPayouts(null)['data'])->pluck('id')->all())->toBe(['po_p'])
            ->and($this->fake->listBalanceTransactions('acct_a')['data'])->toHaveCount(1)
            ->and($this->fake->listPayouts('acct_a')['data'][0]['destination']['object'])->toBe('bank_account')
            ->and($this->fake->listPayouts('acct_a')['data'][0]['destination']['account'])->toBe('acct_a');
    });

    it('books a transfer on the platform and the payment on the seller account, like Stripe', function () {
        $this->fake->recordCharge('ch_src', 10000, ['transfer_group' => 'SDL-X', 'payment_intent' => 'pi_src']);
        $id = $this->fake->createTransfer(6300, 'acct_seller', 'SDL-X', 'ch_src', ['order_id' => 5, 'seller_order_id' => 9]);

        $platform = collect($this->fake->listBalanceTransactions(null)['data'])->firstWhere('type', 'transfer');
        $seller = $this->fake->listBalanceTransactions('acct_seller')['data'][0];
        $transfer = $this->fake->ledgerObject($id);

        expect($platform['amount'])->toBe(-6300)->and($platform['source']['id'])->toBe($id)->and($platform['source']['destination'])->toBe('acct_seller')
            ->and($platform['source']['metadata'])->toBe(['order_id' => '5', 'seller_order_id' => '9'])->and($platform['source']['source_transaction'])->toBe('ch_src')
            ->and($platform['source']['transfer_group'])->toBe('SDL-X')
            ->and($seller['type'])->toBe('payment')->and($seller['amount'])->toBe(6300)->and($seller['source']['source_transfer'])->toBe($id)
            ->and($seller['source']['id'])->toBe($transfer['destination_payment'])
            // the money on the seller account becomes available when the buyer's payment does
            ->and($seller['available_on'])->toBe(collect($this->fake->listBalanceTransactions(null)['data'])->firstWhere('type', 'charge')['available_on']);
    });

    it('books a transfer reversal on both accounts and keeps the transfer\'s counters', function () {
        $id = $this->fake->createTransfer(6300, 'acct_seller', 'SDL-X', null, []);
        $reversal = $this->fake->reverseTransfer($id, 2000, ['seller_order_id' => 9, 'idempotency_key' => 'k']);
        $this->fake->reverseTransfer($id, null);

        $platform = collect($this->fake->listBalanceTransactions(null)['data'])->where('type', 'transfer_refund')->sortBy('amount')->values();
        $seller = collect($this->fake->listBalanceTransactions('acct_seller')['data'])->where('type', 'payment_refund')->sortBy('amount')->values();
        $object = $this->fake->ledgerObject($id);

        expect($platform->pluck('amount')->all())->toBe([2000, 4300])->and($platform[0]['source']['transfer'])->toBe($id)
            ->and($seller->pluck('amount')->all())->toBe([-4300, -2000])->and($seller[1]['source']['source_transfer_reversal'])->toBe($reversal)
            ->and($this->fake->ledgerObject($reversal)['metadata'])->toBe(['seller_order_id' => '9'])
            ->and($object['amount_reversed'])->toBe(6300)->and($object['reversed'])->toBeTrue();
    });

    it('books a refund of a charge it knows, and none for a payment intent it does not', function () {
        $this->fake->recordCharge('ch_known', 5000, ['payment_intent' => 'pi_known']);

        $id = $this->fake->refund('pi_known', 1200, ['order_number' => 'SDL-X', 'idempotency_key' => 'k']);
        $this->fake->refund('pi_strange', 900);
        $refund = collect($this->fake->listBalanceTransactions(null)['data'])->firstWhere('type', 'refund');

        expect($refund['amount'])->toBe(-1200)->and($refund['fee'])->toBe(0)->and($refund['source']['id'])->toBe($id)->and($refund['source']['charge'])->toBe('ch_known')
            ->and($refund['source']['metadata'])->toBe(['order_number' => 'SDL-X'])
            ->and($this->fake->ledgerObject('ch_known')['amount_refunded'])->toBe(1200)
            ->and(collect($this->fake->listBalanceTransactions(null)['data'])->where('type', 'refund'))->toHaveCount(1);

        // all that is left
        $this->fake->refund('pi_known', null);
        expect($this->fake->ledgerObject('ch_known')['amount_refunded'])->toBe(5000)->and($this->fake->ledgerObject('ch_known')['refunded'])->toBeTrue();
    });

    it('computes the balance from the transactions: available follows available_on, per account and currency', function () {
        $this->fake->recordSubscriptionCharge(10000, 'ch_old', ['fee' => 0, 'created' => now()->subDays(5)]);
        $this->fake->recordSubscriptionCharge(2500, 'ch_new', ['fee' => 0]);
        $this->fake->recordPayout(null, 3000, ['status' => 'in_transit', 'created' => now()->subHour()]);

        $platform = $this->fake->retrieveBalance(null);
        $seller = $this->fake->retrieveBalance('acct_nobody');

        expect($platform['object'])->toBe('balance')->and($platform['livemode'])->toBeFalse()
            ->and($platform['available'])->toBe([['amount' => 7000, 'currency' => 'usd', 'source_types' => ['card' => 7000]]])
            ->and($platform['pending'][0]['amount'])->toBe(2500)
            ->and($platform['connect_reserved'])->toBe([['amount' => 0, 'currency' => 'usd']])
            ->and($seller['available'][0]['amount'])->toBe(0)->and($seller)->not->toHaveKey('connect_reserved');

        $this->travel(3)->days();
        expect($this->fake->retrieveBalance(null)['available'][0]['amount'])->toBe(9500)->and($this->fake->retrieveBalance(null)['pending'][0]['amount'])->toBe(0);
    });

    it('records the calls and fails on request like the other methods', function () {
        $this->fake->listBalanceTransactions('acct_x', 100, 'txn_9', 25);
        $this->fake->listPayouts(null);
        $this->fake->retrieveBalance('acct_x');
        expect($this->fake->lastCall('listBalanceTransactions'))->toBe(['account' => 'acct_x', 'createdSince' => 100, 'startingAfter' => 'txn_9', 'limit' => 25])
            ->and($this->fake->callCount('listPayouts'))->toBe(1)->and($this->fake->lastCall('retrieveBalance'))->toBe(['account' => 'acct_x']);

        $this->fake->failOn('retrieveBalance');
        expect(fn () => $this->fake->retrieveBalance(null))->toThrow(PaymentGatewayException::class);
        expect($this->fake->retrieveBalance(null)['object'])->toBe('balance');
    });

    it('forgets the ledger on reset', function () {
        $this->fake->recordSubscriptionCharge(1000, 'ch_gone');
        $this->fake->reset();

        expect($this->fake->listBalanceTransactions(null)['data'])->toBe([])->and($this->fake->ledgerObject('ch_gone'))->toBeNull();
    });
});
