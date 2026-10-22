<?php

namespace Database\Factories;

use App\Models\SellerOrder;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A parcel of a seller order, shipped yesterday. A bare one carries nothing; ->withItems([$orderItemId => $units]) puts
 * items in it, ->carryingAll() all the physical items of its seller order. This does not touch the seller order's status:
 * use ShipmentService for a consistent order, or Order::factory()->shipped() for fixtures.
 *
 * @extends Factory<Shipment>
 */
class ShipmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'seller_order_id' => SellerOrder::factory(),
            'supplier_order_id' => null,
            'carrier' => 'UPS',
            'tracking_number' => '1Z'.Str::upper(Str::random(16)),
            'tracking_url' => null,
            'shipped_at' => now()->subDay(),
        ];
    }

    /** @param  array<int, int>  $quantities  order item id => units */
    public function withItems(array $quantities): static
    {
        return $this->afterCreating(function (Shipment $shipment) use ($quantities) {
            foreach ($quantities as $itemId => $units) {
                ShipmentItem::create(['shipment_id' => $shipment->getKey(), 'order_item_id' => $itemId, 'quantity' => $units]);
            }
        });
    }

    /** Every physical item of the seller order travels in this parcel. */
    public function carryingAll(): static
    {
        return $this->afterCreating(function (Shipment $shipment) {
            foreach ($shipment->sellerOrder->items()->where('is_digital', false)->get() as $item) {
                ShipmentItem::create(['shipment_id' => $shipment->getKey(), 'order_item_id' => $item->getKey(), 'quantity' => $item->quantity]);
            }
        });
    }

    public function withTrackingUrl(?string $url = null): static
    {
        return $this->state(fn (array $attributes) => ['tracking_url' => $url ?? 'https://track.example.test/'.$attributes['tracking_number']]);
    }

    public function shippedAt(\DateTimeInterface|string $at): static
    {
        return $this->state(fn () => ['shipped_at' => $at]);
    }
}
