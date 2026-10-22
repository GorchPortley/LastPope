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
use App\Services\Marketplace\Data\CartIssue;
use App\Services\Marketplace\Data\CartIssueType;
use App\Services\Marketplace\Data\CartLine;
use App\Services\Marketplace\Data\CartSummary;
use App\Services\Marketplace\Data\CheckoutResult;
use App\Services\Marketplace\Data\SellerGroup;
use App\Services\Marketplace\Data\ShippingGroup;
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
            $only = fn (CartIssueType $type) => collect($issues)->every(fn ($issue) => $issue->type === $type);

            throw match (true) {
                $only(CartIssueType::NOT_SHIPPABLE) => new CheckoutException(CheckoutError::NOT_SHIPPABLE, $issues, 'A seller does not ship an item in your cart to that address.'),
                $only(CartIssueType::INSUFFICIENT_STOCK) => new CheckoutException(CheckoutError::INSUFFICIENT_STOCK, $issues),
                default => new CheckoutException(CheckoutError::UNAVAILABLE_ITEMS, $issues),
            };
        }
    }

    /**
     * Inside the order transaction: lock the listing rows (in id order, to avoid deadlocks) and check that
     * each line still fits in the stock that is not held by other buyers' pending orders.
     *
     * @throws CheckoutException
     */
    private function assertStockAvailable(User $user, CartSummary $summary): void
    {
        $lines = collect($summary->lines)->filter(fn (CartLine $line) => $line->purchasable instanceof Listing);

        if ($lines->isEmpty()) {
            return;
        }

        $listings = Listing::query()
            ->whereIn('id', $lines->map(fn (CartLine $line) => $line->purchasable->getKey())->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $issues = [];
        $sourceChanged = false;

        foreach ($lines as $line) {
            $listing = $listings->get($line->purchasable->getKey());
            $available = $listing?->availableQuantity($user) ?? 0;

            if ($listing === null || ! $listing->isAvailableForPurchase($user)) {
                $issues[] = new CartIssue(CartIssueType::INSUFFICIENT_STOCK, "{$line->title} is sold out or on hold for another buyer.", $line->id(), 0);
            } elseif ($line->quantity > $available) {
                $issues[] = new CartIssue(CartIssueType::INSUFFICIENT_STOCK, "Only {$available} available of {$line->title}.", $line->id(), $available);
            } elseif ($listing->fulfillmentFor($line->quantity, $user)->source !== $line->fulfillmentSource()) {
                // The last own units went to somebody else (or came back) since the buyer saw the cart: the line now
                // ships from the other source, which changes the shipping. Never charge a total the buyer has not seen.
                $sourceChanged = true;
            }
        }

        if ($issues !== []) {
            throw new CheckoutException(CheckoutError::INSUFFICIENT_STOCK, $issues);
        }

        if ($sourceChanged) {
            throw new CheckoutException(CheckoutError::PRICE_CHANGED);
        }
    }

    /**
     * @return array{0: Order, 1: array<int, array<string, mixed>>} the order and its Stripe line items
     */
    private function createOrder(User $user, CartSummary $summary, ?Address $address): array
    {
        return DB::transaction(function () use ($user, $summary, $address) {
            // Lock the listings first so two buyers cannot both claim the last unit, then hold the stock.
            $this->assertStockAvailable($user, $summary);

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
                // The stock in this order is held for the buyer, and the Checkout Session lives, until then.
                'reserved_until' => now()->addMinutes(Order::reservationMinutes()),
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
                    $listing = $line->purchasable instanceof Listing ? $line->purchasable : null;

                    $item = OrderItem::create([
                        'order_id' => $order->getKey(),
                        'seller_order_id' => $sellerOrder->getKey(),
                        'purchasable_type' => $line->purchasable->getMorphClass(),
                        'purchasable_id' => $line->purchasable->getKey(),
                        'title' => $line->title,
                        'is_digital' => $line->isDigital,
                        // Decided here, once, and never split: the whole line comes from own stock or from the supplier.
                        'fulfillment_source' => $line->isDigital ? null : $line->fulfillmentSource()->value,
                        'supplier_id' => $line->isDropshipped() ? $line->fulfillment?->supplier?->getKey() : null,
                        'quantity' => $line->quantity,
                        'unit_price_cents' => $line->unitPriceCents,
                        'unit_cost_cents' => $listing?->cost_cents,
                        'shipping_cents' => $line->shippingCents ?? 0,
                        'line_total_cents' => $line->lineTotalCents(),
                    ]);

                    $lineItems[] = $this->stripeLineItem($order, $item, $line, $group);
                }

                if ($group->isPlatform) {
                    // The store: one shipping line per parcel (own stock, each supplier), never naming the supplier.
                    foreach ($group->shippingGroups as $parcel) {
                        if ($parcel->shippingCents > 0) {
                            $lineItems[] = $this->stripeParcelShippingLine($order, $group, $parcel);
                        }
                    }
                } elseif ($group->shippingCents > 0) {
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

    /** @return array<string, mixed> The shipping of one parcel of the store's order. */
    private function stripeParcelShippingLine(Order $order, SellerGroup $group, ShippingGroup $parcel): array
    {
        $price = [
            'currency' => $order->currency,
            'unit_amount' => $parcel->shippingCents,
            'product_data' => [
                'name' => Str::limit($parcel->shippingLabel(), 250, ''),
                'description' => Str::limit('Shipping for the items sold by '.$group->displayName.' that ship '.($parcel->isSupplier() ? 'separately' : 'from the store').'.', 500, ''),
                'metadata' => [
                    'shipping_for_seller_id' => (string) ($group->seller?->getKey() ?? 0),
                    'shipping_group' => $parcel->kind,
                ],
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
