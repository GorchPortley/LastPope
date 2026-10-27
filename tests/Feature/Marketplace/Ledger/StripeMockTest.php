<?php

use App\Models\StripeBalance;
use App\Models\StripeBalanceTransaction;
use App\Models\StripePayout;
use App\Services\Marketplace\Ledger\LedgerLinker;
use App\Services\Marketplace\Ledger\LedgerSync;
use App\Services\Payments\StripeGateway;
use Stripe\StripeClient;

/*
| Opt-in: the real StripeGateway against stripe-mock (https://github.com/stripe/stripe-mock), which validates every request against
| Stripe's OpenAPI spec and answers with objects shaped like the real API's. Skipped unless STRIPE_MOCK_URL is set:
|
|     docker run -d --rm -p 12111:12111 stripe/stripe-mock
|     STRIPE_MOCK_URL=http://localhost:12111 php artisan test --filter=StripeMock
|
| A request with a parameter Stripe does not know, or a response shaped differently from what LedgerSync reads, fails here.
*/

beforeEach(function () {
    $url = getenv('STRIPE_MOCK_URL');

    if (! $url) {
        $this->markTestSkipped('Set STRIPE_MOCK_URL to run against stripe-mock.');
    }

    $this->gateway = new StripeGateway('sk_test_123', new StripeClient(['api_key' => 'sk_test_123', 'api_base' => $url]));
});

it('lists balance transactions with the parameters the sync sends, for the platform and for a connected account', function () {
    foreach ([null, 'acct_1PgafTB7WZ01zgkW'] as $account) {
        $page = $this->gateway->listBalanceTransactions($account, 1700000000, null, 25);

        expect($page['has_more'])->toBeBool()->and($page['data'])->not->toBeEmpty();
        $bt = $page['data'][0];
        expect($bt['object'])->toBe('balance_transaction')->and($bt['id'])->toStartWith('txn_')->and($bt['amount'])->toBeInt()
            ->and($bt['source'])->toBeArray();     // expanded by expand[]=data.source
    }

    $paged = $this->gateway->listBalanceTransactions(null, null, 'txn_1PgaxNB7WZ01zgkWEV3TLf40', 100);
    expect($paged)->toHaveKeys(['data', 'has_more']);
});

it('lists payouts with the destination parameters and retrieves the balance', function () {
    $payouts = $this->gateway->listPayouts('acct_1PgafTB7WZ01zgkW', 1700000000, null, 100);
    expect($payouts['data'])->not->toBeEmpty()->and($payouts['data'][0]['object'])->toBe('payout')->and($payouts['data'][0]['status'])->toBeString();

    $balance = $this->gateway->retrieveBalance(null);
    expect($balance['object'])->toBe('balance')->and($balance['available'][0])->toHaveKeys(['amount', 'currency'])->and($balance['pending'])->toBeArray()->and($balance['livemode'])->toBeFalse();
    expect($this->gateway->retrieveBalance('acct_1PgafTB7WZ01zgkW')['object'])->toBe('balance');
});

it('reads what stripe-mock answers all the way into the mirror', function () {
    $sync = new LedgerSync($this->gateway, app(LedgerLinker::class));

    $result = $sync->syncAccount(null);

    expect($result->complete)->toBeTrue()->and($result->transactions)->toBeGreaterThan(0)->and($result->payouts)->toBeGreaterThan(0)
        ->and(StripeBalanceTransaction::count())->toBeGreaterThan(0)
        ->and(StripePayout::count())->toBeGreaterThan(0)
        ->and(StripeBalance::where('account_key', 'platform')->count())->toBeGreaterThan(0);

    // stripe-mock's transaction is an application fee: not about our orders
    $row = StripeBalanceTransaction::firstOrFail();
    expect($row->amount_cents)->toBeInt()->and($row->currency)->toBe('usd')->and($row->link_status->value)->toBe('other')->and($row->source_type)->toBe('application_fee');
});
