<?php

namespace App\Services\Forum;

use App\Models\User;

/**
 * The app's view of the Flarum forum.
 *
 * Two implementations are bound by ForumServiceProvider: FlarumClient (talks to Flarum over HTTP)
 * and NullForumClient (FORUM_ENABLED=false: every call is a harmless no-op). Tests bind a fake.
 *
 * Failure policy: the calls that run inside a web request (ensureUser, issueRememberToken) never
 * throw, they log and return null, so a forum outage can never block a login or registration.
 * createDiscussion, tags and createTag throw ForumException; the queued job retries, and
 * `forum:setup` reports the error.
 */
interface ForumClient
{
    /** True when the forum integration is switched on (FORUM_ENABLED). */
    public function enabled(): bool;

    /**
     * Make sure a Flarum account exists for the user (creating it if needed) and return its Flarum user id.
     * The Flarum user name is derived from the app's username; collisions get a numeric suffix.
     */
    public function ensureUser(User $user): ?int;

    /**
     * Log the user in at Flarum and return the "remember" access token that goes into the
     * `flarum_remember` cookie (path /forum). Creates the Flarum account on first use.
     */
    public function issueRememberToken(User $user): ?string;

    /**
     * Start a discussion authored by $author in the forum tag with slug $tagSlug.
     *
     * @return string the discussion slug as Flarum reports it ("{id}-{title-slug}"),
     *                usable in discussionUrl() and embedUrl()
     *
     * @throws ForumException
     */
    public function createDiscussion(string $title, string $content, string $tagSlug, User $author): string;

    /** Public link to a discussion, e.g. https://example.com/forum/d/12-my-design. */
    public function discussionUrl(string $slug): string;

    /** Link for the iframe on design/driver pages, e.g. https://example.com/forum/embed/12-my-design. */
    public function embedUrl(string $slug): string;

    /** Public forum home, e.g. https://example.com/forum. */
    public function forumUrl(): string;

    /**
     * Probe the forum for `forum:setup`.
     *
     * @return array{reachable: bool, authorized: bool, title: ?string, version: ?string, message: string}
     */
    public function checkConnection(): array;

    /**
     * All forum tags keyed by slug. A new discussion needs exactly one *primary* tag
     * (a tag with a position in the forum's tag list); secondary tags cannot be used alone.
     *
     * @return array<string, array{id: int, primary: bool}>
     *
     * @throws ForumException
     */
    public function tags(): array;

    /**
     * Create a primary tag and return its id.
     *
     * @throws ForumException
     */
    public function createTag(string $slug, string $name, ?string $description = null, ?string $color = null): int;
}
