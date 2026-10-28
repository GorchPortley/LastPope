<?php

namespace App\Http\Controllers\Marketplace;

use App\Exceptions\Marketplace\CheckoutError;
use App\Exceptions\Marketplace\CheckoutException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Marketplace\CheckoutService;
use App\Support\Analytics\Ga4;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;

/**
 * POST /checkout            start a payment for the cart (route: checkout.start)
 * GET  /checkout/success    "thanks" page after Stripe (route: checkout.success), display only
 * GET  /checkout/cancel     the buyer left the Stripe page (route: checkout.cancel)
 *
 * (GET /checkout, route "checkout", is the Folio page where the buyer picks an address.)
 * Flash messages use the session keys `success`, `info` and `error`.
 */
class CheckoutController extends Controller
{
    /**
     * Form fields: `address_id` (one of the buyer's addresses; required only when the cart has physical
     * items) and optionally `expected_total_cents` (the total the buyer was shown). Send
     * `Accept: application/json` to get {"url": ..., "order": "SDL-..."} or a 422 {"error": code, "message": ...}.
     */
    public function store(Request $request, CheckoutService $checkout): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'address_id' => ['nullable', 'integer'],
            'expected_total_cents' => ['nullable', 'integer', 'min:0'],
        ]);

        $user = $request->user();
        $address = null;

        if (filled($data['address_id'] ?? null)) {
            // Only the buyer's own addresses; anything else is treated like an unusable address.
            $address = $user->addresses()->find($data['address_id']);

            if ($address === null) {
                return $this->failed($request, new CheckoutException(CheckoutError::ADDRESS_INVALID));
            }
        }

        try {
            $result = $checkout->start($user, $address, isset($data['expected_total_cents']) ? (int) $data['expected_total_cents'] : null);
        } catch (CheckoutException $e) {
            return $this->failed($request, $e);
        }

        // For the server-side GA4 purchase (docs/PLAN.md 12): the buyer's GA client id and whether they allowed analytics.
        $result->order->forceFill(Ga4::checkoutAttributes($request))->save();

        if ($request->expectsJson()) {
            return response()->json(['url' => $result->url, 'order' => $result->order->number]);
        }

        return redirect()->away($result->url);
    }

    /**
     * Shows the order's state. It never fulfils anything: the Stripe webhook does, usually within a
     * second or two, so a still-pending order shows a "confirming" message that the CheckoutStatus
     * Livewire component polls until the order is paid.
     */
    public function success(Request $request): View
    {
        $sessionId = (string) $request->query('session_id', '');

        $order = $sessionId === '' ? null : Order::query()
            ->where('stripe_checkout_session_id', $sessionId)
            ->where('user_id', $request->user()->getKey())
            ->with(['items', 'sellerOrders.seller.sellerProfile'])
            ->first();

        abort_if($order === null, 404);

        return view('checkout.success', [
            'order' => $order,
            'sessionId' => $sessionId,
        ]);
    }

    public function cancel(Request $request): View
    {
        return view('checkout.cancel', ['orderNumber' => $request->query('order')]);
    }

    private function failed(Request $request, CheckoutException $e): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'error' => $e->error->value,
                'message' => $e->getMessage(),
                'issues' => $e->issueMessages(),
            ], 422);
        }

        $needsAddress = in_array($e->error, [CheckoutError::ADDRESS_REQUIRED, CheckoutError::ADDRESS_INVALID, CheckoutError::NOT_SHIPPABLE], true);

        return redirect()
            ->route($needsAddress ? 'checkout' : 'cart')
            ->with('error', $e->getMessage())
            ->with('checkout_issues', $e->issueMessages());
    }
}
