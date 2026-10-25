<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Uri;

/**
 * Links in e-mails and in-app notifications that lead to one person's order or problem report carry the id of the
 * account they were sent to (?recipient=…). EnsureLinkRecipient refuses to open such a page for anybody signed in as
 * another account, admins included: they see "this link is for another account" and can switch accounts. The page's
 * own authorization still applies on top; the mark only stops a link from opening in the wrong account.
 */
final class RecipientLink
{
    public const PARAM = 'recipient';

    /** $url marked for $recipient (a notifiable that is not a user, e.g. an on-demand mail address, is left alone). */
    public static function to(string $url, object $recipient): string
    {
        if (! $recipient instanceof User) {
            return $url;
        }

        return Uri::of($url)->withQuery([self::PARAM => $recipient->getKey()])->value();
    }
}
