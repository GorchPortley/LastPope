<?php

namespace App\Models\Concerns;

use App\Models\Entitlement;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Digital products (designs): delivered as snapshots, unlocked by entitlements.
 * The using model provides the `snapshots()` relation.
 */
trait IsDigitalPurchasable
{
    use IsPurchasable;

    public function entitlements(): MorphMany
    {
        return $this->morphMany(Entitlement::class, 'entitleable');
    }

    public function isDigital(): bool
    {
        return true;
    }

    public function hasSnapshots(): bool
    {
        return $this->relationLoaded('snapshots')
            ? $this->snapshots->isNotEmpty()
            : $this->snapshots()->exists();
    }

    public function isFree(): bool
    {
        return $this->purchasablePriceCents() <= 0;
    }

    public function isAvailableForPurchase(): bool
    {
        return (bool) $this->active
            && $this->hasSnapshots()
            && ($this->isFree() || $this->sellerCanBePaid());
    }

    /**
     * Reasons this item cannot be made active yet. Free items can always be published; a paid item
     * needs a snapshot to deliver and a seller that can be paid.
     *
     * @return array<int, string>
     */
    public function publishBlockers(): array
    {
        if ($this->isFree()) {
            return [];
        }

        $blockers = [];

        if (! $this->hasSnapshots()) {
            $blockers[] = 'Create at least one snapshot so buyers have files to download.';
        }

        return array_merge($blockers, $this->sellerBlockers());
    }
}
