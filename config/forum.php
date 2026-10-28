<?php

/*
|--------------------------------------------------------------------------
| Forum (Flarum) integration
|--------------------------------------------------------------------------
|
| Flarum runs in its own container and is served by Caddy under the /forum sub-path of
| the main domain (see docker/ and docs/DEPLOY.md). The app talks to it in two ways:
|
|   * server to server, through the Flarum REST API, with a master API key
|     ("Authorization: Token <key>; userId=<n>" acts as that Flarum user);
|   * single sign-on: after login the browser gets a `flarum_remember` cookie scoped to
|     the /forum path, which Flarum accepts as a login.
|
| FORUM_ENABLED=false (the default, and always in tests) swaps the HTTP client for a
| no-op one, so nothing in the app needs Flarum to be running.
|
*/

// Path of the public forum URL ("/forum"): the SSO cookie is scoped to it.
$publicUrl = rtrim((string) env('FLARUM_URL', ''), '/');
$cookiePath = parse_url($publicUrl, PHP_URL_PATH) ?: '/forum';

return [

    'enabled' => (bool) env('FORUM_ENABLED', false),

    // Public base URL as seen by browsers, e.g. https://example.com/forum
    'url' => $publicUrl,

    // Base URL the Laravel containers use to reach the Flarum API (no TLS, Docker network),
    // e.g. http://flarum:8000/forum. Defaults to the public URL.
    'internal_url' => rtrim((string) env('FLARUM_INTERNAL_URL', $publicUrl), '/'),

    // Master API key (stored in Flarum's api_keys table by the flarum container).
    'api_key' => env('FLARUM_API_KEY'),

    // Flarum user the key acts as for administrative calls (the first admin created by the installer).
    'admin_user_id' => (int) env('FLARUM_ADMIN_USER_ID', 1),

    // That admin's password. A site account with the forum admin's e-mail is logged into the forum with it, because
    // Flarum does not let the API key change the credentials of the account it acts as.
    'admin_password' => env('FLARUM_ADMIN_PASSWORD'),

    // Requests made with the master API key are not throttled by Flarum 2. Should the forum still answer 429
    // ("slow down"), `forum:seed-demo`, which writes many posts in a row, waits this many seconds before it
    // tries that member again.
    'throttle_wait' => (int) env('FORUM_THROTTLE_WAIT', 11),

    // HTTP timeouts in seconds. Logins must never hang because the forum is slow.
    'connect_timeout' => 3,
    'timeout' => 8,

    // Single sign-on cookie.
    'cookie' => [
        'name' => 'flarum_remember',
        'path' => $cookiePath,
        'lifetime_minutes' => 60 * 24 * 14,
    ],

    // Forum tags for the automatic discussions. `forum:setup` creates the ones that are missing.
    // An empty slug disables the automatic discussion for that kind of item.
    'tags' => [
        'design' => [
            'slug' => env('FORUM_DESIGN_TAG', 'designs'),
            'name' => 'Designs',
            'description' => 'Discussion of speaker designs shared on the site.',
            'color' => '#E07B00',
        ],
        'driver' => [
            'slug' => env('FORUM_DRIVER_TAG', 'drivers'),
            'name' => 'Drivers',
            'description' => 'Discussion of drivers and their measurements.',
            'color' => '#2F7FBF',
        ],
        'listing' => [
            'slug' => env('FORUM_LISTING_TAG', 'marketplace'),
            'name' => 'Marketplace',
            'description' => 'Questions about gear for sale in the marketplace.',
            'color' => '#3A9D5D',
        ],
    ],

    // Flarum user names that members must not take. Colliding or reserved names get a numeric suffix.
    'reserved_usernames' => array_values(array_unique(array_filter(array_map(
        'strtolower',
        array_merge(
            ['admin', 'administrator', 'root', 'system', 'moderator', 'flarum', 'support', 'staff'],
            [(string) env('FLARUM_ADMIN_USER', 'forumadmin')],
        ),
    )))),

];
