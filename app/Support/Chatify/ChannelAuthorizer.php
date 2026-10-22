<?php

namespace App\Support\Chatify;

use App\Models\User;
use Chatify\Facades\ChatifyMessenger as Chatify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Who may log in to which real-time channel (POST /chatify/chat/auth).
 *
 * Chatify itself signs ANY channel name for any logged-in user (it compares the request user with the session user,
 * which are the same person), so everybody could subscribe to `private-chatify.{someone else}` and read that person's
 * incoming messages. This class is the missing check. Three kinds of channel exist, everything else is refused:
 *
 *   private-chatify.{id}                 a person's inbox: the server publishes new messages here. Owner only.
 *   private-chatify-chat.{low}.{high}    the two people of one conversation. Their browsers exchange the "client events"
 *                                        (typing, seen, message deleted, ...) here. Only those two, low < high.
 *   presence-activeStatus                who is online (names only). Any logged-in user.
 *
 * public/js/chatify/code.js builds the same names.
 */
class ChannelAuthorizer
{
    public const PRESENCE = 'presence-activeStatus';

    /** Name of the channel shared by the two people of a conversation (smaller id first). */
    public static function pairChannel(int $a, int $b): string
    {
        return 'private-chatify-chat.'.min($a, $b).'.'.max($a, $b);
    }

    public static function inbox(int $userId): string
    {
        return 'private-chatify.'.$userId;
    }

    public function allows(int $userId, string $channel): bool
    {
        if ($channel === self::PRESENCE) {
            return true;
        }

        if (preg_match('/^private-chatify\.([1-9]\d{0,17})$/', $channel, $m)) {
            return (int) $m[1] === $userId;
        }

        if (preg_match('/^private-chatify-chat\.([1-9]\d{0,17})\.([1-9]\d{0,17})$/', $channel, $m)) {
            [$low, $high] = [(int) $m[1], (int) $m[2]];

            return $low < $high
                && ($userId === $low || $userId === $high)
                && User::query()->whereKey([$low, $high])->count() === 2;
        }

        return false;
    }

    /**
     * The answer for the auth endpoint: Chatify's signed login (so the presence data keeps its shape) when the
     * channel is allowed, otherwise 403 and nothing is signed.
     *
     * @return JsonResponse|string
     */
    public function respond(Request $request)
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['message' => 'Not authenticated'], 403);
        }

        $channel = (string) $request->input('channel_name');
        $socketId = (string) $request->input('socket_id');

        if (! preg_match('/^\d+\.\d+$/', $socketId)) {
            return response()->json(['message' => 'Invalid socket id'], 422);
        }

        if (! $this->allows((int) $user->getAuthIdentifier(), $channel)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return Chatify::pusherAuth($user, $user, $channel, $socketId);
    }
}
