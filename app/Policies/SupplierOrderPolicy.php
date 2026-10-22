<?php

namespace App\Policies;

use App\Models\SupplierOrder;
use App\Models\User;

/** Purchase orders are internal: only admins (platform sellers) see or work on them. Buyers and sellers never do. */
class SupplierOrderPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isPlatformSeller() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, SupplierOrder $supplierOrder): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    /** Send, place, confirm, ship, enter costs, cancel. */
    public function update(User $user, SupplierOrder $supplierOrder): bool
    {
        return false;
    }

    public function delete(User $user, SupplierOrder $supplierOrder): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
