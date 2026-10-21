<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Enums\SellerOrderStatus;
use App\Models\Order;
use App\Models\SellerOrder;
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
                SellerOrderStatus::PAID->value, SellerOrderStatus::AWAITING_SHIPMENT->value, SellerOrderStatus::SHIPPED->value,
                SellerOrderStatus::DELIVERED->value, SellerOrderStatus::COMPLETED->value,
            ]);

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
            Stat::make('Pending payouts', money((int) $pending->sum('payout_cents')))
                ->icon('heroicon-o-clock')
                ->description($pending->count().' seller orders waiting for shipment or release'),
        ];
    }
}
