<?php

namespace App\Providers;

use App\Listeners\UserLoggedIn;
use App\Listeners\UserLoggedOut;
use App\Listeners\UserCreated;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
         Registered::class => [
             SendEmailVerificationNotification::class
         ],
        Login::class => [
            UserLoggedIn::class
        ],
        Logout::class => [
            UserLoggedOut::class
        ],
        // The forum account uses its own random password, so password resets need no forum sync.
        Registered::class => [
            UserCreated::class
        ],
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * @return bool
     */
    public function shouldDiscoverEvents()
    {
        return false;
    }
}
