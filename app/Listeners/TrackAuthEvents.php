<?php

namespace App\Listeners;

use App\Support\Analytics\Ga4;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;

/**
 * GA4 `sign_up` and `login` (docs/PLAN.md 12). The auth forms redirect, so the event is queued in the session and
 * the next full page sends it (Ga4::flash / the analytics partial). Only real logins count: a form post or the
 * social-login callback, not a session restored from the "remember me" cookie. Registering also logs the member in,
 * which must not count as a login as well.
 */
class TrackAuthEvents
{
    public function handleRegistered(Registered $event): void
    {
        request()->attributes->set('ga4.signed_up', true);

        Ga4::flash('sign_up', ['method' => 'password']);
    }

    public function handleLogin(Login $event): void
    {
        $request = request();

        if ($event->guard !== 'web' || $request->attributes->get('ga4.signed_up') || $request->attributes->get('ga4.logged_in')) {
            return;
        }

        $social = $request->isMethod('GET') && $request->is('auth/*/callback');

        if ($request->isMethod('GET') && ! $social) {
            return;
        }

        $request->attributes->set('ga4.logged_in', true);

        Ga4::flash('login', ['method' => $social ? (string) $request->route('driver') : 'password']);
    }
}
