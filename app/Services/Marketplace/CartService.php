<?php

namespace App\Services\Marketplace;

use App\Contracts\Purchasable;
use App\Enums\EntitlementSource;
use App\Enums\ListingStatus;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Listing;
use App\Models\User;
use App\Services\Marketplace\Data\CartIssue;
use App\Services\Marketplace\Data\CartIssueType;
use App\Services\Marketplace\Data\CartLine;
use App\Services\Marketplace\Data\CartOutcome;
use App\Services\Marketplace\Data\CartResult;
use App\Services\Marketplace\Data\CartSummary;
use App\Services\Marketplace\Data\FulfillmentQuote;
use App\Services\Marketplace\Data\SellerGroup;
use App\Services\Marketplace\Data\ShippingGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The buyer's cart (docs/PLAN.md 3.4). Every method takes the user explicitly and only touches that
 * user's own cart. Guests have no cart: send them to login before calling.
 *
 * Rules: digital quantity is always 1; you cannot buy your own items nor a digital item you already
 * have access to; a free digital item skips the cart and is added to the library at once; a listing's
 * quantity cannot exceed its stock.
 *
 *     $result = $carts->add($user, $listing, 2);
 *     session()->flash($result->level(), $result->message());
 *     $summary = $carts->summary($user, $address);      // CartSummary
 */
class CartService
{
    public function __construct(
        private readonly ShippingCalculator $shipping,
        private readonly FeeCalculator $fees,
    ) {
    }

    // --- Actions ---------------------------------------------------------------------------------

    public function add(User $user, Purchasable $purchasable, int $quantity = 1): CartResult
    {
        $title = $purchasable->purchasableTitle();

        if (! $purchasable instanceof Model || ! $purchasable->exists) {
            return new CartResult(CartOutcome::NOT_AVAILABLE, title: $title);
        }

        if ($purchasable->getSeller()?->is($user)) {
            return new CartResult(CartOutcome::OWN_ITEM, title: $title);
        }

        if ($purchasable->isDigital()) {
            return $this->addDigital($user, $purchasable, $title);
        }

        if ($quantity < 1) {
            return new CartResult(CartOutcome::INVALID_QUANTITY, title: $title);
        }

        return $this->addPhysical($user, $purchasable, $quantity, $title);
    }

    /** Set the quantity of a cart line; zero or less removes it. Digital lines stay at 1. */
    public function updateQuantity(User $user, CartItem|int $item, int $quantity): CartResult
    {
        $item = $this->findItem($user, $item);
        if ($item === null) {
            return new CartResult(CartOutcome::ITEM_NOT_FOUND);
        }

        if ($quantity < 1) {
            return $this->remove($user, $item);
        }

        $purchasable = $item->purchasable;
        $title = $purchasable?->purchasableTitle();

        if (! $purchasable instanceof Purchasable) {
            return new CartResult(CartOutcome::NOT_AVAILABLE, $item, title: $title);
        }

        if ($purchasable->isDigital()) {
            $quantity = 1;
        } elseif ($purchasable instanceof Listing && $quantity > ($stock = $purchasable->availableQuantity($user))) {
            return new CartResult(CartOutcome::INSUFFICIENT_STOCK, $item, title: $title, available: $stock);
        }

        if ($item->quantity === $quantity) {
            return new CartResult(CartOutcome::UNCHANGED, $item, title: $title);
        }

        $item->forceFill([
            'quantity' => $quantity,
            'unit_price_cents' => $purchasable->purchasablePriceCents(),
        ])->save();

        return new CartResult(CartOutcome::QUANTITY_UPDATED, $item, title: $title);
    }

    public function remove(User $user, CartItem|int $item): CartResult
    {
        $item = $this->findItem($user, $item);
        if ($item === null) {
            return new CartResult(CartOutcome::ITEM_NOT_FOUND);
        }

        $title = $item->purchasable?->purchasableTitle();
        $item->delete();

        return new CartResult(CartOutcome::REMOVED, title: $title);
    }

    public function clear(User $user): CartResult
    {
        $user->cart?->items()->delete();

        return new CartResult(CartOutcome::CLEARED);
    }

    // --- Queries ---------------------------------------------------------------------------------

    /** The user's cart, created on first use. */
    public function cartFor(User $user): Cart
    {
        return Cart::firstOrCreate(['user_id' => $user->getKey()]);
    }

    /** Units in the cart (for the header badge). */
    public function count(User $user): int
    {
        $cart = Cart::where('user_id', $user->getKey())->first();

        return $cart ? $cart->itemCount() : 0;
    }

    public function contains(User $user, Purchasable $purchasable): bool
    {
        if (! $purchasable instanceof Model || ! $purchasable->exists) {
            return false;
        }

        return CartItem::query()
            ->whereHas('cart', fn ($q) => $q->where('user_id', $user->getKey()))
            ->where('purchasable_type', $purchasable->getMorphClass())
            ->where('purchasable_id', $purchasable->getKey())
            ->exists();
    }

