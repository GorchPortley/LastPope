<?php

namespace App\Http\Controllers\Chatify;

use App\Support\Avatars;
use App\Support\Chatify\ChannelAuthorizer;
use Chatify\Facades\ChatifyMessenger as Chatify;
use Chatify\Http\Controllers\MessagesController as ChatifyMessagesController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

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

    /**
     * A new picture from the chat settings becomes the user's site avatar (App\Support\Avatars), so chat, site and
     * forum show the same one. Chatify's own upload would delete the site avatar and keep the picture where nothing
     * else looks. Without a picture, Chatify handles the request as before (dark mode, colour).
     */
    public function updateSettings(Request $request)
    {
        if (! $request->hasFile('avatar')) {
            return parent::updateSettings($request);
        }

        $file = $request->file('avatar');

        if ($file->getSize() >= Chatify::getMaxUploadSize()) {
            return Response::json(['status' => 0, 'error' => 1, 'message' => 'File size you are trying to upload is too large!'], 200);
        }

        if (! in_array(strtolower($file->extension()), Chatify::getAllowedImages(), true)) {
            return Response::json(['status' => 0, 'error' => 1, 'message' => 'File extension not allowed!'], 200);
        }

        $user = $request->user();
        $settings = [];

        // The rest of Chatify's settings form, as the package does it (the request still carries the file, so it
        // cannot be passed on to the parent).
        if ($request['dark_mode']) {
            $settings['dark_mode'] = $request['dark_mode'] === 'dark' ? 1 : 0;
        }
        if ($request['messengerColor']) {
            $settings['messenger_color'] = trim((string) filter_var($request['messengerColor']));
        }
        if ($settings !== []) {
            $user->forceFill($settings)->save();
        }

        Avatars::store($user, $file->getRealPath());

        return Response::json(['status' => 1, 'error' => 0, 'message' => 0], 200);
    }
}
