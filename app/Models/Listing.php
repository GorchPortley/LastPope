<?php

namespace App\Models;

use App\Contracts\Purchasable;
use App\Enums\ListingCategory;
use App\Enums\ListingChannel;
use App\Enums\ListingCondition;
use App\Enums\ListingStatus;
use App\Models\Concerns\IsPurchasable;
use Database\Factories\ListingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Listing extends Model implements Purchasable
{
    /** @use HasFactory<ListingFactory> */
    use HasFactory;
    use SoftDeletes;
    use IsPurchasable;

    protected $fillable = [
        'uuid',
        'seller_id',
        'channel',
        'category',
        'driver_id',
        'design_id',
        'title',
        'slug',
        'condition',
        'description',
        'photos',
        'price_cents',
        'quantity',
        'units_per_lot',
        'ships_from_country',
        'shipping_domestic_cents',
        'shipping_international_cents',
        'additional_item_shipping_cents',
        'handling_days',
        'returns_accepted',
        'return_policy',
        'status',
        'forum_slug',
    ];

    protected $attributes = [
        'channel' => 'marketplace',
        'condition' => 'new',
        'status' => 'draft',
        'quantity' => 1,
        'units_per_lot' => 1,
    ];

    protected $casts = [
        'channel' => ListingChannel::class,
        'category' => ListingCategory::class,
        'condition' => ListingCondition::class,
        'status' => ListingStatus::class,
        'photos' => 'array',
        'price_cents' => 'integer',
        'quantity' => 'integer',
        'units_per_lot' => 'integer',
        'shipping_domestic_cents' => 'integer',
        'shipping_international_cents' => 'integer',
        'additional_item_shipping_cents' => 'integer',
        'handling_days' => 'integer',
        'returns_accepted' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $listing) {
            if (blank($listing->uuid)) {
                $listing->uuid = (string) Str::uuid();
            }

            if (blank($listing->slug)) {
                $listing->slug = Str::slug((string) $listing->title) ?: 'listing';
            }

            if (blank($listing->ships_from_country)) {
                $listing->ships_from_country = strtoupper((string) config('marketplace.default_country', 'US'));
            }

            // A listing always has a category: the channel and the condition imply a sensible one.
            if ($listing->category === null) {
                $listing->category = $listing->defaultCategory();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    /** The driver catalogue entry this listing is an instance of (required for the store, optional otherwise). */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /** The site design a kit or a completed speaker is built from (optional). */
    public function design(): BelongsTo
    {
        return $this->belongsTo(Design::class);
    }

    // --- Scopes --------------------------------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ListingStatus::ACTIVE->value);
    }

    /** SDLabs' own new drivers (sold from the driver page, never shown in the marketplace browse). */
    public function scopeStore(Builder $query): Builder
    {
        return $query->where('channel', ListingChannel::STORE->value);
    }

    /** What users sell to each other: kits, completed speakers, used gear. */
    public function scopeMarketplace(Builder $query): Builder
    {
        return $query->where('channel', ListingChannel::MARKETPLACE->value);
    }

    public function scopeOfCategory(Builder $query, ListingCategory|string $category): Builder
    {
        return $query->where('category', $category instanceof ListingCategory ? $category->value : $category);
    }

    /** Active, in stock, and priced: what the shop shows. Combine with marketplace() or store(). */
    public function scopeForSale(Builder $query): Builder
    {
        return $query->active()->where('quantity', '>', 0)->where('price_cents', '>', 0);
    }

    public function scopeBrandNew(Builder $query): Builder
    {
        return $query->whereIn('condition', ListingCondition::newValues());
    }

    public function scopeUsed(Builder $query): Builder
    {
        return $query->whereIn('condition', ListingCondition::usedValues());
    }

    // --- Helpers -------------------------------------------------------------------------------

    public function isStore(): bool
    {
        return $this->channel === ListingChannel::STORE;
    }

    public function isMarketplace(): bool
    {
        return ! $this->isStore();
    }

    /** The category a listing gets when none is given: store = driver; marketplace = used gear if used, else a kit. */
    public function defaultCategory(): ListingCategory
    {
        return match (true) {
            $this->isStore() => ListingCategory::DRIVER,
            $this->condition?->isUsed() === true => ListingCategory::USED_GEAR,
            default => ListingCategory::KIT,
        };
    }

    public function isNew(): bool
    {
        return $this->condition?->isNew() ?? false;
    }

    public function isUsed(): bool
    {
        return ! $this->isNew();
    }

    /** True when one unit of this listing is more than one physical item (a pair, a set of 4...). */
    public function isLot(): bool
    {
        return $this->units_per_lot > 1;
    }

    /** @return array<int, string> public URLs of the photos */
    public function photoUrls(): array
    {
        return collect($this->photos ?? [])
            ->map(fn (string $path) => Storage::disk('public')->url($path))
            ->all();
    }

    /** Public URL of the first photo, or null. */
    public function mainPhotoUrl(): ?string
    {
        return $this->photoUrls()[0] ?? null;
    }

    /**
     * Shipping for $quantity units to a buyer in $buyerCountry (ISO alpha-2), or null when the
     * seller does not ship there: base (domestic or international) + (qty - 1) x additional item.
     */
    public function shippingCentsFor(string $buyerCountry, int $quantity = 1): ?int
    {
        $base = strtoupper($buyerCountry) === strtoupper((string) $this->ships_from_country)
            ? $this->shipping_domestic_cents
            : $this->shipping_international_cents;

        if ($base === null) {
            return null;
        }

        return (int) $base + max(0, $quantity - 1) * (int) $this->additional_item_shipping_cents;
    }

    // --- Purchasable ---------------------------------------------------------------------------

    public function purchasableTitle(): string
    {
        return (string) $this->title;
    }

    public function purchasablePriceCents(): int
    {
        return (int) $this->price_cents;
    }

    public function getSeller(): ?User
    {
        return $this->seller;
    }

    public function isDigital(): bool
    {
        return false;
    }

    /**
     * Whether $buyer (or anybody, without one) can buy at least one unit now: active, priced, seller
     * payable, and stock left after other buyers' running checkouts (see availableQuantity()).
     */
    public function isAvailableForPurchase(?User $buyer = null): bool
    {
        return $this->status === ListingStatus::ACTIVE
            && $this->price_cents > 0
            && $this->availableQuantity($buyer) > 0
            && $this->sellerCanBePaid();
    }

    /**
     * Units that can still be bought: the stock minus the units held by pending orders whose
     * reservation (orders.reserved_until) has not expired. Pass the buyer to ignore their own
     * pending orders, so going back from Stripe to the cart never blocks them.
     * CheckoutService locks the listing row before calling this, which makes the check race-free.
     */
    public function availableQuantity(?User $buyer = null): int
    {
        $held = OrderItem::query()
            ->where('purchasable_type', $this->getMorphClass())
            ->where('purchasable_id', $this->getKey())
            ->whereHas('order', function (Builder $orders) use ($buyer) {
                $orders->holdingStock()->when($buyer, fn (Builder $q) => $q->where('user_id', '!=', $buyer->getKey()));
            })
            ->sum('quantity');

        return max(0, (int) $this->quantity - (int) $held);
    }

    /**
     * Reasons this listing cannot be made active yet.
     *
     * @return array<int, string>
     */
    public function publishBlockers(): array
    {
        $blockers = [];

        if ($this->price_cents <= 0) {
            $blockers[] = 'Set a price greater than zero.';
        }

        if ($this->quantity < 1) {
            $blockers[] = 'Quantity must be at least 1.';
        }

        $blockers = array_merge($blockers, array_values($this->channelRuleViolations()));

        // Switching a store listing on must not collide with the driver's current one.
        if ($this->isStore() && $this->status !== ListingStatus::ACTIVE && ($conflict = $this->activeStoreConflictMessage()) !== null) {
            $blockers[] = $conflict;
        }

        return array_merge($blockers, $this->sellerBlockers());
    }

    // --- Channel rules (store vs marketplace) ----------------------------------------------------

    /**
     * Where this listing breaks the store / marketplace rules, as field => message (empty = fine).
     *
     *  STORE        seller is a platform seller (admin), driver_id set, category DRIVER, condition NEW, and
     *               no other active store listing for the same driver (checked while this one is active)
     *  MARKETPLACE  category KIT, COMPLETED_SPEAKER or USED_GEAR; USED_GEAR is never condition NEW;
     *               driver_id and design_id are optional
     *
     * The keys are attribute names so forms can attach each message to its field. publishBlockers()
     * includes these, validateChannelRules() throws them as a ValidationException.
     *
     * @return array<string, string>
     */
    public function channelRuleViolations(): array
    {
        $violations = [];

        if ($this->isStore()) {
            if (! $this->seller?->isPlatformSeller()) {
                $violations['seller_id'] = 'Store listings are sold by the platform: the seller must be an admin.';
            }

            if (blank($this->driver_id)) {
                $violations['driver_id'] = 'A store listing must be for a driver from the catalogue.';
            }

            if ($this->category !== ListingCategory::DRIVER) {
                $violations['category'] = 'Store listings are in the Driver category.';
            }

            if ($this->condition !== ListingCondition::NEW) {
                $violations['condition'] = 'Store listings are always new.';
            }

            if ($this->status === ListingStatus::ACTIVE && ($conflict = $this->activeStoreConflictMessage()) !== null) {
                $violations['driver_id'] = $conflict;
            }

            return $violations;
        }

        if (! in_array($this->category, ListingCategory::marketplaceCases(), true)) {
            $violations['category'] = 'Pick what you are selling: a kit, a completed speaker or used gear.';
        } elseif ($this->category === ListingCategory::USED_GEAR && $this->condition === ListingCondition::NEW) {
            $violations['condition'] = 'Used gear cannot be listed as new: pick a used condition, or list it as a kit or a completed speaker.';
        }

        return $violations;
    }

    /** Throws a ValidationException (messages keyed by field) when channelRuleViolations() is not empty. */
    public function validateChannelRules(): static
    {
        if (($violations = $this->channelRuleViolations()) !== []) {
            throw ValidationException::withMessages($violations);
        }

        return $this;
    }

    /** The other active store listing of this listing's driver, if there is one. */
    public function conflictingStoreListing(): ?self
    {
        if (! $this->isStore() || blank($this->driver_id)) {
            return null;
        }

        return static::query()->store()->active()
            ->where('driver_id', $this->driver_id)
            ->when($this->exists, fn (Builder $q) => $q->whereKeyNot($this->getKey()))
            ->first();
    }

    private function activeStoreConflictMessage(): ?string
    {
        return $this->conflictingStoreListing() !== null
            ? 'This driver already has an active store listing. Pause or remove it first.'
            : null;
    }
}
