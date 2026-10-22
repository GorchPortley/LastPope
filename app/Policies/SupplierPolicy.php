<?php

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;

/** Suppliers are internal: only admins (platform sellers) see or manage them. Everyone else is refused. */
class SupplierPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isPlatformSeller() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return false;
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
