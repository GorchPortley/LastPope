<?php

namespace App\Policies;

use App\Models\Shipment;
use App\Models\User;

/**
 * Who sees a parcel: the buyer of the order, the seller of that part of it, and admins. Correcting a parcel's tracking
 * is shipping: the seller, or an admin for a platform seller (see SellerOrderPolicy::ship).
 */
class ShipmentPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isPlatformSeller() && $ability !== 'update' ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, Shipment $shipment): bool
    {
        $sellerOrder = $shipment->sellerOrder;

        return $sellerOrder !== null
            && ($sellerOrder->order?->user_id === $user->id || $sellerOrder->seller_id === $user->id);
    }

    public function update(User $user, Shipment $shipment): bool
    {
        $sellerOrder = $shipment->sellerOrder;

        return $sellerOrder !== null
            && ($sellerOrder->seller_id === $user->id || ($user->isPlatformSeller() && $sellerOrder->seller?->isPlatformSeller() === true));
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, Shipment $shipment): bool
    {
        return false;
    }
}
