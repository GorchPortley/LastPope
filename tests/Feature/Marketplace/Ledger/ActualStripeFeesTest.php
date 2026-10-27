<?php

use App\Filament\Pages\Margins;
use App\Filament\Widgets\MarketplaceStats;
use App\Models\Order;
use App\Models\User;
use App\Services\Marketplace\MarginReport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
| R6.4: the margin report and the admin stats use the Stripe fee Stripe actually charged (orders.stripe_fee_cents, read from the
| charge's balance transaction); only an order without a known fee gets the estimate, and it is labelled "(est.)" everywhere.
*/

beforeEach(function () {
    Mail::fake();
    Notification::fake();
    // far from the demo data, so the report of "today" holds only what a test creates
    $this->travelTo(now()->addYears(2)->setTime(12, 0));
    config(['marketplace.fee_bps' => 1000, 'marketplace.stripe_fee_bps' => 290, 'marketplace.stripe_fee_fixed_cents' => 30]);
    $this->buyer = User::factory()->create();
    $this->admin = adminUser();
    $this->report = app(MarginReport::class);
});

/** A store order for 10000 + 900 shipping (revenue 10900) and nothing else. */
function storeOnlyOrder(object $test, array $listing = []): Order
{
    $stock = storeListing(array_merge(['price_cents' => 10000, 'cost_cents' => 6000, 'quantity' => 5, 'shipping_domestic_cents' => 900], $listing), null, $test->admin);

    return paidOrderOf($test->buyer, [[$stock, 1]]);
}

function feeRange(): array
{
    return [now()->startOfDay(), now()->endOfDay()];
}

it('uses the fee Stripe charged and marks nothing as an estimate', function () {
    $order = storeOnlyOrder($this);
    $order->forceFill(['stripe_fee_cents' => 351])->save();     // the estimate would be 346

    [$from, $to] = feeRange();
    $report = $this->report->forRange($from, $to);
    $row = $report['rows'][0];

    expect($row->stripeFeeCents)->toBe(351)->and($row->stripeFeeIsEstimate())->toBeFalse()->and($row->estimatedFees)->toBe(0)
        ->and($report['totals']->stripeFeeCents)->toBe(351)->and($report['totals']->stripeFeeIsEstimate())->toBeFalse()
        ->and($row->marginCents())->toBe(10900 - 6000 - 351);
});

it('gives the store its share of the fee of an order it shares with marketplace sellers', function () {
    $store = storeListing(['price_cents' => 8000, 'cost_cents' => 5000, 'quantity' => 5, 'shipping_domestic_cents' => 900], null, $this->admin);
    $kit = sellableListing(onboardedSeller(), 15000, ['quantity' => 3, 'shipping_domestic_cents' => 1100]);
    $order = paidOrderOf($this->buyer, [[$store, 1], [$kit, 1]]);
    // items 23000 + shipping 2000 = 25000 charged, 8900 of it the store's
    $order->forceFill(['stripe_fee_cents' => 1000])->save();

    [$from, $to] = feeRange();
    $row = $this->report->forRange($from, $to)['rows'][0];

    expect($order->fresh()->subtotal_cents + $order->shipping_cents)->toBe(25000)
        ->and($row->revenueCents())->toBe(8900)
        // 1000 x 8900 / 25000 = 356
        ->and($row->stripeFeeCents)->toBe(356)->and($row->stripeFeeIsEstimate())->toBeFalse()
        ->and($this->report->stripeFee($order->fresh(), 8900))->toBe([356, false]);
});

it('estimates the fee of an order Stripe has not reported yet, and labels it', function () {
    $actual = storeOnlyOrder($this);
    $actual->forceFill(['stripe_fee_cents' => 351])->save();
    $estimated = storeOnlyOrder($this);

    [$from, $to] = feeRange();
    $report = $this->report->forRange($from, $to);
    $byNumber = $report['rows']->keyBy('orderNumber');

    expect($byNumber[$actual->number]->stripeFeeIsEstimate())->toBeFalse()
        // 10900 x 2.9 % = 316.1 -> 316 + 30
        ->and($byNumber[$estimated->number]->stripeFeeCents)->toBe(346)->and($byNumber[$estimated->number]->stripeFeeIsEstimate())->toBeTrue()
        ->and($report['totals']->stripeFeeCents)->toBe(351 + 346)->and($report['totals']->estimatedFees)->toBe(1)->and($report['totals']->stripeFeeIsEstimate())->toBeTrue();
});

it('labels the estimate in the CSV: the header and a column that says which orders', function () {
    $actual = storeOnlyOrder($this);
    $actual->forceFill(['stripe_fee_cents' => 351])->save();
    [$from, $to] = feeRange();

    $rows = array_map('str_getcsv', array_filter(explode("\n", $this->report->toCsv($from, $to))));
    expect($rows[0][9])->toBe('Stripe fee')->and($rows[0][13])->toBe('Stripe fee estimated')
        ->and($rows[1][9])->toBe('3.51')->and($rows[1][13])->toBe('')->and($rows[2][13])->toBe('');

    $estimated = storeOnlyOrder($this);
    $rows = collect(array_map('str_getcsv', array_filter(explode("\n", $this->report->toCsv($from, $to)))));

    expect($rows[0][9])->toBe('Stripe fee (est.)')
        ->and($rows->firstWhere(0, $estimated->number)[13])->toBe('yes')
        ->and($rows->firstWhere(0, $actual->number)[13])->toBe('')
        ->and($rows->last()[13])->toBe('yes');
});

it('shows on the Margins page what is actual and what is estimated', function () {
    $actual = storeOnlyOrder($this);
    $actual->forceFill(['stripe_fee_cents' => 351])->save();

    Livewire::actingAs($this->admin)->test(Margins::class)
        ->assertSee('Stripe fees')->assertDontSee('Stripe fees (est.)')->assertDontSeeHtml('data-fee-estimate')->assertSee('$3.51')
        ->assertSee('charged by Stripe');

    $estimated = storeOnlyOrder($this);

    Livewire::actingAs($this->admin)->test(Margins::class)
        ->assertSee('Stripe fees (est.)')
        ->assertSee('1 of 2 orders charged by Stripe, 1 estimated')
        ->assertSeeHtml('data-fee-estimate')
        ->assertSee('$6.97');       // 351 + 346
});

it('adds the Stripe fees to the admin stats, estimated only for orders without a known fee', function () {
    $first = storeOnlyOrder($this);
    $first->forceFill(['stripe_fee_cents' => 351])->save();

    Livewire::actingAs($this->admin)->test(MarketplaceStats::class)
        ->assertSee('Stripe fees, 30 days')->assertDontSee('Stripe fees, 30 days (est.)')->assertSee('charged by Stripe')->assertSee('$3.51');

    storeOnlyOrder($this);

    Livewire::actingAs($this->admin)->test(MarketplaceStats::class)
        ->assertSee('Stripe fees, 30 days (est.)')->assertSee('1 of 2 orders charged, 1 estimated')->assertSee('$6.97');

    expect(MarketplaceStats::stripeFees(Order::query()->whereNotNull('paid_at')->where('paid_at', '>=', now()->subDay())))->toBe([697, 1, 2]);
});

it('takes the fee from the ledger: sync it, and the report stops estimating', function () {
    $order = storeOnlyOrder($this);
    fakeGateway()->recordOrderCharge($order->fresh(), ['fee' => 412]);

    [$from, $to] = feeRange();
    expect($this->report->forRange($from, $to)['rows'][0]->stripeFeeIsEstimate())->toBeTrue();

    syncLedger();

    $row = $this->report->forRange($from, $to)['rows'][0];
    expect($order->fresh()->stripe_fee_cents)->toBe(412)->and($row->stripeFeeCents)->toBe(412)->and($row->stripeFeeIsEstimate())->toBeFalse();
});

it('keeps a refunded order\'s fee: Stripe does not give it back', function () {
    $order = storeOnlyOrder($this);
    fakeGateway()->recordOrderCharge($order->fresh(), ['fee' => 412]);
    app(App\Services\Marketplace\RefundService::class)->refundOrder($this->admin, $order->fresh());
    syncLedger();

    [$from, $to] = feeRange();
    $row = $this->report->forRange($from, $to)['rows'][0];

    expect($row->refundsCents)->toBe(10900)->and($row->stripeFeeCents)->toBe(412)->and($row->stripeFeeIsEstimate())->toBeFalse()
        ->and($row->marginCents())->toBe(0 - 6000 - 412);   // everything given back; the goods cost and Stripe's fee are lost
});
