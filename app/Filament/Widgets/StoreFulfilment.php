<?php

namespace App\Filament\Widgets;

use App\Enums\SellerOrderStatus;
use App\Enums\SupplierOrderStatus;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\SupplierOrderResource;
use App\Filament\Resources\SupplierResource;
use App\Models\SellerOrder;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Services\Marketplace\ShipmentService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * What needs doing in the SDLabs store (docs/PLAN.md 8): purchase orders to send, purchase orders with a problem, parcels
 * from our own stock to ship, suppliers whose stock feed failed. Every number links to the place where it is worked off.
 */
class StoreFulfilment extends StatsOverviewWidget
{
    protected static ?int $sort = -9;

    protected ?string $heading = 'Store fulfilment';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->hasRole('admin');
    }

    protected function getStats(): array
    {
        $toSend = SupplierOrder::query()->toSend()->count();
        $problems = SupplierOrder::query()->status(SupplierOrderStatus::PROBLEM)->count();
        $parcels = static::parcelsAwaitingShipment();
        $feedErrors = static::suppliersWithFeedErrors();

        return [
            Stat::make('Purchase orders to send', $toSend)
                ->icon('heroicon-o-paper-airplane')
                ->description($toSend > 0 ? 'waiting for you to send them to a supplier' : 'nothing waiting')
                ->descriptionColor($toSend > 0 ? 'warning' : 'success')
                ->color($toSend > 0 ? 'warning' : 'gray')
                ->url(SupplierOrderResource::getUrl('index', ['activeTab' => 'to_send'])),
            Stat::make('Purchase orders with a problem', $problems)
                ->icon('heroicon-o-exclamation-triangle')
                ->description($problems > 0 ? 'cancel, confirm or ship them' : 'all clear')
                ->descriptionColor($problems > 0 ? 'danger' : 'success')
                ->color($problems > 0 ? 'danger' : 'gray')
                ->url(SupplierOrderResource::getUrl('index', ['activeTab' => 'problem'])),
            Stat::make('Store parcels to ship', $parcels)
                ->icon('heroicon-o-truck')
                ->description($parcels > 0 ? 'from our own stock, paid and waiting' : 'nothing waiting')
                ->descriptionColor($parcels > 0 ? 'warning' : 'success')
                ->color($parcels > 0 ? 'warning' : 'gray')
                ->url(OrderResource::getUrl('index')),
            Stat::make('Suppliers with feed errors', $feedErrors)
                ->icon('heroicon-o-signal-slash')
                ->description($feedErrors > 0 ? 'the last stock sync failed' : 'every feed reads fine')
                ->descriptionColor($feedErrors > 0 ? 'danger' : 'success')
                ->color($feedErrors > 0 ? 'danger' : 'gray')
                ->url(SupplierResource::getUrl('index')),
        ];
    }

    /**
     * Paid store orders (a platform seller's part) with units the store still has to ship itself: awaiting shipment or
     * partly shipped, and not waiting only for a supplier's parcel.
     */
    public static function parcelsAwaitingShipment(): int
    {
        $shipments = app(ShipmentService::class);

        return SellerOrder::query()
            ->whereIn('status', [SellerOrderStatus::AWAITING_SHIPMENT->value, SellerOrderStatus::PARTIALLY_SHIPPED->value])
            ->whereHas('seller', fn ($sellers) => $sellers->role('admin'))
            ->with(['items.supplierOrderItem', 'shipments.items'])
            ->get()
            ->filter(fn (SellerOrder $sellerOrder) => $shipments->ownRemainingQuantities($sellerOrder) !== [])
            ->count();
    }

    /** Active suppliers whose last stock sync failed. */
    public static function suppliersWithFeedErrors(): int
    {
        return Supplier::query()->active()->whereNotNull('feed_last_error')->where('feed_last_error', '!=', '')->count();
    }
}
