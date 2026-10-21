<?php

use App\Models\Order;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Payments\Exceptions\InvalidWebhookException;
use App\Services\Payments\Exceptions\PaymentGatewayException;
use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\StripeGateway;
use Tests\Support\FakeStripeHttpClient;

beforeEach(function () {
    $this->http = FakeStripeHttpClient::install();
    $this->gateway = new StripeGateway('sk_test_unit');
    config(['marketplace.currency' => 'usd', 'marketplace.stripe_tax' => false]);
});

afterEach(function () {
    FakeStripeHttpClient::uninstall();
});

it('binds the fake gateway in the test environment', function () {
    expect(app(PaymentGateway::class))->toBeInstanceOf(FakePaymentGateway::class)
        ->and(app(PaymentGateway::class))->toBe(app(PaymentGateway::class));
});

it('creates an Express account with card_payments and transfers for the seller country', function () {
    $user = User::factory()->create(['email' => 'shop@example.test']);
    $profile = SellerProfile::factory()->withoutStripe()->for($user)->create(['country' => 'DE', 'shop_name' => 'Klang Werkstatt']);
    $this->http->respond('POST /v1/accounts', ['id' => 'acct_new_1', 'object' => 'account']);

    $id = $this->gateway->createConnectedAccount($profile);

    $request = $this->http->last('POST /v1/accounts');
    expect($id)->toBe('acct_new_1')
        ->and($request['params'])->toMatchArray(['type' => 'express', 'country' => 'DE', 'email' => 'shop@example.test'])
        ->and($request['params']['capabilities'])->toBe(['card_payments' => ['requested' => 'true'], 'transfers' => ['requested' => 'true']])
        ->and($request['headers']['idempotency-key'])->toBe('seller-profile-'.$profile->id.'-account');
});

it('creates onboarding and login links and reads the account status', function () {
    $this->http
        ->respond('POST /v1/account_links', ['object' => 'account_link', 'url' => 'https://connect.stripe.com/setup/e/abc'])
        ->respond('POST /v1/accounts/acct_9/login_links', ['object' => 'login_link', 'url' => 'https://connect.stripe.com/express/xyz'])
        ->respond('GET /v1/accounts/acct_9', ['id' => 'acct_9', 'object' => 'account', 'charges_enabled' => true, 'payouts_enabled' => false, 'details_submitted' => true]);

    expect($this->gateway->createOnboardingLink('acct_9', 'https://app.test/return', 'https://app.test/refresh'))->toBe('https://connect.stripe.com/setup/e/abc')
        ->and($this->http->last('POST /v1/account_links')['params'])->toMatchArray([
            'account' => 'acct_9', 'type' => 'account_onboarding', 'return_url' => 'https://app.test/return', 'refresh_url' => 'https://app.test/refresh',
        ])
        ->and($this->gateway->createLoginLink('acct_9'))->toBe('https://connect.stripe.com/express/xyz')
        ->and($this->gateway->retrieveAccountStatus('acct_9'))->toBe(['charges_enabled' => true, 'payouts_enabled' => false, 'details_submitted' => true]);
});

it('creates a payment-mode Checkout Session tied to the order by transfer group and metadata', function () {
    $buyer = User::factory()->create(['email' => 'buyer@example.test']);
    $address = addressFor($buyer, 'DE');
    $order = Order::factory()->for($buyer, 'user')->create(['shipping_address' => $address->toSnapshot()]);
    $this->http->respond('POST /v1/checkout/sessions', ['id' => 'cs_live_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_live_1']);
    $lines = [['quantity' => 1, 'price_data' => ['currency' => 'usd', 'unit_amount' => 1500, 'product_data' => ['name' => 'Thing']]]];

    $session = $this->gateway->createCheckoutSession($order, $lines, 'https://app.test/ok?session_id={CHECKOUT_SESSION_ID}', 'https://app.test/no');

    $request = $this->http->last('POST /v1/checkout/sessions');
    $params = $request['params'];
    expect($session)->toBe(['id' => 'cs_live_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_live_1'])
        ->and($params['mode'])->toBe('payment')
        ->and($params['line_items'])->toBe($lines)
        ->and($params['success_url'])->toBe('https://app.test/ok?session_id={CHECKOUT_SESSION_ID}')
        ->and($params['cancel_url'])->toBe('https://app.test/no')
        ->and($params['customer_email'])->toBe('buyer@example.test')
        ->and($params['client_reference_id'])->toBe($order->number)
        ->and($params['metadata'])->toBe(['order_id' => (string) $order->id, 'order_number' => $order->number])
        ->and($params['payment_intent_data']['transfer_group'])->toBe($order->number)
        ->and($params['payment_intent_data']['metadata']['order_id'])->toBe((string) $order->id)
        ->and($params['payment_intent_data']['shipping']['address']['country'])->toBe('DE')
        ->and($params)->not->toHaveKey('automatic_tax')
        ->and($request['headers']['idempotency-key'])->toBe('checkout-order-'.$order->number);
});

it('turns on automatic tax when configured', function () {
    config(['marketplace.stripe_tax' => true]);
    $order = Order::factory()->create();
    $this->http->respond('POST /v1/checkout/sessions', ['id' => 'cs_t', 'object' => 'checkout.session', 'url' => 'https://x']);

    $this->gateway->createCheckoutSession($order, [], 'https://s', 'https://c');

    expect($this->http->last('POST /v1/checkout/sessions')['params']['automatic_tax'])->toBe(['enabled' => 'true'])
        ->and($this->http->last('POST /v1/checkout/sessions')['params']['billing_address_collection'])->toBe('required');
});

