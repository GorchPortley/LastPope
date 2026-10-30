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
 * createDiscussion, createPost, replyCount, makeDiscussionPublic, makeDiscussionPrivate, tags and createTag throw
 * ForumException; the queued job retries, and `forum:setup` / `forum:seed-demo` report the error.
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
     * Upload the user's site avatar (or the site's default avatar) as their Flarum avatar, creating the Flarum account
     * when needed. False when the forum is off or the account could not be made. Throws ForumException when Flarum
     * refuses the upload.
     */
    public function syncAvatar(User $user): bool;

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

    /**
     * Reply in a discussion as $author (same per-member credentials as createDiscussion()).
     *
     * @param  string  $discussion  the stored discussion slug "{id}-{title-slug}"; only the leading id is used
     * @return int|null the new post's id, null when the forum is disabled
     *
     * @throws ForumException
     */
    public function createPost(string $discussion, string $content, User $author): ?int;

    /**
     * How many replies a discussion has (its posts after the opening one), or null when that cannot be
     * told (forum disabled, discussion unknown). `forum:seed-demo` uses it to skip threads that have replies.
     *
     * @throws ForumException
     */
    public function replyCount(string $discussion): ?int;

    /**
     * Let everyone see the discussion again (in the tag it was created in). Does nothing when it is public already.
     *
     * @param  string  $discussion  the stored discussion slug "{id}-{title-slug}"; only the leading id is used
     *
     * @throws ForumException
     */
    public function makeDiscussionPublic(string $discussion): void;

    /**
     * Make the discussion private: only $members and the forum's administrators can see it and reply. The member
     * list is applied as given (anybody not on it loses access), so pass everybody who may see it. Does nothing
     * when the discussion is private to exactly these members already. Every member needs a forum account, which
     * is created when missing; when one cannot be had nothing is changed and the call throws.
     *
     * @param  string  $discussion  the stored discussion slug "{id}-{title-slug}"; only the leading id is used
     * @param  iterable<User>  $members
     *
     * @throws ForumException
     */
    public function makeDiscussionPrivate(string $discussion, iterable $members): void;

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
