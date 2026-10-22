<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| R3 dropshipping (docs/PLAN.md 8.3): shipments are general from now on, so every seller order that was shipped before
| R3 (shipped, delivered or completed, with a carrier or tracking number) gets ONE shipment covering all its physical
| items. Old orders keep showing their tracking, now from the shipments table. Plain SQL on purpose: the migration must
| not depend on models that change later. Safe to run twice (a seller order that has a shipment is skipped).
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::table('seller_orders')
            ->whereIn('status', ['shipped', 'delivered', 'completed'])
            ->where(fn ($q) => $q->whereNotNull('tracking_number')->orWhereNotNull('carrier'))
            ->orderBy('id')
            ->chunkById(200, function ($sellerOrders) {
                foreach ($sellerOrders as $sellerOrder) {
                    if (DB::table('shipments')->where('seller_order_id', $sellerOrder->id)->exists()) {
                        continue;
                    }

                    $shippedAt = $sellerOrder->shipped_at ?? $sellerOrder->updated_at ?? now();

                    $shipmentId = DB::table('shipments')->insertGetId([
                        'seller_order_id' => $sellerOrder->id,
                        'supplier_order_id' => null,
                        'carrier' => $sellerOrder->carrier,
                        'tracking_number' => $sellerOrder->tracking_number,
                        'tracking_url' => $sellerOrder->tracking_url,
                        'shipped_at' => $shippedAt,
                        'created_at' => $shippedAt,
                        'updated_at' => $shippedAt,
                    ]);

                    $rows = DB::table('order_items')
                        ->where('seller_order_id', $sellerOrder->id)
                        ->where('is_digital', false)
                        ->get(['id', 'quantity'])
                        ->map(fn ($item) => [
                            'shipment_id' => $shipmentId,
                            'order_item_id' => $item->id,
                            'quantity' => $item->quantity,
                            'created_at' => $shippedAt,
                            'updated_at' => $shippedAt,
                        ])
                        ->all();

                    if ($rows !== []) {
                        DB::table('shipment_items')->insert($rows);
                    }
                }
            });
    }

    public function down(): void
    {
        // Nothing to undo: the shipments table is dropped by its own migration, and seller_orders still holds the tracking.
    }
};
