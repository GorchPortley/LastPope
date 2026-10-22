<?php

namespace App\Http\Controllers\Chatify;

use App\Support\Chatify\ChannelAuthorizer;
use Chatify\Http\Controllers\MessagesController as ChatifyMessagesController;
use Illuminate\Http\Request;

/**
 * Chatify's web controller with a real check on the real-time channel login. Bound in place of the package's
 * controller by App\Providers\AppServiceProvider (the package's routes name the package class, the container
 * hands out this one). See App\Support\Chatify\ChannelAuthorizer.
 */
class MessagesController extends ChatifyMessagesController
{
    public function pusherAuth(Request $request)
    {
        return app(ChannelAuthorizer::class)->respond($request);
    }
}
