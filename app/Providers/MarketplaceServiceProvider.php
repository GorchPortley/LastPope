<?php

namespace App\Providers;

use App\Models\Design;
use App\Models\Driver;
use App\Models\Listing;
use App\Models\Order;
use App\Models\OrderIssue;
use App\Models\SellerOrder;
use App\Models\Shipment;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Policies\DesignPolicy;
use App\Policies\DriverPolicy;
use App\Policies\ListingPolicy;
use App\Policies\OrderIssuePolicy;
use App\Policies\OrderPolicy;
use App\Policies\SellerOrderPolicy;
use App\Policies\ShipmentPolicy;
use App\Policies\SupplierOrderPolicy;
use App\Policies\SupplierPolicy;
use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\StripeGateway;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class MarketplaceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentGateway::class, function (Application $app): PaymentGateway {
            $key = config('marketplace.stripe.secret_key');

            if ($app->environment('testing')) {
                return new FakePaymentGateway;
            }

            if (blank($key) && $app->environment('local')) {
                Log::warning('STRIPE_SECRET_KEY is not set: using the fake payment gateway. Checkouts will not reach Stripe.');

                return new FakePaymentGateway;
            }

            // Without a key outside local/testing every call fails with a PaymentGatewayException.
            return new StripeGateway($key);
        });
    }

    public function boot(): void
    {
        // Short, stable aliases for the polymorphic columns (cart_items, order_items, entitlements).
        // Non-enforcing: other models keep storing their class name (Wave/spatie store App\Models\User).
        Relation::morphMap([
            'design' => Design::class,
            'listing' => Listing::class,
        ]);

        Gate::policy(Design::class, DesignPolicy::class);
        Gate::policy(Driver::class, DriverPolicy::class);
        Gate::policy(Listing::class, ListingPolicy::class);
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(OrderIssue::class, OrderIssuePolicy::class);
        Gate::policy(SellerOrder::class, SellerOrderPolicy::class);
        Gate::policy(Shipment::class, ShipmentPolicy::class);
        Gate::policy(Supplier::class, SupplierPolicy::class);
        Gate::policy(SupplierOrder::class, SupplierOrderPolicy::class);
    }
}