it('transfers with source_transaction and an idempotency key per seller order', function () {
    $this->http->respond('POST /v1/transfers', ['id' => 'tr_77', 'object' => 'transfer']);

    $id = $this->gateway->createTransfer(1800, 'acct_dest', 'SDL-ABC123', 'ch_src', ['order_id' => 5, 'seller_order_id' => 9, 'order_number' => 'SDL-ABC123']);

    $request = $this->http->last('POST /v1/transfers');
    expect($id)->toBe('tr_77')
        ->and($request['params'])->toMatchArray(['amount' => 1800, 'currency' => 'usd', 'destination' => 'acct_dest', 'source_transaction' => 'ch_src'])
        ->and($request['params'])->not->toHaveKey('transfer_group')
        ->and($request['params']['metadata'])->toMatchArray(['seller_order_id' => 9, 'order_number' => 'SDL-ABC123'])
        ->and($request['headers']['idempotency-key'])->toBe('transfer-seller-order-9');

    $this->gateway->createTransfer(500, 'acct_dest', 'SDL-ABC123', null, ['seller_order_id' => 10]);
    expect($this->http->last('POST /v1/transfers')['params'])->toMatchArray(['transfer_group' => 'SDL-ABC123'])
        ->and($this->http->last('POST /v1/transfers')['params'])->not->toHaveKey('source_transaction');
});

it('refunds by payment intent and reverses transfers', function () {
    $this->http
        ->respond('POST /v1/refunds', ['id' => 're_1', 'object' => 'refund'])
        ->respond('POST /v1/transfers/tr_1/reversals', ['id' => 'trr_1', 'object' => 'transfer_reversal']);

    $refund = $this->gateway->refund('pi_1', 3000, ['order_number' => 'SDL-X', 'idempotency_key' => 'refund-seller-order-4']);
    $full = $this->gateway->refund('pi_1', null);
    $reversal = $this->gateway->reverseTransfer('tr_1', 2700, ['idempotency_key' => 'reverse-seller-order-4']);

    expect($refund)->toBe('re_1')
        ->and($reversal)->toBe('trr_1');

    $refunds = array_values(array_filter($this->http->requests, fn ($r) => $r['path'] === '/v1/refunds'));
    expect($refunds[0]['params'])->toMatchArray(['payment_intent' => 'pi_1', 'amount' => 3000])
        ->and($refunds[0]['params']['metadata'])->toBe(['order_number' => 'SDL-X'])
        ->and($refunds[0]['headers']['idempotency-key'])->toBe('refund-seller-order-4')
        ->and($refunds[1]['params'])->not->toHaveKey('amount')
        ->and($this->http->last('POST /v1/transfers/tr_1/reversals')['params']['amount'])->toBe(2700)
        ->and($this->http->last('POST /v1/transfers/tr_1/reversals')['headers']['idempotency-key'])->toBe('reverse-seller-order-4');
});

it('finds the charge of a payment intent', function () {
    $this->http->respond('GET /v1/payment_intents/pi_5', ['id' => 'pi_5', 'object' => 'payment_intent', 'latest_charge' => 'ch_5']);

    expect($this->gateway->findChargeId('pi_5'))->toBe('ch_5');

    $this->http->respond('GET /v1/payment_intents/pi_6', ['id' => 'pi_6', 'object' => 'payment_intent', 'latest_charge' => null]);
    expect($this->gateway->findChargeId('pi_6'))->toBeNull();
});

it('verifies webhook signatures', function () {
    $payload = json_encode(stripeEventPayload('account.updated', ['id' => 'acct_1', 'object' => 'account']));
    $header = Stripe\WebhookSignature::generateSignatureHeader($payload, 'whsec_x');

    $event = $this->gateway->constructWebhookEvent($payload, $header, 'whsec_x');
    expect($event->type)->toBe('account.updated')->and($event->data->object->id)->toBe('acct_1');

    expect(fn () => $this->gateway->constructWebhookEvent($payload, $header, 'whsec_other'))->toThrow(InvalidWebhookException::class);
    expect(fn () => $this->gateway->constructWebhookEvent('not json', Stripe\WebhookSignature::generateSignatureHeader('not json', 'whsec_x'), 'whsec_x'))->toThrow(InvalidWebhookException::class);
});

it('wraps Stripe API errors in a PaymentGatewayException', function () {
    $this->http->respond('POST /v1/refunds', ['error' => ['type' => 'invalid_request_error', 'message' => 'Charge already refunded.']], 400);

    expect(fn () => $this->gateway->refund('pi_1', null))->toThrow(PaymentGatewayException::class, 'Charge already refunded.');
});

it('fails with a clear error when no secret key is configured', function () {
    $gateway = new StripeGateway(null);

    expect(fn () => $gateway->createLoginLink('acct_1'))->toThrow(PaymentGatewayException::class, 'STRIPE_SECRET_KEY');
    expect($this->http->requests)->toBe([]);
});

it('records calls and fails on demand in the fake gateway', function () {
    $fake = new FakePaymentGateway;

    expect($fake->createLoginLink('acct_1'))->toBe('https://connect.stripe.test/express/acct_1')
        ->and($fake->callCount('createLoginLink'))->toBe(1)
        ->and($fake->retrieveAccountStatus('acct_1'))->toBe(['charges_enabled' => true, 'payouts_enabled' => true, 'details_submitted' => true]);

    $fake->setAccountStatus('acct_2', false, false, true)->failOn('refund');
    expect($fake->retrieveAccountStatus('acct_2')['payouts_enabled'])->toBeFalse()
        ->and(fn () => $fake->refund('pi_1', 100))->toThrow(PaymentGatewayException::class)
        ->and($fake->refund('pi_1', 100))->toBe('re_fake_1');
});
