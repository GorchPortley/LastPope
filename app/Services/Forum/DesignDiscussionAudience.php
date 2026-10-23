<?php

namespace App\Services\Forum;

use App\Models\Design;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who may see the forum discussion of a design (docs/PLAN.md, R5.2). It follows the design:
 *
 *   hidden (not active)   the designer and the admins
 *   active, free          everybody (the discussion is public)
 *   active, paid          the designer, the admins and the buyers (users with an unrevoked entitlement)
 *
 * The admins are the site's admins (role "admin"), the people DesignPolicy and User::hasAccessTo let in as well.
 * SyncForumVisibility applies members() to the forum; the Discussion tab asks includes() whether to show the thread.
 * Both read the database only, never the forum.
 */
final class DesignDiscussionAudience
{
    /** Everybody may see the discussion: the design is on sale (or free) and not hidden. */
    public static function isPublic(Design $design): bool
    {
        return $design->active && (int) $design->price_cents <= 0;
    }

    /** The audience grows with every purchase and shrinks with every refund. */
    public static function dependsOnBuyers(Design $design): bool
    {
        return $design->active && (int) $design->price_cents > 0;
    }

    /**
     * Everybody who may see a private discussion.
     *
     * @return Collection<int, User>
     */
    public static function members(Design $design): Collection
    {
        $members = User::role('admin')->get()->merge(array_filter([$design->designer]));

        if (self::dependsOnBuyers($design)) {
            $members = $members->merge(User::whereIn('id', $design->entitlements()->active()->select('user_id'))->get());
        }

        return $members->sortBy('id')->values();
    }

    /** May $user (null = a guest) see the discussion? Same rules as members(), for one person. */
    public static function includes(Design $design, ?User $user): bool
    {
        if (self::isPublic($design)) {
            return true;
        }

        if ($user === null || ! $user->exists) {
            return false;
        }

        if ($user->is($design->designer) || $user->isPlatformSeller()) {
            return true;
        }

        return self::dependsOnBuyers($design)
            && $design->entitlements()->active()->where('user_id', $user->getKey())->exists();
    }
}
