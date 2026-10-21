<?php

namespace App\Services\Forum;

use App\Models\User;

/** Bound when FORUM_ENABLED=false: the rest of the app calls the forum without caring. */
class NullForumClient implements ForumClient
{
    public function enabled(): bool
    {
        return false;
    }

    public function ensureUser(User $user): ?int
    {
        return null;
    }

    public function issueRememberToken(User $user): ?string
    {
        return null;
    }

    public function createDiscussion(string $title, string $content, string $tagSlug, User $author): string
    {
        throw new ForumException('The forum is disabled (FORUM_ENABLED=false).');
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
        return (string) config('forum.url');
    }

    public function checkConnection(): array
    {
        return [
            'reachable' => false,
            'authorized' => false,
            'title' => null,
            'version' => null,
            'message' => 'The forum is disabled (FORUM_ENABLED=false).',
        ];
    }

    public function tags(): array
    {
        return [];
    }

    public function createTag(string $slug, string $name, ?string $description = null, ?string $color = null): int
    {
        throw new ForumException('The forum is disabled (FORUM_ENABLED=false).');
    }
}
