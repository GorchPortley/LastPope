<?php

namespace App\Services\Forum;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Flarum 2.x over its REST API (JSON:API, Flarum's flarum/json-api-server).
 *
 *  - server calls go to FLARUM_INTERNAL_URL (container to container, e.g. http://flarum:8000/forum)
 *  - links for browsers are built from FLARUM_URL (e.g. https://example.com/forum)
 *  - admin calls send "Authorization: Token <master key>; userId=<n>", which Flarum treats as a
 *    request made by user n (the admin for setup calls, the author for new discussions)
 *
 * Every member gets a Flarum account with a random password that only this app knows (the
 * encrypted users.forum_password); members never type it. Login simply asks Flarum for a
 * "remember" token with that password and hands it to the browser as the flarum_remember cookie.
 *
 * Private discussions are fof/byobu's: a discussion is private while it has recipients (users and/or groups), and only
 * recipients see it, Flarum's administrators included (hence the Admin group is always a recipient, which also keeps the
 * discussion reachable for our admin calls). Byobu keeps the discussion's tag when it turns private or public again.
 */
class FlarumClient implements ForumClient
{
    /** Cache key set for a short while after a connection failure, so logins do not wait for a dead forum. */
    private const DOWN_KEY = 'forum:unavailable';

    private const DOWN_SECONDS = 30;

    /** Flarum's built-in Admin group. */
    private const ADMIN_GROUP_ID = 1;

    /**
     * Byobu mails and alerts a member "X added you to a private discussion" whenever they become a recipient. This
     * app adds people on its own (the designer of every hidden or paid design, every buyer), so the accounts it
     * creates start with that notification off. Members can switch it on in their forum settings.
     */
    private const QUIET_PREFERENCES = [
        'notify_byobuPrivateDiscussionAdded_alert' => false,
        'notify_byobuPrivateDiscussionAdded_email' => false,
    ];

    private static ?bool $hasForumUserIdColumn = null;

    public function __construct(
        private readonly string $publicUrl,
        private readonly string $internalUrl,
        private readonly ?string $apiKey,
        private readonly int $adminUserId,
        private readonly UsernameSanitizer $usernames,
        private readonly int $connectTimeout = 3,
        private readonly int $timeout = 8,
        private readonly ?string $adminPassword = null,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            (string) config('forum.url'),
            (string) config('forum.internal_url'),
            config('forum.api_key'),
            (int) config('forum.admin_user_id', 1),
            UsernameSanitizer::fromConfig(),
            (int) config('forum.connect_timeout', 3),
            (int) config('forum.timeout', 8),
            config('forum.admin_password'),
        );
    }

    public function enabled(): bool
    {
        return true;
    }

    // --- members ------------------------------------------------------------------------------

    public function ensureUser(User $user): ?int
    {
        if (! $this->usable()) {
            return null;
        }

        try {
            $id = $this->knownForumId($user);

            // The stored id is only a shortcut. After a forum reset or a restore of an older forum database it points
            // at nothing (or at somebody else's new account): look the account up again instead of acting as a ghost.
            if ($id !== null && ! $this->forumUserExists($id)) {
                $this->forgetForumId($user);
                $id = null;
            }

            $id ??= $this->findUserId((string) $user->email);

            if ($id === null) {
                try {
                    $id = $this->createUser($user, $this->forumPassword($user));
                } catch (EmailAlreadyTaken) {
                    // An account with this e-mail exists (restored forum, manual sign-up): adopt it.
                    $id = $this->findUserId((string) $user->email);
                }
            }

            if ($id !== null) {
                $this->rememberForumId($user, $id);
            }

            return $id;
        } catch (Throwable $e) {
            $this->logFailure('ensure user', $e, $user);

            return null;
        }
    }

    public function issueRememberToken(User $user): ?string
    {
        if (! $this->usable()) {
            return null;
        }

        try {
            $password = $this->forumPassword($user);
            $token = $this->requestToken($user, $password);

            if ($token === null) {
                // Unknown account (first login), or the e-mail/password changed on one side only.
                $id = $this->ensureUser($user);
                $token = $this->requestToken($user, $password);

                if ($token === null && $id === $this->adminUserId) {
                    // The member is the forum's own admin (same e-mail), the account the API key acts as: Flarum refuses
                    // to reset its credentials through the key (401), so log in with the admin password from .env.
                    $token = filled($this->adminPassword) ? $this->requestToken($user, (string) $this->adminPassword) : null;
                } elseif ($token === null && $id !== null) {
                    $this->repairAccount($id, $user, $password);
                    $token = $this->requestToken($user, $password);
                }
            }

            if ($token === null) {
                return null;
            }

            $this->rememberForumId($user, $token['userId']);

            return $token['token'];
        } catch (Throwable $e) {
            $this->logFailure('issue remember token', $e, $user);

            return null;
        }
    }

    // --- discussions --------------------------------------------------------------------------

    public function createDiscussion(string $title, string $content, string $tagSlug, User $author): string
    {
        $this->assertConfigured();

        $authorId = $this->ensureUser($author)
            ?? throw new ForumException("Could not find or create the forum account of user #{$author->getKey()}.");

        $tag = $this->tags()[$tagSlug]
            ?? throw new ForumException("The forum tag '{$tagSlug}' does not exist. Run: php artisan forum:setup");
        $tagId = $tag['id'];

        $response = $this->request($authorId)->post('discussions', [
            'data' => [
                'type' => 'discussions',
                'attributes' => [
                    'title' => Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($title)) ?? ''), 80, ''),
                    'content' => $content,
                ],
                'relationships' => [
                    'tags' => ['data' => [['type' => 'tags', 'id' => (string) $tagId]]],
                ],
            ],
        ]);

        $this->throwUnlessSuccessful($response, 'create the discussion');

        $slug = $response->json('data.attributes.slug');

        if (! is_string($slug) || $slug === '') {
            throw new ForumException('Flarum created the discussion but did not return its slug.');
        }

        return $slug;
    }

    public function createPost(string $discussion, string $content, User $author): ?int
    {
        $this->assertConfigured();

        $discussionId = $this->discussionId($discussion);

        $authorId = $this->ensureUser($author)
            ?? throw new ForumException("Could not find or create the forum account of user #{$author->getKey()}.");

        $response = $this->request($authorId)->post('posts', [
            'data' => [
                'type' => 'posts',
                'attributes' => ['content' => $content],
                'relationships' => [
                    'discussion' => ['data' => ['type' => 'discussions', 'id' => (string) $discussionId]],
                ],
            ],
        ]);

        $this->throwUnlessSuccessful($response, 'post the reply');

        $id = $response->json('data.id');

        return is_numeric($id) ? (int) $id : null;
    }

    public function replyCount(string $discussion): ?int
    {
        $this->assertConfigured();

        $response = $this->request()->get('discussions/'.$this->discussionId($discussion));

        if ($response->status() === 404) {
            return null;
        }

        $this->throwUnlessSuccessful($response, 'read the discussion');

        $comments = $response->json('data.attributes.commentCount');

        // commentCount includes the opening post.
        return is_numeric($comments) ? max(0, (int) $comments - 1) : null;
    }

    public function makeDiscussionPublic(string $discussion): void
    {
        $this->assertConfigured();

        $id = $this->discussionId($discussion);
        $state = $this->readDiscussion($id);

        if (! $state['private']) {
            return;
        }

        // Byobu hides the "public" field until its setting "make public" is on, and a fresh forum has it off.
        if (! $state['canMakePublic']) {
            $this->throwUnlessSuccessful($this->request()->post('settings', ['fof-byobu.makePublic' => '1']), 'allow making discussions public');
            Log::info('Forum: switched on the Byobu setting "make public" (needed to open designs to everyone again).');
        }

        // Byobu only looks at "public" when the request also submits the (now empty) recipient lists.
        $response = $this->request()->patch('discussions/'.$id, [
            'data' => [
                'type' => 'discussions',
                'id' => (string) $id,
                'attributes' => ['public' => $id],
                'relationships' => [
                    'recipientUsers' => ['data' => []],
                    'recipientGroups' => ['data' => []],
                ],
            ],
        ]);

        $this->throwUnlessSuccessful($response, 'make the discussion public');
        $this->assertPrivacy($id, false);
    }

    public function makeDiscussionPrivate(string $discussion, iterable $members): void
    {
        $this->assertConfigured();

        $id = $this->discussionId($discussion);
        $recipients = [];

        foreach ($members as $member) {
            // A missing account must not silently drop a member: the list below replaces the current one.
            $forumId = $this->ensureUser($member)
                ?? throw new ForumException("Could not find or create the forum account of user #{$member->getKey()}.");

            $recipients[$forumId] = ['type' => 'users', 'id' => (string) $forumId];
        }

        $response = $this->request()->patch('discussions/'.$id, [
            'data' => [
                'type' => 'discussions',
                'id' => (string) $id,
                'relationships' => [
                    'recipientUsers' => ['data' => array_values($recipients)],
                    'recipientGroups' => ['data' => [['type' => 'groups', 'id' => (string) self::ADMIN_GROUP_ID]]],
                ],
            ],
        ]);

        $this->throwUnlessSuccessful($response, 'make the discussion private');
        $this->assertPrivacy($id, true);
    }

    public function discussionUrl(string $slug): string
    {
        return $this->forumUrl().'/d/'.rawurlencode($slug);
    }

    public function embedUrl(string $slug): string
    {
        return $this->forumUrl().'/embed/'.rawurlencode($slug);
    }

    public function forumUrl(): string
    {
        return $this->publicUrl !== '' ? rtrim($this->publicUrl, '/') : rtrim(url('/forum'), '/');
    }

    // --- setup --------------------------------------------------------------------------------

    public function checkConnection(): array
    {
        $status = ['reachable' => false, 'authorized' => false, 'title' => null, 'version' => null, 'message' => ''];

        if ($this->internalUrl === '') {
            $status['message'] = 'FLARUM_INTERNAL_URL (or FLARUM_URL) is not set.';

            return $status;
        }

        try {
            $response = Http::acceptJson()
                ->connectTimeout($this->connectTimeout)->timeout($this->timeout)
                ->get($this->apiUrl());
        } catch (ConnectionException $e) {
            $status['message'] = 'Cannot connect to '.$this->apiUrl().': '.Str::limit($e->getMessage(), 160);

            return $status;
        }

        if (! $response->successful()) {
            $status['message'] = "{$this->apiUrl()} answered HTTP {$response->status()}.";

            return $status;
        }

        $status['reachable'] = true;
        $status['title'] = $response->json('data.attributes.title');
        $status['version'] = $response->json('data.attributes.version');

        if (blank($this->apiKey)) {
            $status['message'] = 'FLARUM_API_KEY is empty.';

            return $status;
        }

        // Only the admin identity gets to see an e-mail address, so its presence proves the key works.
        try {
            $me = $this->request()->get('users/'.$this->adminUserId);
        } catch (ConnectionException $e) {
            $status['message'] = 'Lost the connection while checking the API key.';

            return $status;
        }

        if ($me->successful() && filled($me->json('data.attributes.email'))) {
            $status['authorized'] = true;
            $status['message'] = 'OK';
            $status['version'] ??= $this->flarumVersion();
        } else {
            $status['message'] = "The forum rejected FLARUM_API_KEY (acting as user #{$this->adminUserId}, HTTP {$me->status()}). "
                .'The flarum container stores the key at start; check FLARUM_API_KEY in .env and restart it.';
        }

        return $status;
    }

    /** The installed Flarum version ("2.0.2"), read from the admin-only system info; null when it cannot be told. */
    private function flarumVersion(): ?string
    {
        try {
            $info = $this->request()->get('system-info/system');
        } catch (ConnectionException) {
            return null;
        }

        return $info->successful() && preg_match('/^Flarum core: (\S+)/m', (string) $info->json('data.attributes.content'), $matches)
            ? $matches[1]
            : null;
    }

    public function tags(): array
    {
        $this->assertConfigured();

        $response = $this->request()->get('tags');
        $this->throwUnlessSuccessful($response, 'list the tags');

        $tags = [];

        foreach ((array) $response->json('data', []) as $tag) {
            $attributes = $tag['attributes'] ?? [];
            $slug = $attributes['slug'] ?? null;

            if (is_string($slug) && isset($tag['id'])) {
                $tags[$slug] = [
                    'id' => (int) $tag['id'],
                    // Flarum 2 reports isPrimary; a primary tag is one with a position that is not a child tag.
                    'primary' => (bool) ($attributes['isPrimary'] ?? (($attributes['position'] ?? null) !== null && empty($attributes['isChild']))),
                ];
            }
        }

        return $tags;
    }

    public function createTag(string $slug, string $name, ?string $description = null, ?string $color = null): int
    {
        $this->assertConfigured();

        $attributes = ['name' => $name, 'slug' => $slug, 'isHidden' => false, 'isPrimary' => true];

        if ($description !== null) {
            $attributes['description'] = $description;
        }

        if ($color !== null) {
            $attributes['color'] = $color;
        }

        $response = $this->request()->post('tags', ['data' => ['type' => 'tags', 'attributes' => $attributes]]);
        $this->throwUnlessSuccessful($response, "create the tag '{$slug}'");

        return (int) $response->json('data.id');
    }

    // --- internals ----------------------------------------------------------------------------

    private function usable(): bool
    {
        return $this->internalUrl !== ''
            && filled($this->apiKey)
            && ! Cache::has(self::DOWN_KEY);
    }

    private function assertConfigured(): void
    {
        if ($this->internalUrl === '' || blank($this->apiKey)) {
            throw new ForumException('The forum is not configured: set FLARUM_INTERNAL_URL and FLARUM_API_KEY.');
        }
    }

    /** The numeric id at the start of a discussion slug ("12-my-design" -> 12). */
    private function discussionId(string $discussion): int
    {
        if (! preg_match('/^(\d+)(?:-|$)/', trim($discussion), $matches)) {
            throw new ForumException("'{$discussion}' is not a forum discussion slug (expected '<id>-<title>').");
        }

        return (int) $matches[1];
    }

    /** @return array{private: bool, canMakePublic: bool} as the admin sees the discussion */
    private function readDiscussion(int $id): array
    {
        $response = $this->request()->get('discussions/'.$id);
        $this->throwUnlessSuccessful($response, 'read the discussion');

        return [
            'private' => (bool) $response->json('data.attributes.isPrivateDiscussion'),
            'canMakePublic' => (bool) $response->json('data.attributes.canMakePublic'),
        ];
    }

    /** Byobu answers 200 to requests it quietly ignores, so read the discussion back after changing who can see it. */
    private function assertPrivacy(int $id, bool $private): void
    {
        if ($this->readDiscussion($id)['private'] !== $private) {
            throw new ForumException("Flarum accepted the request but discussion #{$id} is still ".($private ? 'public' : 'private').'.');
        }
    }

    private function apiUrl(): string
    {
        return rtrim($this->internalUrl, '/').'/api';
    }

    /** Request acting as Flarum user $actAs (default: the admin). */
    private function request(?int $actAs = null): PendingRequest
    {
        return Http::baseUrl($this->apiUrl())
            ->acceptJson()
            ->asJson()
            ->withHeaders(['Authorization' => 'Token '.$this->apiKey.'; userId='.($actAs ?? $this->adminUserId)])
            ->connectTimeout($this->connectTimeout)
            ->timeout($this->timeout);
    }

    /** @return array{token: string, userId: int}|null null = Flarum does not accept these credentials */
    private function requestToken(User $user, string $password): ?array
    {
        $response = Http::baseUrl($this->apiUrl())
            ->acceptJson()->asJson()
            ->connectTimeout($this->connectTimeout)->timeout($this->timeout)
            ->post('token', [
                'identification' => $user->email,
                'password' => $password,
                'remember' => 1,
            ]);

        if (in_array($response->status(), [400, 401, 403], true)) {
            return null;
        }

        $this->throwUnlessSuccessful($response, 'log the user in');

        $token = $response->json('token');

        return is_string($token) && $token !== ''
            ? ['token' => $token, 'userId' => (int) $response->json('userId')]
            : null;
    }

    private function findUserId(string $email): ?int
    {
        if ($email === '') {
            return null;
        }

        $response = $this->request()->get('users', ['filter' => ['email' => $email], 'page' => ['limit' => 1]]);

        if (! $response->successful()) {
            return null;
        }

        $id = $response->json('data.0.id');

        return $id !== null ? (int) $id : null;
    }

    /** @throws EmailAlreadyTaken */
    private function createUser(User $user, string $password): int
    {
        foreach ($this->usernames->candidates((string) $user->username, (string) $user->name) as $username) {
            $response = $this->request()->post('users', [
                'data' => [
                    'type' => 'users',
                    'attributes' => [
                        'username' => $username,
                        'email' => $user->email,
                        'password' => $password,
                        'isEmailConfirmed' => true,
                    ],
                ],
            ]);

            if ($response->successful()) {
                $id = (int) $response->json('data.id');
                $this->quietenNotifications($id);

                return $id;
            }

            if ($response->status() === 422) {
                $pointers = collect($response->json('errors', []))->pluck('source.pointer')->all();

                if (in_array('/data/attributes/email', $pointers, true)) {
                    throw new EmailAlreadyTaken();
                }

                if (in_array('/data/attributes/username', $pointers, true)) {
                    continue; // taken or rejected: try the next candidate
                }
            }

            $this->throwUnlessSuccessful($response, 'create the forum account');
        }

        throw new ForumException('No free forum user name found for user #'.$user->getKey().'.');
    }

    /** Best effort: a member can only change their own preferences, so this acts as the new account. */
    private function quietenNotifications(int $forumUserId): void
    {
        try {
            $response = $this->request($forumUserId)->patch('users/'.$forumUserId, [
                'data' => [
                    'type' => 'users',
                    'id' => (string) $forumUserId,
                    'attributes' => ['preferences' => self::QUIET_PREFERENCES],
                ],
            ]);
        } catch (ConnectionException $e) {
            $response = null;
        }

        if ($response === null || ! $response->successful()) {
            Log::warning('Could not set the notification preferences of a new forum account.', [
                'forum_user_id' => $forumUserId,
                'status' => $response?->status(),
            ]);
        }
    }

    /** Put e-mail and password of an existing forum account back in line with the app. */
    private function repairAccount(int $forumUserId, User $user, string $password): void
    {
        $response = $this->request()->patch('users/'.$forumUserId, [
            'data' => [
                'type' => 'users',
                'id' => (string) $forumUserId,
                'attributes' => [
                    'email' => $user->email,
                    'isEmailConfirmed' => true,
                    'password' => $password,
                ],
            ],
        ]);

        if (! $response->successful()) {
            Log::warning('Could not repair the forum account.', [
                'user_id' => $user->getKey(),
                'forum_user_id' => $forumUserId,
                'status' => $response->status(),
            ]);
        }
    }

    /**
     * The app's per-member Flarum password; created on first use, never shown to the member. One that can no longer be
     * decrypted (APP_KEY changed) is replaced like a missing one: the login then resets the forum account to it.
     */
    private function forumPassword(User $user): string
    {
        try {
            $password = $user->forum_password;
        } catch (DecryptException) {
            Log::warning('The stored forum password could not be decrypted (APP_KEY changed?); a new one is set.', ['user_id' => $user->getKey()]);
            $password = null;
            // Saving compares with the stored value, which would fail to decrypt again: forget it first.
            $user->setRawAttributes(['forum_password' => null] + $user->getAttributes(), true);
        }

        if (blank($password)) {
            $password = Str::random(40);
            $user->forceFill(['forum_password' => $password])->saveQuietly();
        }

        return $password;
    }

    // users.forum_user_id is optional: when the column exists it pins the Flarum identity, so a
    // changed e-mail address updates the existing forum account instead of creating a second one.

    private function hasForumUserIdColumn(): bool
    {
        return self::$hasForumUserIdColumn ??= Schema::hasColumn('users', 'forum_user_id');
    }

    private function knownForumId(User $user): ?int
    {
        return $this->hasForumUserIdColumn() && $user->getAttribute('forum_user_id')
            ? (int) $user->getAttribute('forum_user_id')
            : null;
    }

    private function forgetForumId(User $user): void
    {
        if ($this->hasForumUserIdColumn() && $user->getAttribute('forum_user_id') !== null) {
            $user->forceFill(['forum_user_id' => null])->saveQuietly();
        }
    }

    private function forumUserExists(int $id): bool
    {
        $response = $this->request()->get('users/'.$id);

        if ($response->status() === 404) {
            return false;
        }

        $this->throwUnlessSuccessful($response, 'look up the forum account');

        return true;
    }

    private function rememberForumId(User $user, int $id): void
    {
        if ($this->hasForumUserIdColumn() && (int) $user->getAttribute('forum_user_id') !== $id) {
            $user->forceFill(['forum_user_id' => $id])->saveQuietly();
        }
    }

    private function throwUnlessSuccessful(Response $response, string $action): void
    {
        if ($response->successful()) {
            return;
        }

        $details = collect($response->json('errors', []))
            ->map(fn ($error) => trim(($error['detail'] ?? $error['code'] ?? '').' '.($error['source']['pointer'] ?? '')))
            ->filter()
            ->implode('; ');

        throw new ForumException(
            "Flarum could not {$action} (HTTP {$response->status()})".($details !== '' ? ": {$details}" : '.'),
            $response->status(),
        );
    }

    private function logFailure(string $what, Throwable $e, User $user): void
    {
        if ($e instanceof ConnectionException) {
            Cache::put(self::DOWN_KEY, true, self::DOWN_SECONDS);
        }

        Log::warning("Forum: could not {$what}.", [
            'user_id' => $user->getKey(),
            'error' => Str::limit($e->getMessage(), 300),
        ]);
    }
}
