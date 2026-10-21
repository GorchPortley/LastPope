<?php

namespace App\Models;

use App\Contracts\Purchasable;
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

class Listing extends Model implements Purchasable
{
    /** @use HasFactory<ListingFactory> */
    use HasFactory;
    use SoftDeletes;
    use IsPurchasable;

    protected $fillable = [
        'uuid',
        'seller_id',
        'driver_id',
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
        'condition' => 'new',
        'status' => 'draft',
        'quantity' => 1,
        'units_per_lot' => 1,
    ];

    protected $casts = [
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

    /** The driver catalogue entry this listing is an instance of (optional). */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    // --- Scopes --------------------------------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ListingStatus::ACTIVE->value);
    }

    /** Active, in stock, and priced: what the marketplace shows. */
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

        return array_merge($blockers, $this->sellerBlockers());
    }
}
