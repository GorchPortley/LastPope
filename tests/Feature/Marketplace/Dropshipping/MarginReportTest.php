<?php

use App\Enums\SupplierOrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SupplierOrder;
use App\Models\User;
use App\Services\Marketplace\MarginReport;
use App\Services\Marketplace\PurchaseOrderService;
use App\Services\Marketplace\RefundService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/*
| R3.4: the margin report of the store: per paid order and in total, revenue (items + shipping charged), goods cost,
| supplier shipping, dropship fees, an estimate of Stripe's fee, refunds, margin and margin %.
*/

beforeEach(function () {
    Mail::fake();
    Notification::fake();
    // far from the demo data, so the report of "today" holds only what a test creates
    $this->travelTo(now()->addYears(2)->setTime(12, 0));
    config(['marketplace.fee_bps' => 1000, 'marketplace.stripe_fee_bps' => 290, 'marketplace.stripe_fee_fixed_cents' => 30]);
    $this->buyer = User::factory()->create();
    $this->admin = adminUser();
    $this->acme = supplier(['dropship_fee_cents' => 250, 'shipping_domestic_cents' => 899, 'additional_item_shipping_cents' => 300]);
    $this->report = app(MarginReport::class);
    $this->pos = app(PurchaseOrderService::class);
});

/**
 * Own stock (8000, cost 5000, shipping 900) + 2 Acme units (10000, cost 6000; shipping 899 + 300) from the store, plus a
 * member's kit that is none of the store's business. Items 28000, shipping 2099, revenue 30099.
 */
function knownOrder(object $test): Order
{
    $stock = storeListing(['price_cents' => 8000, 'cost_cents' => 5000, 'quantity' => 5, 'shipping_domestic_cents' => 900, 'additional_item_shipping_cents' => 0], null, $test->admin);
    $dropped = dropshipListing($test->acme, ['price_cents' => 10000, 'cost_cents' => 6000, 'supplier_stock' => 9], $test->admin);
    $kit = sellableListing(onboardedSeller(), 15000, ['quantity' => 3]);

    return paidOrderOf($test->buyer, [[$stock, 1], [$dropped, 2], [$kit, 1]]);
}

function rangeToday(): array
{
    return [now()->startOfDay(), now()->endOfDay()];
}

it('computes revenue, costs, the Stripe estimate and the margin of a known order', function () {
    $order = knownOrder($this);
    $po = purchaseOrderOf($order);
    $this->pos->recordSupplierCosts($this->admin, $po, 1000);

    [$from, $to] = rangeToday();
    $report = $this->report->forRange($from, $to);

    expect($report['rows'])->toHaveCount(1);
    $row = $report['rows'][0];

    // 8000 + 2 x 10000 items, 900 + 899 + 300 shipping charged by the store; the member's kit is not the store's revenue
    expect($row->orderNumber)->toBe($order->number)
        ->and($row->itemsCents)->toBe(28000)
        ->and($row->shippingCents)->toBe(900 + 899 + 300)
        ->and($row->revenueCents())->toBe(30099)
        ->and($row->refundsCents)->toBe(0)
        ->and($row->goodsCostCents)->toBe(5000 + 2 * 6000)
        ->and($row->supplierShippingCents)->toBe(1000)
        ->and($row->dropshipFeesCents)->toBe(250)
        // 30099 x 2.9 % = 872.87 -> 873, + 30
        ->and($row->stripeFeeCents)->toBe(903)
        ->and($row->costsPending)->toBeFalse()
        ->and($row->marginCents())->toBe(30099 - 17000 - 1000 - 250 - 903)
        ->and($row->marginBps())->toBe(3637)
        ->and($row->marginPercent())->toBe(36.4);

    // the totals row is the sum of the rows
    expect($report['totals']->revenueCents())->toBe(30099)->and($report['totals']->marginCents())->toBe(10946)->and($report['totals']->orders)->toBe(1);
});

