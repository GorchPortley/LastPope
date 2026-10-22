<?php

namespace App\Http\Controllers\Chatify\Api;

use App\Support\Chatify\ChannelAuthorizer;
use Chatify\Http\Controllers\Api\MessagesController as ChatifyApiMessagesController;
use Illuminate\Http\Request;

/** The same channel check for Chatify's API route (chatify/api/chat/auth), see the web controller next to it. */
class MessagesController extends ChatifyApiMessagesController
{
    public function pusherAuth(Request $request)
    {
        return app(ChannelAuthorizer::class)->respond($request);
    }
}
