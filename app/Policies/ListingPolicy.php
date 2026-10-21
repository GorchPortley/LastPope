<?php

namespace App\Policies;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\User;

class ListingPolicy
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

    public function view(?User $user, Listing $listing): bool
    {
        return in_array($listing->status, [ListingStatus::ACTIVE, ListingStatus::SOLD_OUT, ListingStatus::PAUSED], true)
            || ($user !== null && $listing->seller_id === $user->id);
    }

    /**
     * Any user may create marketplace listings. Pass a listing to ask about its channel:
     * `Gate::allows('create', new Listing(['channel' => 'store']))` is admin only (see createStore()).
     */
    public function create(User $user, ?Listing $listing = null): bool
    {
        return $listing === null || ! $listing->isStore();
    }

    /** Store listings (SDLabs' own new drivers) are created by admins only. Admins pass through before(). */
    public function createStore(User $user): bool
    {
        return false;
    }

    /** Sellers edit their own marketplace listings; store listings are admin only (granted by before()). */
    public function update(User $user, Listing $listing): bool
    {
        return ! $listing->isStore() && $listing->seller_id === $user->id;
    }

    public function delete(User $user, Listing $listing): bool
    {
        return ! $listing->isStore() && $listing->seller_id === $user->id;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    /** Listings are physical goods: there is nothing to download. */
    public function download(User $user, Listing $listing): bool
    {
        return false;
    }
}
