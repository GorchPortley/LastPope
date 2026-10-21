<?php

namespace App\Http\Controllers\Marketplace;

use App\Exceptions\Marketplace\InvalidOrderStateException;
use App\Http\Controllers\Controller;
use App\Models\SellerOrder;
use App\Services\Marketplace\ShipmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Plain-HTTP front for ShipmentService (P3 pages may call the service directly instead).
 *
 *   POST /selling/orders/{sellerOrder}/ship      selling.orders.ship   fields: carrier, tracking_number, tracking_url?
 *   POST /orders/{sellerOrder}/received          orders.received       buyer confirms the parcel arrived
 *
 * Somebody else's order is a 403. Flash keys: `success`, `error`.
 */
class ShipmentController extends Controller
{
    public function ship(Request $request, SellerOrder $sellerOrder, ShipmentService $shipments): RedirectResponse
    {
        try {
            $shipments->markShipped(
                $request->user(),
                $sellerOrder,
                (string) $request->input('carrier'),
                (string) $request->input('tracking_number'),
                $request->input('tracking_url'),
            );
        } catch (InvalidOrderStateException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Marked as shipped. The buyer has been notified.');
    }

    public function received(Request $request, SellerOrder $sellerOrder, ShipmentService $shipments): RedirectResponse
    {
        try {
            $shipments->confirmReceived($request->user(), $sellerOrder);
        } catch (InvalidOrderStateException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Thanks for confirming. The order is complete.');
    }
}
