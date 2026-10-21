<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

/** Orders belong to their buyer; sellers see the orders they are part of; admins see and refund all. */
class OrderPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isPlatformSeller() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, Order $order): bool
    {
        return $order->user_id === $user->id
            || $order->sellerOrders()->where('seller_id', $user->id)->exists();
    }

    /** Admin only (granted by before()). */
    public function refund(User $user, Order $order): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Order $order): bool
    {
        return false;
    }

    public function delete(User $user, Order $order): bool
    {
        return false;
    }
}
