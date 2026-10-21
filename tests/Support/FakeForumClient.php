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

        $this->discussions[] = ['title' => $title, 'content' => $content, 'tag' => $tagSlug, 'author' => $author->getKey()];

        return count($this->discussions).'-'.str($title)->slug();
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
            'version' => '1.8.20',
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
