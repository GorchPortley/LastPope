<?php

namespace Tests\Support;

use App\Models\User;
use App\Services\Forum\ForumClient;
use App\Services\Forum\ForumException;

/** In-memory ForumClient that records what the app asked it to do. Bind it with FakeForumClient::install(). */
class FakeForumClient implements ForumClient
{
    /** @var array<int, array{title: string, content: string, tag: string, author: int}> */
    public array $discussions = [];

    /** @var array<int, array{discussion: string, content: string, author: int}> */
    public array $posts = [];

    /** @var array<string, int> discussion slug => replies that already exist (written by somebody else) */
    public array $existingReplies = [];

    /**
     * @var array<string, array<int, int>|null> discussion slug => ids of the users it is private to (besides the
     *                                           forum's administrators); null = public. Slugs never restricted are missing = public.
     */
    public array $visibility = [];

    /** @var array<int, array{discussion: string, members: array<int, int>|null}> every makeDiscussionPublic / makeDiscussionPrivate call */
    public array $visibilityCalls = [];

    /** The next N createDiscussion / createPost calls fail with Flarum's "429 slow down". */
    public int $throttle = 0;

    /** @var array<int, int> */
    public array $ensuredUsers = [];

    /** @var array<int, int> */
    public array $tokenRequests = [];

    /** @var array<string, int> slug => id (all primary) */
    public array $tags = [];

    /** @var array<int, string> slugs of tags that exist but are not primary */
    public array $secondaryTags = [];

    public ?string $token = 'remember-token-123';

    public bool $reachable = true;

    public bool $authorized = true;

    public function __construct(public bool $enabled = true)
    {
    }

    public static function install(bool $enabled = true): self
    {
        $fake = new self($enabled);
        app()->instance(ForumClient::class, $fake);

        return $fake;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function ensureUser(User $user): ?int
    {
        $this->ensuredUsers[] = $user->getKey();

        return 100 + $user->getKey();
    }

    /** @var array<int, int> ids of the users whose avatar was uploaded, in order */
    public array $avatarSyncs = [];

    public function syncAvatar(User $user): bool
    {
        $this->avatarSyncs[] = $user->getKey();

        return true;
    }

    public function issueRememberToken(User $user): ?string
    {
        $this->tokenRequests[] = $user->getKey();

        return $this->token;
    }

    public function createDiscussion(string $title, string $content, string $tagSlug, User $author): string
    {
        if (! isset($this->tags[$tagSlug])) {
            throw new ForumException("The forum tag '{$tagSlug}' does not exist.");
        }

        $this->failWhenThrottled();

        $this->discussions[] = ['title' => $title, 'content' => $content, 'tag' => $tagSlug, 'author' => $author->getKey()];

        return count($this->discussions).'-'.str($title)->slug();
    }

    public function createPost(string $discussion, string $content, User $author): ?int
    {
        $this->failWhenThrottled();

        // Like Flarum: a private discussion does not exist for anybody who cannot see it.
        if (($this->visibility[$discussion] ?? null) !== null && ! in_array($author->getKey(), $this->visibility[$discussion], true)) {
            throw new ForumException('Flarum could not post the reply (HTTP 404): not_found', 404);
        }

        $this->posts[] = ['discussion' => $discussion, 'content' => $content, 'author' => $author->getKey()];

        return 1000 + count($this->posts);
    }

    public function replyCount(string $discussion): ?int
    {
        return ($this->existingReplies[$discussion] ?? 0)
            + count(array_filter($this->posts, fn (array $post) => $post['discussion'] === $discussion));
    }

    public function makeDiscussionPublic(string $discussion): void
    {
        $this->visibility[$discussion] = null;
        $this->visibilityCalls[] = ['discussion' => $discussion, 'members' => null];
    }

    public function makeDiscussionPrivate(string $discussion, iterable $members): void
    {
        $ids = collect($members)->map(fn (User $user) => $user->getKey())->unique()->sort()->values()->all();

        $this->visibility[$discussion] = $ids;
        $this->visibilityCalls[] = ['discussion' => $discussion, 'members' => $ids];
    }

    private function failWhenThrottled(): void
    {
        if ($this->throttle > 0) {
            $this->throttle--;

            throw new ForumException('Flarum could not post (HTTP 429): Too Many Requests', 429);
        }
    }

    public function discussionUrl(string $slug): string
    {
        return 'https://forum.test/forum/d/'.$slug;
    }

    public function embedUrl(string $slug): string
    {
        return 'https://forum.test/forum/embed/'.$slug;
    }

    public function forumUrl(): string
    {
        return 'https://forum.test/forum';
    }

    public function checkConnection(): array
    {
        return [
            'reachable' => $this->reachable,
            'authorized' => $this->reachable && $this->authorized,
            'title' => 'Test Forum',
            'version' => '2.0.2',
            'message' => $this->reachable ? 'OK' : 'connection refused',
        ];
    }

    public function tags(): array
    {
        $tags = [];

        foreach ($this->tags as $slug => $id) {
            $tags[$slug] = ['id' => $id, 'primary' => ! in_array($slug, $this->secondaryTags, true)];
        }

        return $tags;
    }

    public function createTag(string $slug, string $name, ?string $description = null, ?string $color = null): int
    {
        return $this->tags[$slug] = count($this->tags) + 1;
    }
}
