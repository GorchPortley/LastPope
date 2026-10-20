<?php

namespace App\Models\Concerns;

use App\Models\CartItem;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Shared plumbing for the models that implement App\Contracts\Purchasable.
 */
trait IsPurchasable
{
    public function cartItems(): MorphMany
    {
        return $this->morphMany(CartItem::class, 'purchasable');
    }

    public function orderItems(): MorphMany
    {
        return $this->morphMany(OrderItem::class, 'purchasable');
    }

    public function requiresShipping(): bool
    {
        return ! $this->isDigital();
    }

    /**
     * Whether the money for this item can actually reach its seller: the seller finished Stripe
     * onboarding, or is a platform seller (admin) whose revenue stays on the platform.
     */
    public function sellerCanBePaid(): bool
    {
        $seller = $this->getSeller();

        return $seller instanceof User && ($seller->isPlatformSeller() || $seller->canReceivePayouts());
    }

    /**
     * Human-readable reasons the seller side blocks selling this item (empty = fine).
     *
     * @return array<int, string>
     */
    protected function sellerBlockers(): array
    {
        $seller = $this->getSeller();

        if (! $seller instanceof User) {
            return ['This item has no owner.'];
        }

        if (! $seller->isPlatformSeller() && ! $seller->canReceivePayouts()) {
            return ['Finish the Stripe payout setup for your account before you charge for this item.'];
        }

        return [];
    }

    /** Whether the item can be made active/published right now (see publishBlockers()). */
    public function canBePublished(): bool
    {
        return $this->publishBlockers() === [];
    }
}
