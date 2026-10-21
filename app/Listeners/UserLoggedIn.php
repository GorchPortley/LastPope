<?php

namespace App\Listeners;

use App\Services\Forum\ForumClient;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Cookie;

/**
 * Single sign-on: after a member logs in to the site, log them in at the forum too.
 *
 * Flarum accepts a "remember" access token in the `flarum_remember` cookie. The cookie is scoped to
 * the forum's path (/forum) so it is only sent to the forum, and EncryptCookies leaves it alone
 * because Flarum has to read it as it is. If the forum is down or disabled, nothing happens and
 * the site login is unaffected.
 */
class UserLoggedIn
{
    public function __construct(private readonly ForumClient $forum)
    {
    }

    public function handle(Login $event): void
    {
        // Only browser logins. The API guards (JWT) fire Login too but have no cookies to set.
        if ($event->guard !== 'web' || ! $this->forum->enabled()) {
            return;
        }

        $token = $this->forum->issueRememberToken($event->user);

        if ($token === null) {
            return;
        }

        Cookie::queue(Cookie::make(
            name: config('forum.cookie.name'),
            value: $token,
            minutes: (int) config('forum.cookie.lifetime_minutes'),
            path: config('forum.cookie.path'),
            domain: null,
            secure: request()->isSecure(),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        ));
    }
}
