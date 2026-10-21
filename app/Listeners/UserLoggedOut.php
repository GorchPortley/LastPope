<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Cookie;

/** Logging out of the site also logs the member out of the forum (clears the SSO cookies). */
class UserLoggedOut
{
    public function handle(Logout $event): void
    {
        if ($event->guard !== 'web') {
            return;
        }

        $path = config('forum.cookie.path');

        Cookie::queue(Cookie::forget(config('forum.cookie.name'), $path));
        Cookie::queue(Cookie::forget('flarum_session', $path));
    }
}
