<?php

namespace App\Services\Marketplace;

use App\Contracts\Purchasable;
use App\Enums\OrderStatus;
use App\Enums\SellerOrderStatus;
use App\Exceptions\Marketplace\CheckoutError;
use App\Exceptions\Marketplace\CheckoutException;
use App\Models\Address;
use App\Models\Listing;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SellerOrder;
use App\Models\User;
use App\Services\Marketplace\Data\CartIssueType;
use App\Services\Marketplace\Data\CartLine;
use App\Services\Marketplace\Data\CartSummary;
use App\Services\Marketplace\Data\CheckoutResult;
use App\Services\Marketplace\Data\SellerGroup;
use App\Services\Payments\Exceptions\PaymentGatewayException;
use App\Services\Payments\PaymentGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Starts a payment for the buyer's cart (docs/PLAN.md 3.8 step 1).
 *
 *     try {
 *         $result = $checkout->start($user, $address);      // $address only when the cart has physical items
 *         return redirect()->away($result->url);            // Stripe Checkout
 *     } catch (CheckoutException $e) {
 *         // $e->error (CheckoutError), $e->getMessage(), $e->issues
 *     }
 *
 * The cart is re-validated and re-priced; a pending Order with one SellerOrder per seller and one
 * OrderItem per line is created in a transaction; then the gateway creates the Checkout Session.
 * Nothing is fulfilled here: the webhook does that after the payment (OrderFulfillment::markPaid).
 * The cart is left untouched, so a cancelled payment loses nothing.
 */
class CheckoutService
{
    public function __construct(
        private readonly CartService $carts,
        private readonly PaymentGateway $gateway,
        private readonly OrderFulfillment $fulfillment,
    ) {
    }

    /**
     * @param  int|null  $expectedTotalCents  the total the buyer saw (CartSummary::totalCents()); when it
     *                                        differs now, CheckoutError::PRICE_CHANGED is thrown instead of charging more.
     *
     * @throws CheckoutException
     */
    public function start(User $user, ?Address $address = null, ?int $expectedTotalCents = null): CheckoutResult
    {
        if ($address !== null && (int) $address->user_id !== (int) $user->getKey()) {
            throw new CheckoutException(CheckoutError::ADDRESS_INVALID);
        }

        $summary = $this->carts->summary($user, $address);
        $this->assertCanCheckout($summary, $address);

        if ($expectedTotalCents !== null && $summary->totalCents() !== $expectedTotalCents) {
            throw new CheckoutException(CheckoutError::PRICE_CHANGED);
        }

        [$order, $lineItems] = $this->createOrder($user, $summary, $address);

        try {
            $session = $this->gateway->createCheckoutSession(
                $order,
                $lineItems,
                route('checkout.success').'?session_id={CHECKOUT_SESSION_ID}',
                route('checkout.cancel').'?order='.$order->number,
            );
        } catch (PaymentGatewayException $e) {
            report($e);
            $this->fulfillment->cancelPending($order);

            throw new CheckoutException(CheckoutError::GATEWAY_ERROR, previous: $e);
        }

        $order->forceFill(['stripe_checkout_session_id' => $session['id']])->save();

        return new CheckoutResult($order->fresh(['sellerOrders.items']), $session['url'], $session['id']);
    }

    /** @throws CheckoutException */
    private function assertCanCheckout(CartSummary $summary, ?Address $address): void
    {
        if ($summary->isEmpty()) {
            throw new CheckoutException(CheckoutError::EMPTY_CART);
        }

        if ($summary->needsAddress) {
            if ($address === null) {
                throw new CheckoutException(CheckoutError::ADDRESS_REQUIRED);
            }

            foreach (['name', 'line1', 'city', 'postal_code', 'country'] as $field) {
                if (blank($address->{$field})) {
                    throw new CheckoutException(CheckoutError::ADDRESS_INVALID);
                }
            }
        }

        $issues = $summary->issues();
        if ($issues !== []) {
            $onlyShipping = collect($issues)->every(fn ($issue) => $issue->type === CartIssueType::NOT_SHIPPABLE);

            throw new CheckoutException(
                $onlyShipping ? CheckoutError::NOT_SHIPPABLE : CheckoutError::UNAVAILABLE_ITEMS,
                $issues,
                $onlyShipping ? 'A seller does not ship an item in your cart to that address.' : null,
            );
        }
    }

