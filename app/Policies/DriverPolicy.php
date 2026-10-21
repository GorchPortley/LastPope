<?php

namespace App\Policies;

use App\Models\Driver;
use App\Models\User;

class DriverPolicy
{
    /** Admins (platform sellers) may do everything, which also keeps the Filament admin resources working. */
    public function before(User $user, string $ability): ?bool
    {
        return $user->isPlatformSeller() ? true : null;
    }

    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, Driver $driver): bool
    {
        return $driver->active || ($user !== null && $driver->user_id === $user->id);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Driver $driver): bool
    {
        return $driver->user_id === $user->id;
    }

    public function delete(User $user, Driver $driver): bool
    {
        return $driver->user_id === $user->id;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    /**
     * Download the driver's measurement snapshots. Driver data is free and public: anybody, logged in or not,
     * may download the snapshots of an active driver. Inactive drivers: owner (and admins, see before()) only.
     */
    public function download(?User $user, Driver $driver): bool
    {
        return $driver->active || ($user !== null && $driver->user_id === $user->id);
    }
}
