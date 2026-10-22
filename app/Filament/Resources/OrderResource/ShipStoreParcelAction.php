<?php

namespace App\Filament\Resources\OrderResource;

use App\Exceptions\Marketplace\InvalidOrderStateException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SellerOrder;
use App\Services\Marketplace\ShipmentService;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * "Ship SDLabs parcel" (docs/PLAN.md 8.3): an admin ships the items the store has in its own hands as one parcel
 * (ShipmentService::shipItems). The form lists the units that are left from own stock - the admin chooses how many go in
 * this parcel - and says which items a supplier ships instead: those are shipped from their purchase order.
 *
 * Works on the order page (record = the Order; its platform seller's part is shipped) and on the seller order relation
 * manager (record = the SellerOrder). Pass the action class, \Filament\Actions\Action or \Filament\Tables\Actions\Action.
 */
final class ShipStoreParcelAction
{
    public static function make(string $action): object
    {
        return $action::make('shipParcel')
            ->label('Ship SDLabs parcel')
            ->icon('heroicon-m-truck')
            ->color('primary')
            ->visible(fn (Model $record): bool => static::canShip($record))
            ->modalHeading(fn (Model $record): string => 'Ship a parcel of '.static::sellerOrderOf($record)?->order?->number)
            ->modalDescription('The items SDLabs ships from its own stock, in one parcel. The buyer gets an e-mail with the carrier and tracking number.')
            ->modalSubmitActionLabel('Save and notify buyer')
            ->modalWidth('2xl')
            ->form(function (Model $record): array {
                $sellerOrder = static::sellerOrderOf($record);
                $own = $sellerOrder ? app(ShipmentService::class)->ownRemainingQuantities($sellerOrder) : [];
                $items = $sellerOrder ? $sellerOrder->items()->with('supplierOrderItem.supplierOrder')->get()->keyBy('id') : collect();

                $fields = [];
                foreach ($own as $itemId => $units) {
                    /** @var OrderItem|null $item */
                    $item = $items->get($itemId);
                    $fields[] = TextInput::make('quantities.'.$itemId)
                        ->label($item?->title.' (own stock, '.$units.' to ship)')
                        ->numeric()->integer()->minValue(0)->maxValue($units)
                        ->default($units)
                        ->required();
                }

                $supplierLines = $items->filter(fn (OrderItem $item) => ! $item->is_digital && $item->isFromSupplier() && $item->supplierOrderItem !== null && ! $item->supplierOrderItem->isCancelled());
                if ($supplierLines->isNotEmpty()) {
                    $fields[] = Placeholder::make('by_supplier')
                        ->label('Shipped by a supplier, not in this parcel')
                        ->content(new HtmlString(
                            '<ul class="list-disc pl-5 text-sm">'
                            .$supplierLines->map(fn (OrderItem $item) => '<li>'.e(($item->quantity > 1 ? $item->quantity.' x ' : '').$item->title).' - enter its tracking on purchase order <strong>'.e($item->supplierOrderItem->supplierOrder->number).'</strong></li>')->implode('')
                            .'</ul>'
                        ));
                }

                return [
                    ...$fields,
                    TextInput::make('carrier')->required()->maxLength(100)->datalist(\App\Filament\Resources\SupplierOrderResource\PurchaseOrderActions::CARRIERS),
                    TextInput::make('tracking_number')->required()->maxLength(100),
                    TextInput::make('tracking_url')->label('Tracking link (optional)')->url()->maxLength(500)->placeholder('https://'),
                ];
            })
            ->action(function (array $data, Model $record, $action): void {
                $sellerOrder = static::sellerOrderOf($record);
                $quantities = collect($data['quantities'] ?? [])->map(fn ($units) => (int) $units)->filter(fn (int $units) => $units > 0)->all();

                if ($quantities === []) {
                    Notification::make()->danger()->title('Nothing to ship')->body('Enter how many units of at least one item go in this parcel.')->send();
                    $action->halt();
                }

                try {
                    app(ShipmentService::class)->shipItems(auth()->user(), $sellerOrder, $quantities, $data['carrier'], $data['tracking_number'], $data['tracking_url'] ?? null);
                } catch (InvalidOrderStateException $e) {
                    Notification::make()->danger()->title('Could not ship this parcel')->body($e->getMessage())->send();

                    return;
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('Please check your input')->body((string) collect($e->errors())->flatten()->first())->send();

                    return;
                }

                Notification::make()->success()->title('Parcel shipped')->body('The buyer has been notified.')->send();
            });
    }

    /** The platform seller's part of the order, or the seller order itself. */
    public static function sellerOrderOf(Model $record): ?SellerOrder
    {
        if ($record instanceof SellerOrder) {
            return $record;
        }

        if ($record instanceof Order) {
            return $record->sellerOrders()->with('seller')->get()->first(fn (SellerOrder $sellerOrder) => $sellerOrder->seller?->isPlatformSeller() === true);
        }

        return null;
    }

    /** A store order that awaits shipment and still has units the store ships itself. */
    public static function canShip(Model $record): bool
    {
        $sellerOrder = static::sellerOrderOf($record);

        return $sellerOrder !== null
            && $sellerOrder->seller?->isPlatformSeller() === true
            && $sellerOrder->status->awaitsShipment()
            && Gate::allows('ship', $sellerOrder)
            && app(ShipmentService::class)->ownRemainingQuantities($sellerOrder) !== [];
    }
}
