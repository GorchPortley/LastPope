<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Enums\SellerOrderStatus;
use App\Models\Order;
use App\Models\SellerOrder;
use App\Services\Marketplace\MarginReport;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Marketplace numbers at the top of the admin dashboard. */
class MarketplaceStats extends StatsOverviewWidget
{
    protected static ?int $sort = -10;

    protected ?string $heading = 'Marketplace';

    protected function getStats(): array
    {
        $since = now()->subDays(30);
        $paid = fn () => Order::query()->whereNotNull('paid_at')->whereNotIn('status', [OrderStatus::CANCELLED->value, OrderStatus::PENDING->value]);

        $today = (clone $paid())->where('paid_at', '>=', now()->startOfDay())->count();
        $thirty = (clone $paid())->where('paid_at', '>=', $since);

        // Money earned by sellers that has not been transferred yet (paid, not refunded or cancelled).
        $pending = SellerOrder::query()
            ->whereNull('transferred_at')
            ->whereIn('status', [
                SellerOrderStatus::PAID->value, SellerOrderStatus::AWAITING_SHIPMENT->value, SellerOrderStatus::PARTIALLY_SHIPPED->value, SellerOrderStatus::SHIPPED->value,
                SellerOrderStatus::DELIVERED->value, SellerOrderStatus::COMPLETED->value,
            ]);

        // Stripe's fee: what Stripe charged where the ledger sync has read it, an estimate (marked) for the orders without it yet.
        [$stripeFees, $estimated, $paidCount] = static::stripeFees(clone $thirty);

        return [
            Stat::make('Orders today', $today)
                ->icon('heroicon-o-shopping-bag')
                ->description('paid since midnight'),
            Stat::make('GMV, 30 days', money((int) (clone $thirty)->sum('total_cents')))
                ->icon('heroicon-o-banknotes')
                ->description((clone $thirty)->count().' paid orders'),
            Stat::make('Platform fees, 30 days', money((int) (clone $thirty)->sum('platform_fee_cents')))
                ->icon('heroicon-o-building-library')
                ->description('commission earned'),
            Stat::make($estimated > 0 ? 'Stripe fees, 30 days (est.)' : 'Stripe fees, 30 days', money($stripeFees))
                ->icon('heroicon-o-credit-card')
                ->description($paidCount === 0 ? 'no paid orders' : ($estimated > 0 ? ($paidCount - $estimated).' of '.$paidCount.' orders charged, '.$estimated.' estimated' : 'charged by Stripe')),
            Stat::make('Pending payouts', money((int) $pending->sum('payout_cents')))
                ->icon('heroicon-o-clock')
                ->description($pending->count().' seller orders waiting for shipment, the payout delay or a problem'),
        ];
    }

    /**
     * The Stripe fees of the paid orders in the query: the fee Stripe charged (orders.stripe_fee_cents) where it is known, the
     * margin report's estimate for the others.
     *
     * @return array{0: int, 1: int, 2: int} cents, orders with an estimated fee, orders
     */
    public static function stripeFees(\Illuminate\Database\Eloquent\Builder $orders): array
    {
        $report = app(MarginReport::class);
        $total = (int) (clone $orders)->whereNotNull('stripe_fee_cents')->sum('stripe_fee_cents');
        $estimated = 0;

        (clone $orders)->whereNull('stripe_fee_cents')->select(['id', 'subtotal_cents', 'shipping_cents'])->chunkById(500, function ($chunk) use (&$total, &$estimated, $report) {
            foreach ($chunk as $order) {
                $total += $report->stripeFeeCents($order->subtotal_cents + $order->shipping_cents);
                $estimated++;
            }
        });

        return [$total, $estimated, (clone $orders)->count()];
    }
}
