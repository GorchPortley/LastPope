<?php

namespace App\Filament\Resources\SupplierOrderResource\Pages;

use App\Enums\SupplierOrderStatus;
use App\Filament\Resources\SupplierOrderResource;
use App\Models\SupplierOrder;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListSupplierOrders extends ListRecords
{
    protected static string $resource = SupplierOrderResource::class;

    /**
     * One tab per stage of a purchase order; the ones waiting to be sent come first. The badge of a tab is the number
     * of purchase orders in it (the open stages in a colour, the finished ones grey).
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $tab = fn (SupplierOrderStatus $status, string $color): Tab => Tab::make($status->label())
            ->badge(SupplierOrder::query()->status($status)->count())
            ->badgeColor($color)
            ->modifyQueryUsing(fn (Builder $query) => $query->status($status));

        return [
            'to_send' => $tab(SupplierOrderStatus::PENDING, 'warning'),
            'sent' => $tab(SupplierOrderStatus::SENT, 'info'),
            'confirmed' => $tab(SupplierOrderStatus::CONFIRMED, 'primary'),
            'shipped' => $tab(SupplierOrderStatus::SHIPPED, 'success'),
            'problem' => $tab(SupplierOrderStatus::PROBLEM, 'danger'),
            'cancelled' => $tab(SupplierOrderStatus::CANCELLED, 'gray'),
            'all' => Tab::make('All'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'to_send';
    }
}
