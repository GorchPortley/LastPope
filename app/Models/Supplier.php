<?php

namespace App\Models;

use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A company that dropships products for the SDLabs store (docs/PLAN.md 8.1). Suppliers are internal: their name never
 * reaches a buyer (use leadTimeText() and the store's own labels). Only admins manage them (SupplierPolicy).
 *
 * The shipping_* fields are what WE charge the buyer for this supplier's parcel; dropship_fee_cents is what the
 * supplier charges us per purchase order.
 */
class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'contact_name',
        'contact_email',
        'contact_phone',
        'order_email',
        'website',
        'account_number',
        'ships_from_country',
        'lead_time_min_days',
        'lead_time_max_days',
        'dropship_fee_cents',
        'blind_dropship',
        'auto_send_purchase_orders',
        'shipping_domestic_cents',
        'shipping_international_cents',
        'additional_item_shipping_cents',
        'free_shipping_over_cents',
        'notes',
        'active',
        'feed_url',
        'feed_file_path',
        'feed_sku_column',
        'feed_quantity_column',
        'feed_cost_column',
        'feed_last_synced_at',
        'feed_last_error',
    ];

    protected $attributes = [
        'ships_from_country' => 'US',
        'lead_time_min_days' => 3,
        'lead_time_max_days' => 7,
        'dropship_fee_cents' => 0,
        'blind_dropship' => true,
        'auto_send_purchase_orders' => false,
        'shipping_domestic_cents' => 0,
        'additional_item_shipping_cents' => 0,
        'active' => true,
        'feed_sku_column' => 'sku',
        'feed_quantity_column' => 'quantity',
    ];

    protected $casts = [
        'lead_time_min_days' => 'integer',
        'lead_time_max_days' => 'integer',
        'dropship_fee_cents' => 'integer',
        'blind_dropship' => 'boolean',
        'auto_send_purchase_orders' => 'boolean',
        'shipping_domestic_cents' => 'integer',
        'shipping_international_cents' => 'integer',
        'additional_item_shipping_cents' => 'integer',
        'free_shipping_over_cents' => 'integer',
        'active' => 'boolean',
        'feed_last_synced_at' => 'datetime',
    ];

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(SupplierOrder::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /** Suppliers that have a stock feed to read (a URL or an uploaded file). */
    public function scopeWithFeed(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNotNull('feed_url')->where('feed_url', '!=', '')
            ->orWhere(fn (Builder $q) => $q->whereNotNull('feed_file_path')->where('feed_file_path', '!=', '')));
    }

    public function hasFeed(): bool
    {
        return filled($this->feed_url) || filled($this->feed_file_path);
    }

    /** "5-8 business days" (an en dash), or "5 business days" when both ends are the same; "" when no lead time is set. */
    public function leadTimeText(): string
    {
        return static::formatLeadTime((int) $this->lead_time_min_days, (int) $this->lead_time_max_days);
    }

    public static function formatLeadTime(int $min, int $max): string
    {
        $min = max(0, $min);
        $max = max($min, $max);

        if ($max === 0) {
            return '';
        }

        $range = $min === $max ? (string) $max : $min.'–'.$max;

        return $range.' business '.Str::plural('day', $max);
    }

    /**
     * What we charge the buyer for one parcel of this supplier: first unit domestic (the buyer is in the supplier's
     * country) or international, plus additional_item_shipping_cents per further unit, free when the parcel's items
     * add up to free_shipping_over_cents or more. Null = the supplier does not ship there (no international price).
     */
    public function shippingCentsFor(string $buyerCountry, int $units, int $itemsSubtotalCents = 0): ?int
    {
        $base = strtoupper($buyerCountry) === strtoupper((string) $this->ships_from_country)
            ? $this->shipping_domestic_cents
            : $this->shipping_international_cents;

        if ($base === null) {
            return null;
        }

        if ((int) $this->free_shipping_over_cents > 0 && $itemsSubtotalCents >= (int) $this->free_shipping_over_cents) {
            return 0;
        }

        return (int) $base + max(0, $units - 1) * (int) $this->additional_item_shipping_cents;
    }
}
