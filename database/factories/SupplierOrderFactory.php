<?php

namespace Database\Factories;

use App\Enums\FulfillmentSource;
use App\Enums\ListingStatus;
use App\Enums\OrderStatus;
use App\Enums\SellerOrderStatus;
use App\Enums\SupplierOrderStatus;
use App\Models\Address;
use App\Models\Listing;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SellerOrder;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A purchase order with one line, on a paid store order built for it: an order of a new buyer, a seller order of an
 * admin (awaiting shipment) and an order item bought from this supplier. The line costs 6000 each and sells for 10000.
 *
 * States: pending() (default), sent(), confirmed(), shipped() (a shipment exists and the seller order is shipped),
 * cancelled(), problem($note?), placedManually($reference?), withCosts($shipping, $fee?). Pass ->for($supplier) to
 * choose the supplier, ->state(['status' => ...]) is also fine for bare rows.
 *
 * @extends Factory<SupplierOrder>
 */
class SupplierOrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory()->state(fn () => [
                'status' => OrderStatus::PAID,
                'paid_at' => now()->subHours(3),
                'stripe_checkout_session_id' => 'cs_test_'.Str::random(24),
                'stripe_payment_intent_id' => 'pi_'.Str::random(24),
                'stripe_charge_id' => 'ch_'.Str::random(24),
                'shipping_address' => Address::factory()->make()->toSnapshot(),
            ]),
            'seller_order_id' => fn (array $attributes) => SellerOrder::factory()->state([
                'order_id' => $attributes['order_id'],
                'seller_id' => User::factory()->admin(),
                'status' => SellerOrderStatus::AWAITING_SHIPMENT,
                'payout_cents' => 0,
                'platform_fee_cents' => 0,
            ])->create()->getKey(),
            'supplier_id' => Supplier::factory(),
            'status' => SupplierOrderStatus::PENDING,
            'shipping_address' => fn (array $attributes) => Order::find($attributes['order_id'])->shipping_address,
            'supplier_reference' => null,
            'items_cost_cents' => 0,
            'shipping_cost_cents' => null,
            'dropship_fee_cents' => 0,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (SupplierOrder $po) {
            if ($po->items()->exists()) {
                return;
            }

            $this->addLine($po, 2);
        });
    }

    /** Add a line of $quantity units (a new dropship store listing) to the purchase order, its seller order and its order. */
    public static function addLine(SupplierOrder $po, int $quantity = 1, int $unitCostCents = 6000, int $unitPriceCents = 10000): SupplierOrderItem
    {
        $sellerOrder = $po->sellerOrder;
        $listing = Listing::factory()->dropship($po->supplier, null, $sellerOrder->seller)->create([
            'price_cents' => $unitPriceCents,
            'cost_cents' => $unitCostCents,
            'status' => ListingStatus::ACTIVE,
        ]);

        $item = OrderItem::create([
            'order_id' => $po->order_id,
            'seller_order_id' => $sellerOrder->getKey(),
            'purchasable_type' => $listing->getMorphClass(),
            'purchasable_id' => $listing->getKey(),
            'title' => $listing->title,
            'is_digital' => false,
            'fulfillment_source' => FulfillmentSource::SUPPLIER,
            'supplier_id' => $po->supplier_id,
            'quantity' => $quantity,
            'unit_price_cents' => $unitPriceCents,
            'unit_cost_cents' => $unitCostCents,
            'shipping_cents' => 0,
            'line_total_cents' => $unitPriceCents * $quantity,
        ]);

        $line = SupplierOrderItem::create([
            'supplier_order_id' => $po->getKey(),
            'order_item_id' => $item->getKey(),
            'title' => $item->title,
            'supplier_sku' => $listing->supplier_sku,
            'quantity' => $quantity,
            'unit_cost_cents' => $unitCostCents,
        ]);

        $subtotal = (int) $sellerOrder->items()->sum('line_total_cents');
        $sellerOrder->forceFill(['subtotal_cents' => $subtotal])->save();
        $po->order->forceFill(['subtotal_cents' => $subtotal, 'total_cents' => $subtotal + $po->order->shipping_cents])->save();
        $po->forceFill(['items_cost_cents' => (int) $po->activeItems()->get()->sum(fn ($l) => $l->lineCostCents())])->save();

        return $line;
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => SupplierOrderStatus::PENDING]);
    }

    public function sent(): static
    {
        return $this->state(fn () => ['status' => SupplierOrderStatus::SENT, 'sent_at' => now()->subHours(2)]);
    }

    public function placedManually(string $reference = 'PORTAL-48213'): static
    {
        return $this->sent()->state(fn () => ['placed_manually' => true, 'supplier_reference' => $reference]);
    }

    public function confirmed(string $reference = 'SO-7741'): static
    {
        return $this->sent()->state(fn () => [
            'status' => SupplierOrderStatus::CONFIRMED,
            'confirmed_at' => now()->subHour(),
            'supplier_reference' => $reference,
        ]);
    }

    /** Shipped: a shipment carries every line, the seller order is SHIPPED (shipped_at = that shipment). */
    public function shipped(): static
    {
        return $this->confirmed()->state(fn () => [
            'status' => SupplierOrderStatus::SHIPPED,
            'shipped_at' => now()->subHour(),
        ])->afterCreating(function (SupplierOrder $po) {
            $shipment = Shipment::create([
                'seller_order_id' => $po->seller_order_id,
                'supplier_order_id' => $po->getKey(),
                'carrier' => 'UPS',
                'tracking_number' => '1Z'.Str::upper(Str::random(16)),
                'shipped_at' => $po->shipped_at,
            ]);

            foreach ($po->activeItems as $line) {
                ShipmentItem::create(['shipment_id' => $shipment->getKey(), 'order_item_id' => $line->order_item_id, 'quantity' => $line->quantity]);
            }

            $po->sellerOrder->forceFill([
                'status' => SellerOrderStatus::SHIPPED,
                'shipped_at' => $po->shipped_at,
                'carrier' => $shipment->carrier,
                'tracking_number' => $shipment->tracking_number,
            ])->save();
        });
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => SupplierOrderStatus::CANCELLED, 'cancelled_at' => now()->subHour()])
            ->afterCreating(fn (SupplierOrder $po) => $po->items()->update(['cancelled_at' => now()->subHour()]));
    }

    public function problem(string $note = 'Cancel with supplier: refunded after the order was sent.'): static
    {
        return $this->sent()->state(fn () => ['status' => SupplierOrderStatus::PROBLEM, 'notes' => $note]);
    }

    /** What the supplier charges us for shipping (and optionally per order). */
    public function withCosts(int $shippingCents, int $feeCents = 0): static
    {
        return $this->state(fn () => ['shipping_cost_cents' => $shippingCents, 'dropship_fee_cents' => $feeCents]);
    }
}
