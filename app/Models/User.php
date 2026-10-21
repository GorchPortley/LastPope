<?php

namespace App\Models;

use App\Contracts\Purchasable;
use App\Enums\EntitlementSource;
use InvalidArgumentException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Maicol07\SSO\Flarum;
use Wave\User as WaveUser;
use Illuminate\Notifications\Notifiable;
use Wave\Traits\HasProfileKeyValues;
use Laravel\Scout\Searchable;

class User extends WaveUser
{
    use HasFactory, Notifiable, HasProfileKeyValues, Searchable;

    public $guard_name = 'web';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'username',
        'avatar',
        'password',
        'role_id',
        'verification_code',
        'verified',
        'trial_ends_at',
        'forum_password',
        'forum_user_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'forum_password',
    ];

    /**
     * Wave declares `$casts` on its parent User, so the extra casts go through casts().
     * The Flarum password is encrypted at rest.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'forum_password' => 'encrypted',
            'forum_user_id' => 'integer',
        ]);
    }

    public function toSearchableArray()
{
    return [
        'id' => $this->id,
        'username' => $this->username,
        'name' => $this->name,
        'email' => $this->email,
    ];
}

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($user) {
            if (empty($user->username)) {
                $username = Str::slug($user->name, '');
                $i = 1;
                while (self::where('username', $username)->exists()) {
                    $username = Str::slug($user->name, '') . $i;
                    $i++;
                }
                $user->username = $username;
            }
        });

        static::created(function ($user) {
            $user->syncRoles([]);
            $user->assignRole( config('wave.default_user_role', 'registered') );
        });
    }

    public function designs(): HasMany
    {
        return $this->hasMany(Design::class);
    }

    public function drivers(): HasMany
    {
        return $this->hasMany(Driver::class);
    }

    public function sellerProfile(): HasOne
    {
        return $this->hasOne(SellerProfile::class);
    }

    /** Physical listings this user is selling. */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class, 'seller_id');
    }

    /** Digital products this user may download (see hasAccessTo()). */
    public function entitlements(): HasMany
    {
        return $this->hasMany(Entitlement::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    /** Orders this user placed as a buyer. */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** The seller's slice of each order that contains their items. */
    public function sellerOrders(): HasMany
    {
        return $this->hasMany(SellerOrder::class, 'seller_id');
    }

    public function cart(): HasOne
    {
        return $this->hasOne(Cart::class);
    }

    // --- Marketplace ---------------------------------------------------------------------------

    /** The seller has finished Stripe onboarding: charges and payouts are enabled. */
    public function canReceivePayouts(): bool
    {
        $profile = $this->sellerProfile;

        return $profile !== null && $profile->charges_enabled && $profile->payouts_enabled;
    }

    /**
     * Admins sell as the platform: their revenue stays on the platform, so they never need Stripe
     * Connect and no transfer is made.
     */
    public function isPlatformSeller(): bool
    {
        return $this->hasRole('admin');
    }

    /**
     * May this user download/use $purchasable's digital content?
     *
     * Owner, admin, or an unrevoked entitlement. A free digital item that is available grants a
     * `free` entitlement the first time (pass $grantFree = false for a side-effect-free check,
     * e.g. when only rendering a page). A revoked entitlement denies access, even for free items.
     * Guests (users without an id) never have access.
     */
    public function hasAccessTo(Purchasable $purchasable, bool $grantFree = true): bool
    {
        if (! $this->exists) {
            return false;
        }

        $seller = $purchasable->getSeller();
        if ($seller !== null && $seller->is($this)) {
            return true;
        }

        if ($this->isPlatformSeller()) {
            return true;
        }

        if (! $purchasable->isDigital()) {
            return false;
        }

        $entitlement = $this->entitlementFor($purchasable);
        if ($entitlement !== null) {
            return $entitlement->isActive();
        }

        if ($purchasable->purchasablePriceCents() <= 0 && $purchasable->isAvailableForPurchase()) {
            if ($grantFree) {
                $this->grantEntitlement($purchasable, EntitlementSource::FREE);
            }

            return true;
        }

        return false;
    }

    /** The user's entitlement row for $purchasable, revoked or not. */
    public function entitlementFor(Purchasable $purchasable): ?Entitlement
    {
        if (! $purchasable instanceof Model || ! $purchasable->exists) {
            return null;
        }

        return $this->entitlements()
            ->where('entitleable_type', $purchasable->getMorphClass())
            ->where('entitleable_id', $purchasable->getKey())
            ->first();
    }

    /**
     * Give the user access to a digital product. Idempotent: an active entitlement is kept as it
     * is (a purchase is never downgraded to a free grant); a revoked one is revived.
     */
    public function grantEntitlement(
        Purchasable $purchasable,
        EntitlementSource $source = EntitlementSource::PURCHASE,
        ?OrderItem $orderItem = null,
    ): Entitlement {
        if (! $purchasable instanceof Model || ! $purchasable->exists || ! $purchasable->isDigital()) {
            throw new InvalidArgumentException('Entitlements can only be granted for saved, digital products.');
        }

        $entitlement = Entitlement::firstOrNew([
            'user_id' => $this->getKey(),
            'entitleable_type' => $purchasable->getMorphClass(),
            'entitleable_id' => $purchasable->getKey(),
        ]);

        if (! $entitlement->exists || ! $entitlement->isActive()) {
            $entitlement->fill([
                'source' => $source,
                'granted_at' => now(),
                'revoked_at' => null,
                'order_item_id' => $orderItem?->getKey(),
            ]);
        } elseif ($orderItem !== null && $entitlement->order_item_id === null) {
            $entitlement->order_item_id = $orderItem->getKey();
        }

        $entitlement->save();

        return $entitlement;
    }

    /** Revoke access (e.g. after a refund). Returns false if the user had no entitlement. */
    public function revokeEntitlement(Purchasable $purchasable): bool
    {
        $entitlement = $this->entitlementFor($purchasable);

        if ($entitlement === null) {
            return false;
        }

        $entitlement->revoke();

        return true;
    }
}
