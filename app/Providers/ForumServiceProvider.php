<?php

namespace App\Providers;

use App\Console\Commands\ForumSeedDemo;
use App\Console\Commands\ForumSetup;
use App\Models\Design;
use App\Models\Driver;
use App\Models\Entitlement;
use App\Models\Listing;
use App\Observers\DesignObserver;
use App\Observers\DriverObserver;
use App\Observers\EntitlementObserver;
use App\Observers\ListingObserver;
use App\Services\Forum\FlarumClient;
use App\Services\Forum\ForumClient;
use App\Services\Forum\NullForumClient;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Flarum forum into the app: the HTTP client (or a no-op one when FORUM_ENABLED=false),
 * the observers that open a discussion for new designs/drivers/listings (and re-sync who can see a design's
 * discussion when its status, price or buyers change), and the `forum:*` commands.
 * Login/logout/registration listeners are registered in EventServiceProvider.
 */
class ForumServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ForumClient::class, function () {
            return config('forum.enabled')
                ? FlarumClient::fromConfig()
                : new NullForumClient();
        });
    }

    public function boot(): void
    {
        Design::observe(DesignObserver::class);
        Driver::observe(DriverObserver::class);
        Entitlement::observe(EntitlementObserver::class);

        if (class_exists(Listing::class)) {
            Listing::observe(ListingObserver::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([ForumSetup::class, ForumSeedDemo::class]);
        }
    }
}
