<?php

namespace App\Policies;

use App\Models\SellerOrder;
use App\Models\User;

/**
 * Who may do what with one seller's part of an order. Admins may view and refund everything (which
 * also keeps the Filament resources working) but ship and confirm only through the roles they have.
 */
class SellerOrderPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isPlatformSeller() && ! in_array($ability, ['ship', 'confirmReceived'], true)) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return false;
    }

    /** The seller and the buyer. */
    public function view(User $user, SellerOrder $sellerOrder): bool
    {
        return $sellerOrder->seller_id === $user->id || $sellerOrder->order?->user_id === $user->id;
    }

    /** Only the seller enters carrier and tracking. */
    public function ship(User $user, SellerOrder $sellerOrder): bool
    {
        return $sellerOrder->seller_id === $user->id;
    }

    /** Only the buyer confirms that the parcel arrived. */
    public function confirmReceived(User $user, SellerOrder $sellerOrder): bool
    {
        return $sellerOrder->order?->user_id === $user->id;
    }

    /** Admin only (granted by before()). */
    public function refund(User $user, SellerOrder $sellerOrder): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, SellerOrder $sellerOrder): bool
    {
        return false;
    }

    public function delete(User $user, SellerOrder $sellerOrder): bool
    {
        return false;
    }
}