    /**
     * The cart at today's prices. Pure read: nothing is written (a line's stored price is the price
     * when it was added, compare CartLine::priceChanged()). Pass the buyer's address to price
     * shipping and to detect listings the seller will not ship there.
     */
    public function summary(User $user, ?Address $address = null): CartSummary
    {
        if ($address !== null && (int) $address->user_id !== (int) $user->getKey()) {
            $address = null;
        }

        $cart = Cart::where('user_id', $user->getKey())->first();
        $items = $cart ? $cart->items()->with('purchasable')->orderBy('id')->get() : collect();
        $country = $address?->country ? strtoupper($address->country) : null;

        // Where each physical listing line would come from (own stock or a supplier, docs/PLAN.md 8.1): the decision
        // CheckoutService stores on the order item, and the one that prices its shipping.
        $fulfillment = [];
        $shippingInput = [];
        foreach ($items as $item) {
            $purchasable = $item->purchasable;
            if ($purchasable instanceof Purchasable) {
                $quantity = $this->quantityFor($purchasable, $item);
                $fulfillment[$item->getKey()] = $purchasable instanceof Listing ? $purchasable->fulfillmentFor($quantity, $user) : null;
                $shippingInput[$item->getKey()] = [$purchasable, $quantity, $fulfillment[$item->getKey()]];
            }
        }
        $quote = $this->shipping->quote($shippingInput, $country);

        $lines = [];
        foreach ($items as $item) {
            $lines[] = $this->buildLine($user, $item, $quote->lines[$item->getKey()] ?? null, $country, $fulfillment[$item->getKey()] ?? null);
        }

        return $this->assemble($lines, $address);
    }

    // --- Internals -------------------------------------------------------------------------------

    private function addDigital(User $user, Purchasable&Model $purchasable, string $title): CartResult
    {
        if (! $purchasable->isAvailableForPurchase()) {
            return new CartResult(CartOutcome::NOT_AVAILABLE, title: $title);
        }

        if ($purchasable->purchasablePriceCents() <= 0) {
            // Free: no cart, no checkout. Straight into the library.
            if ($user->isPlatformSeller()) {
                return new CartResult(CartOutcome::ALREADY_OWNED, title: $title);
            }

            $existing = $user->entitlementFor($purchasable);
            if ($existing?->isActive()) {
                return new CartResult(CartOutcome::ALREADY_OWNED, entitlement: $existing, title: $title);
            }
            if ($existing !== null) {
                return new CartResult(CartOutcome::ACCESS_REVOKED, title: $title);
            }

            $entitlement = $user->grantEntitlement($purchasable, EntitlementSource::FREE);

            return new CartResult(CartOutcome::FREE_GRANTED, entitlement: $entitlement, title: $title);
        }

        if ($user->hasAccessTo($purchasable, grantFree: false)) {
            return new CartResult(CartOutcome::ALREADY_OWNED, title: $title);
        }

        $existing = $this->itemFor($user, $purchasable);
        if ($existing !== null) {
            return new CartResult(CartOutcome::ALREADY_IN_CART, $existing, title: $title);
        }

        return $this->createLine($user, $purchasable, 1, $title);
    }

    private function addPhysical(User $user, Purchasable&Model $purchasable, int $quantity, string $title): CartResult
    {
        $stock = $purchasable instanceof Listing ? $purchasable->availableQuantity($user) : PHP_INT_MAX;

        if (! $this->isAvailable($user, $purchasable)) {
            // Sold out, or the last units are held in somebody else's running checkout.
            return $stock <= 0 && $purchasable instanceof Listing && $this->isStockProblem($purchasable)
                ? new CartResult(CartOutcome::INSUFFICIENT_STOCK, title: $title, available: 0)
                : new CartResult(CartOutcome::NOT_AVAILABLE, title: $title);
        }

        $existing = $this->itemFor($user, $purchasable);
        $wanted = ($existing?->quantity ?? 0) + $quantity;

        if ($wanted > $stock) {
            return new CartResult(CartOutcome::INSUFFICIENT_STOCK, $existing, title: $title, available: $stock);
        }

        if ($existing !== null) {
            $existing->forceFill([
                'quantity' => $wanted,
                'unit_price_cents' => $purchasable->purchasablePriceCents(),
            ])->save();

            return new CartResult(CartOutcome::QUANTITY_UPDATED, $existing, title: $title);
        }

        return $this->createLine($user, $purchasable, $quantity, $title);
    }

