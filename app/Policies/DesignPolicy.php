<?php

namespace App\Policies;

use App\Models\Design;
use App\Models\User;

class DesignPolicy
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

    public function view(?User $user, Design $design): bool
    {
        return $design->active || ($user !== null && $design->user_id === $user->id);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Design $design): bool
    {
        return $design->user_id === $user->id;
    }

    public function delete(User $user, Design $design): bool
    {
        return $design->user_id === $user->id;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    /** Download the design's snapshots: owner, admin, entitled buyer, or anyone for a free design. */
    public function download(User $user, Design $design): bool
    {
        return $user->hasAccessTo($design);
    }
}
