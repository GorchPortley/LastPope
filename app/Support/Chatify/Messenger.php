<?php

namespace App\Support\Chatify;

use App\Support\Avatars;
use Chatify\ChatifyMessenger;

/**
 * Chatify looks for avatars in its own folder (storage/users-avatar/{avatar}), where this site keeps none: the users'
 * avatars live where the profile page puts them. Serve the same picture as everywhere else, default included.
 */
class Messenger extends ChatifyMessenger
{
    public function getUserAvatarUrl($user_avatar_name)
    {
        return Avatars::url($user_avatar_name);
    }
}
