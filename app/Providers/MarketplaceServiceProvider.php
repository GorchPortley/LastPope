<?php

namespace App\Providers;

use App\Models\Design;
use App\Models\Driver;
use App\Models\Listing;
use App\Policies\DesignPolicy;
use App\Policies\DriverPolicy;
use App\Policies\ListingPolicy;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class MarketplaceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Short, stable aliases for the polymorphic columns (cart_items, order_items, entitlements).
        // Non-enforcing: other models keep storing their class name (Wave/spatie store App\Models\User).
        Relation::morphMap([
            'design' => Design::class,
            'driver' => Driver::class,
            'listing' => Listing::class,
        ]);

        Gate::policy(Design::class, DesignPolicy::class);
        Gate::policy(Driver::class, DriverPolicy::class);
        Gate::policy(Listing::class, ListingPolicy::class);
    }
}