    private function createLine(User $user, Purchasable&Model $purchasable, int $quantity, string $title): CartResult
    {
        $cart = $this->cartFor($user);

        try {
            $item = $cart->items()->create([
                'purchasable_type' => $purchasable->getMorphClass(),
                'purchasable_id' => $purchasable->getKey(),
                'quantity' => $quantity,
                'unit_price_cents' => $purchasable->purchasablePriceCents(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // A double click added it a moment ago.
            return new CartResult(CartOutcome::ALREADY_IN_CART, $this->itemFor($user, $purchasable), title: $title);
        }

        return new CartResult(CartOutcome::ADDED, $item, title: $title);
    }

    private function itemFor(User $user, Purchasable&Model $purchasable): ?CartItem
    {
        return CartItem::query()
            ->whereHas('cart', fn ($q) => $q->where('user_id', $user->getKey()))
            ->where('purchasable_type', $purchasable->getMorphClass())
            ->where('purchasable_id', $purchasable->getKey())
            ->first();
    }

    private function findItem(User $user, CartItem|int $item): ?CartItem
    {
        $id = $item instanceof CartItem ? $item->getKey() : $item;

        return CartItem::query()
            ->whereKey($id)
            ->whereHas('cart', fn ($q) => $q->where('user_id', $user->getKey()))
            ->first();
    }

    /** isAvailableForPurchase() from this buyer's point of view (their own running checkout does not hold stock against them). */
    private function isAvailable(User $user, Purchasable $purchasable): bool
    {
        return $purchasable instanceof Listing
            ? $purchasable->isAvailableForPurchase($user)
            : $purchasable->isAvailableForPurchase();
    }

    /** A listing that is for sale in principle, just without units left right now. */
    private function isStockProblem(Listing $listing): bool
    {
        return in_array($listing->status, [ListingStatus::ACTIVE, ListingStatus::SOLD_OUT], true);
    }

    private function quantityFor(Purchasable $purchasable, CartItem $item): int
    {
        return $purchasable->isDigital() ? 1 : max(1, (int) $item->quantity);
    }

    private function buildLine(User $user, CartItem $item, ?int $shippingCents, ?string $country, ?FulfillmentQuote $fulfillment = null): CartLine
    {
        $purchasable = $item->purchasable;

        if (! $purchasable instanceof Purchasable) {
            return new CartLine(
                item: $item,
                purchasable: null,
                title: 'Item no longer available',
                seller: null,
                quantity: (int) $item->quantity,
                unitPriceCents: (int) $item->unit_price_cents,
                storedUnitPriceCents: (int) $item->unit_price_cents,
                isDigital: false,
                shippingCents: null,
                maxQuantity: null,
                issues: [new CartIssue(CartIssueType::ITEM_MISSING, 'This item no longer exists. Remove it from your cart.', $item->getKey())],
            );
        }

        $title = $purchasable->purchasableTitle();
        $seller = $purchasable->getSeller();
        $digital = $purchasable->isDigital();
        $quantity = $this->quantityFor($purchasable, $item);
        // Stock left after other buyers' running checkouts hold their units.
        $stock = $purchasable instanceof Listing ? $purchasable->availableQuantity($user) : null;
        $issues = [];

        $issue = fn (CartIssueType $type, string $message, ?int $available = null) => new CartIssue($type, $message, $item->getKey(), $available);

        if ($seller?->is($user)) {
            $issues[] = $issue(CartIssueType::OWN_ITEM, "You cannot buy your own item: {$title}.");
        } elseif (! $this->isAvailable($user, $purchasable)) {
            $issues[] = $stock === 0 && $this->isStockProblem($purchasable)
                ? $issue(CartIssueType::INSUFFICIENT_STOCK, "{$title} is sold out or on hold for another buyer.", 0)
                : $issue(CartIssueType::UNAVAILABLE, "{$title} is not available right now.");
        } elseif ($digital && $user->hasAccessTo($purchasable, grantFree: false)) {
            $issues[] = $issue(CartIssueType::ALREADY_OWNED, "You already have access to {$title}.");
        } else {
            if ($stock !== null && $quantity > $stock) {
                $issues[] = $issue(CartIssueType::INSUFFICIENT_STOCK, "Only {$stock} available of {$title}.", $stock);
            }

            if (! $digital && $country !== null && $shippingCents === null) {
                $issues[] = $issue(CartIssueType::NOT_SHIPPABLE, "The seller does not ship {$title} to {$country}.");
            }
        }

        return new CartLine(
            item: $item,
            purchasable: $purchasable,
            title: $title,
            seller: $seller,
            quantity: $quantity,
            unitPriceCents: $purchasable->purchasablePriceCents(),
            storedUnitPriceCents: (int) $item->unit_price_cents,
            isDigital: $digital,
            shippingCents: $shippingCents,
            maxQuantity: $digital ? 1 : $stock,
            issues: $issues,
            fulfillment: $fulfillment,
        );
    }

    /** @param  array<int, CartLine>  $lines */
    private function assemble(array $lines, ?Address $address): CartSummary
    {
        $bySeller = [];
        foreach ($lines as $line) {
            $bySeller[$line->seller?->getKey() ?? 0][] = $line;
        }

        $groups = [];
        $units = $subtotal = $shipping = $fee = 0;
        $needsAddress = $shippingPending = false;

        foreach ($bySeller as $sellerLines) {
            $seller = $sellerLines[0]->seller;
            $groupSubtotal = $groupShipping = 0;
            $groupPending = false;

            foreach ($sellerLines as $line) {
                if ($line->purchasable === null) {
                    continue;
                }

                $units += $line->quantity;
                $groupSubtotal += $line->lineTotalCents();

                if (! $line->isDigital) {
                    $needsAddress = true;
                    if ($line->shippingCents === null) {
                        $groupPending = $groupPending || $address === null;
                    } else {
                        $groupShipping += $line->shippingCents;
                    }
                }
            }

            $groupFee = $this->fees->feeCents($groupSubtotal, $seller);
            $isPlatform = $seller?->isPlatformSeller() ?? false;
            $displayName = $seller?->sellerProfile?->shop_name ?: ($seller?->name ?? 'Unknown seller');

            $groups[] = new SellerGroup(
                seller: $seller,
                displayName: $displayName,
                lines: $sellerLines,
                subtotalCents: $groupSubtotal,
                shippingCents: $groupShipping,
                shippingPending: $groupPending,
                feeCents: $groupFee,
                payoutCents: $isPlatform ? 0 : max(0, $groupSubtotal + $groupShipping - $groupFee),
                isPlatform: $isPlatform,
                shippingGroups: $this->shippingGroups($sellerLines, $isPlatform, $displayName, $address),
            );

            $subtotal += $groupSubtotal;
            $shipping += $groupShipping;
            $fee += $groupFee;
            $shippingPending = $shippingPending || $groupPending;
        }

        return new CartSummary(
            sellers: $groups,
            lines: $lines,
            address: $address,
            currency: strtolower((string) config('marketplace.currency', 'usd')),
            itemCount: $units,
            subtotalCents: $subtotal,
            shippingCents: $shipping,
            feeCents: $fee,
            needsAddress: $needsAddress,
            shippingPending: $shippingPending,
        );
    }

    /**
     * The parcels of one seller's lines. A marketplace seller ships everything in one parcel. The store (platform seller)
     * ships its own-stock lines together ("Ships from SDLabs") and every supplier's lines as a parcel of their own
     * ("Ships separately (5–8 business days)"); the supplier is never named. Digital lines are in no parcel.
     *
     * @param  array<int, CartLine>  $lines
     * @return array<int, ShippingGroup>
     */
    private function shippingGroups(array $lines, bool $isPlatform, string $displayName, ?Address $address): array
    {
        $buckets = [];
        foreach ($lines as $line) {
            if ($line->purchasable === null || $line->isDigital) {
                continue;
            }

            $buckets[$isPlatform ? $line->shippingGroupKey() : 'seller'][] = $line;
        }

        // Own stock first, then the suppliers in cart order.
        uksort($buckets, fn (string $a, string $b) => ($a === 'stock' ? 0 : 1) <=> ($b === 'stock' ? 0 : 1));

        $storeName = (string) config('marketplace.store_name', 'SDLabs');
        $groups = [];

        foreach ($buckets as $key => $groupLines) {
            $subtotal = $shipping = 0;
            $pending = false;

            foreach ($groupLines as $line) {
                $subtotal += $line->lineTotalCents();

                if ($line->shippingCents === null) {
                    $pending = $pending || $address === null;
                } else {
                    $shipping += $line->shippingCents;
                }
            }

            $supplier = str_starts_with($key, 'supplier:') ? $groupLines[0]->fulfillment?->supplier : null;

            $groups[] = match (true) {
                $key === 'seller' => new ShippingGroup('seller', ShippingGroup::KIND_SELLER, 'Ships from '.$displayName, '', $groupLines, $subtotal, $shipping, $pending),
                $supplier !== null => new ShippingGroup(
                    $key,
                    ShippingGroup::KIND_SUPPLIER,
                    'Ships separately',
                    $supplier->leadTimeText(),
                    $groupLines,
                    $subtotal,
                    $shipping,
                    $pending,
                    $supplier->getKey(),
                    (int) $supplier->lead_time_min_days,
                    (int) $supplier->lead_time_max_days,
                ),
                default => new ShippingGroup('stock', ShippingGroup::KIND_STOCK, 'Ships from '.$storeName, '', $groupLines, $subtotal, $shipping, $pending),
            };
        }

        return $groups;
    }
}