    /**
     * @return array{0: Order, 1: array<int, array<string, mixed>>} the order and its Stripe line items
     */
    private function createOrder(User $user, CartSummary $summary, ?Address $address): array
    {
        return DB::transaction(function () use ($user, $summary, $address) {
            $order = Order::create([
                'user_id' => $user->getKey(),
                'status' => OrderStatus::PENDING,
                'currency' => $summary->currency,
                'subtotal_cents' => $summary->subtotalCents,
                'shipping_cents' => $summary->shippingCents,
                'tax_cents' => 0,
                'total_cents' => $summary->totalCents(),
                'platform_fee_cents' => $summary->feeCents,
                'shipping_address' => $summary->needsAddress ? $address?->toSnapshot() : null,
            ]);

            $lineItems = [];

            foreach ($summary->sellers as $group) {
                $sellerOrder = SellerOrder::create([
                    'order_id' => $order->getKey(),
                    'seller_id' => $group->seller?->getKey(),
                    'status' => SellerOrderStatus::PENDING,
                    'subtotal_cents' => $group->subtotalCents,
                    'shipping_cents' => $group->shippingCents,
                    'platform_fee_cents' => $group->feeCents,
                    'payout_cents' => $group->payoutCents,
                ]);

                foreach ($group->lines as $line) {
                    $item = OrderItem::create([
                        'order_id' => $order->getKey(),
                        'seller_order_id' => $sellerOrder->getKey(),
                        'purchasable_type' => $line->purchasable->getMorphClass(),
                        'purchasable_id' => $line->purchasable->getKey(),
                        'title' => $line->title,
                        'is_digital' => $line->isDigital,
                        'quantity' => $line->quantity,
                        'unit_price_cents' => $line->unitPriceCents,
                        'shipping_cents' => $line->shippingCents ?? 0,
                        'line_total_cents' => $line->lineTotalCents(),
                    ]);

                    $lineItems[] = $this->stripeLineItem($order, $item, $line, $group);
                }

                if ($group->shippingCents > 0) {
                    $lineItems[] = $this->stripeShippingLine($order, $group);
                }

                // Keep the cart's remembered price honest for the lines that are about to be paid.
                foreach ($group->lines as $line) {
                    $line->item->forceFill(['unit_price_cents' => $line->unitPriceCents])->saveQuietly();
                }
            }

            return [$order, $lineItems];
        });
    }

    /** @return array<string, mixed> */
    private function stripeLineItem(Order $order, OrderItem $item, CartLine $line, SellerGroup $group): array
    {
        $details = [$line->isDigital ? 'Digital download' : $this->describeListing($line->purchasable), 'Sold by '.$group->displayName];

        $price = [
            'currency' => $order->currency,
            'unit_amount' => $item->unit_price_cents,
            'product_data' => [
                'name' => Str::limit($item->title, 250, ''),
                'description' => Str::limit(implode(' · ', array_filter($details)), 500, ''),
                'metadata' => ['order_item_id' => (string) $item->getKey()],
            ],
        ];

        if (config('marketplace.stripe_tax')) {
            $price['tax_behavior'] = 'exclusive';
        }

        return ['quantity' => $item->quantity, 'price_data' => $price];
    }

    /** @return array<string, mixed> One shipping line per seller that has shipping. */
    private function stripeShippingLine(Order $order, SellerGroup $group): array
    {
        $price = [
            'currency' => $order->currency,
            'unit_amount' => $group->shippingCents,
            'product_data' => [
                'name' => Str::limit('Shipping - '.$group->displayName, 250, ''),
                'description' => 'Shipping for the items sold by '.$group->displayName,
                'metadata' => ['shipping_for_seller_id' => (string) ($group->seller?->getKey() ?? 0)],
            ],
        ];

        if (config('marketplace.stripe_tax')) {
            $price['tax_behavior'] = 'exclusive';
        }

        return ['quantity' => 1, 'price_data' => $price];
    }

    private function describeListing(?Purchasable $purchasable): string
    {
        if (! $purchasable instanceof Listing) {
            return 'Physical item';
        }

        $parts = [$purchasable->condition?->label() ?? ($purchasable->isNew() ? 'New' : 'Used')];
        if ($purchasable->isLot()) {
            $parts[] = 'Lot of '.$purchasable->units_per_lot;
        }

        return implode(', ', $parts);
    }
}
