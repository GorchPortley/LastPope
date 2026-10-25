<?php

namespace App\Http\Middleware;

use App\Support\RecipientLink;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A page opened from a link marked for one account (see RecipientLink) is only shown to that account. Anybody else who
 * is signed in, an admin too, gets a page that says so and offers to sign in with the right account. Guests pass: the
 * page's own `auth` middleware sends them to the login, and after it they come back here and are checked again.
 */
class EnsureLinkRecipient
{
    public function handle(Request $request, Closure $next): Response
    {
        $recipient = $request->query(RecipientLink::PARAM);
        $user = $request->user();

        if ($recipient === null || $user === null || (string) $user->getKey() === (string) $recipient) {
            return $next($request);
        }

        return response()->view('link-recipient-mismatch', [
            'user' => $user,
            'target' => $request->getRequestUri(),
        ], 403);
    }
}