it('subtracts refunds and keeps Stripe\'s fee', function () {
    $order = knownOrder($this);
    $this->pos->recordSupplierCosts($this->admin, purchaseOrderOf($order), 1000);
    app(RefundService::class)->refundSellerOrderPartially($this->admin, $order->sellerOrders->firstWhere('seller_id', $this->admin->id), 2000, 'Scratch');

    [$from, $to] = rangeToday();
    $row = $this->report->forRange($from, $to)['rows'][0];

    expect($row->refundsCents)->toBe(2000)
        ->and($row->revenueCents())->toBe(30099)
        ->and($row->netRevenueCents())->toBe(28099)
        ->and($row->stripeFeeCents)->toBe(903)
        ->and($row->marginCents())->toBe(10946 - 2000)
        ->and($row->marginBps())->toBe((int) round((10946 - 2000) * 10000 / 28099));
});

it('counts a purchase order cancelled before it was sent as no cost, and a fully refunded order as lost fees', function () {
    $order = knownOrder($this);
    $storeSellerOrder = $order->sellerOrders->firstWhere('seller_id', $this->admin->id);

    app(RefundService::class)->refundSellerOrder($this->admin, $storeSellerOrder, 'Changed mind');

    [$from, $to] = rangeToday();
    $row = $this->report->forRange($from, $to)['rows'][0];

    expect(purchaseOrderOf($order)->status)->toBe(SupplierOrderStatus::CANCELLED)
        ->and($row->refundsCents)->toBe(30099)
        ->and($row->goodsCostCents)->toBe(5000) // the own-stock unit is still ours; the supplier line was never bought
        ->and($row->supplierShippingCents)->toBe(0)
        ->and($row->dropshipFeesCents)->toBe(0)
        ->and($row->netRevenueCents())->toBe(0)
        ->and($row->marginBps())->toBeNull() // nothing was kept: no percentage
        ->and($row->marginCents())->toBe(-5000 - 903);
});

it('flags rows whose supplier shipping or cost is not known yet', function () {
    $order = knownOrder($this);

    [$from, $to] = rangeToday();
    // supplier shipping not entered yet
    expect($this->report->forRange($from, $to)['rows'][0]->costsPending)->toBeTrue();

    $this->pos->recordSupplierCosts($this->admin, purchaseOrderOf($order), 0);
    expect($this->report->forRange($from, $to)['rows'][0]->costsPending)->toBeFalse();

    // a listing without cost: the order item has no snapshot and the listing none to fall back on
    $unknown = storeListing(['price_cents' => 5000, 'cost_cents' => null, 'quantity' => 2], null, $this->admin);
    paidOrderOf(User::factory()->create(), [[$unknown, 1]]);
    $rows = $this->report->forRange($from, $to)['rows'];
    expect($rows)->toHaveCount(2)->and($rows->last()->costsPending)->toBeTrue()->and($rows->last()->goodsCostCents)->toBe(0)
        ->and($this->report->forRange($from, $to)['totals']->costsPending)->toBeTrue();
});

it('falls back to the listing\'s current cost for order lines from before the cost snapshot', function () {
    $listing = storeListing(['price_cents' => 8000, 'cost_cents' => 4500, 'quantity' => 5], null, $this->admin);
    $order = paidOrderOf($this->buyer, [[$listing, 2]]);
    OrderItem::where('order_id', $order->id)->update(['unit_cost_cents' => null]);

    [$from, $to] = rangeToday();
    expect($this->report->forRange($from, $to)['rows'][0]->goodsCostCents)->toBe(9000);

    // and the snapshot wins over a later change of the listing's cost
    $listing->update(['cost_cents' => 7000]);
    $order2 = paidOrderOf(User::factory()->create(), [[$listing->fresh(), 1]]);
    $listing->update(['cost_cents' => 1]);
    $row = $this->report->forRange($from, $to)['rows']->firstWhere('orderNumber', $order2->number);
    expect($row->goodsCostCents)->toBe(7000);
});

it('reports only the orders paid in the date range, oldest first, and totals them', function () {
    $first = knownOrder($this);
    $this->travel(2)->days();
    $second = paidOrderOf($this->buyer, [[storeListing(['price_cents' => 8000, 'cost_cents' => 5000, 'quantity' => 5, 'shipping_domestic_cents' => 0], null, $this->admin), 1]]);

    $today = now()->startOfDay();
    $both = $this->report->forRange($today->copy()->subDays(2), $today->copy()->endOfDay());
    $onlySecond = $this->report->forRange($today, $today->copy()->endOfDay());
    $none = $this->report->forRange($today->copy()->subDays(10), $today->copy()->subDays(5));

    expect($both['rows']->pluck('orderNumber')->all())->toBe([$first->number, $second->number])
        ->and($both['totals']->orders)->toBe(2)
        ->and($both['totals']->itemsCents)->toBe(28000 + 8000)
        ->and($both['totals']->goodsCostCents)->toBe(17000 + 5000)
        ->and($onlySecond['rows']->pluck('orderNumber')->all())->toBe([$second->number])
        ->and($none['rows'])->toHaveCount(0)
        ->and($none['totals']->revenueCents())->toBe(0);
});

it('leaves unpaid and cancelled orders and marketplace-only orders out', function () {
    pendingOrder($this->buyer, [[storeListing(['quantity' => 3], null, $this->admin), 1]], addressFor($this->buyer));
    paidOrderOf(User::factory()->create(), [[sellableListing(onboardedSeller(), 9000, ['quantity' => 2]), 1]]);

    [$from, $to] = rangeToday();
    expect($this->report->forRange($from, $to)['rows'])->toHaveCount(0);
});

it('exports the report as CSV with a totals row', function () {
    $order = knownOrder($this);
    $this->pos->recordSupplierCosts($this->admin, purchaseOrderOf($order), 1000);

    [$from, $to] = rangeToday();
    $csv = $this->report->toCsv($from, $to);
    $rows = array_map('str_getcsv', array_filter(explode("\n", $csv)));

    expect($rows[0])->toBe(['Order', 'Paid', 'Items', 'Shipping charged', 'Revenue', 'Refunds', 'Goods cost', 'Supplier shipping', 'Dropship fees', 'Stripe fee (est.)', 'Margin', 'Margin %', 'Costs pending'])
        ->and($rows[1])->toBe([$order->number, now()->format('Y-m-d'), '280.00', '20.99', '300.99', '0.00', '170.00', '10.00', '2.50', '9.03', '109.46', '36.4', ''])
        ->and($rows[2][0])->toBe('Total (1 orders)')
        ->and($rows[2][10])->toBe('109.46');
});

it('estimates Stripe\'s fee from the config', function () {
    config(['marketplace.stripe_fee_bps' => 150, 'marketplace.stripe_fee_fixed_cents' => 25]);

    expect($this->report->stripeFeeCents(10000))->toBe(175)
        ->and($this->report->stripeFeeCents(0))->toBe(0);
});

it('gives the unit margin of a price and cost, and the low-margin warning', function () {
    config(['marketplace.min_margin_bps' => 1500]);

    expect(MarginReport::unitMargin(10000, 8500))->toBe(['cents' => 1500, 'bps' => 1500])
        ->and(MarginReport::unitMargin(10000, null))->toBeNull()
        ->and(MarginReport::unitMargin(0, 100))->toBeNull()
        ->and(MarginReport::lowMarginWarning(10000, 8500))->toBeNull()
        ->and(MarginReport::lowMarginWarning(10000, 8600))->toContain('Low margin')->toContain('14.0 %')->toContain('15.0 %')
        ->and(MarginReport::lowMarginWarning(10000, 11000))->toContain('loss');

    config(['marketplace.min_margin_bps' => 3000]);
    expect(MarginReport::lowMarginWarning(10000, 8500))->toContain('Low margin');
});
