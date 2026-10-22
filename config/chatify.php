<?php

// Which real-time server: the self-hosted Reverb (default) or pusher.com (CHATIFY_DRIVER=pusher). See 'pusher'/'client' below.
$driver = env('CHATIFY_DRIVER', 'reverb') === 'pusher' ? 'pusher' : 'reverb';
// '/ws' (or '' = no prefix), normalised: leading slash, no trailing slash.
$serverPath = trim((string) env('REVERB_SERVER_PATH', '/ws'), '/');
$serverPath = $serverPath === '' ? '' : '/'.$serverPath;

// Public address of Reverb as the BROWSER sees it. Production (APP_URL is https): wss://<host>:443 through the web
// server. Local `php artisan serve` (APP_URL is http): ws://<host>:8080, straight to `php artisan reverb:start`.
$appUrl = parse_url((string) env('APP_URL', 'http://localhost')) ?: [];
$clientScheme = env('REVERB_CLIENT_SCHEME', ($appUrl['scheme'] ?? 'http') === 'https' ? 'https' : 'http');

return [
    /*
    |-------------------------------------
    | Messenger display name
    |-------------------------------------
    */
    'name' => env('CHATIFY_NAME', 'Chatify Messenger'),

    /*
    |-------------------------------------
    | The disk on which to store added
    | files and derived images by default.
    |-------------------------------------
    */
    'storage_disk_name' => env('CHATIFY_STORAGE_DISK', 'public'),

    /*
    |-------------------------------------
    | Routes configurations
    |-------------------------------------
    */
    'routes' => [
        'custom' => env('CHATIFY_CUSTOM_ROUTES', false),
        'prefix' => env('CHATIFY_ROUTES_PREFIX', 'chatify'),
        'middleware' => env('CHATIFY_ROUTES_MIDDLEWARE', ['web','auth']),
        'namespace' => env('CHATIFY_ROUTES_NAMESPACE', 'Chatify\Http\Controllers'),
    ],
    'api_routes' => [
        'prefix' => env('CHATIFY_API_ROUTES_PREFIX', 'chatify/api'),
        'middleware' => env('CHATIFY_API_ROUTES_MIDDLEWARE', ['api']),
        'namespace' => env('CHATIFY_API_ROUTES_NAMESPACE', 'Chatify\Http\Controllers\Api'),
    ],

    /*
    |-------------------------------------
    | Real-time server (WebSockets)
    |-------------------------------------
    |
    | Chatify speaks the Pusher protocol. By default it is served by the self-hosted
    | Laravel Reverb server (the `reverb` container, config/reverb.php), so nothing
    | goes through pusher.com. Set CHATIFY_DRIVER=pusher (and the PUSHER_APP_* values
    | in .env) only if you really want pusher.com instead.
    |
    | Two separate halves, because the server and the browser reach Reverb differently:
    |
    |   'pusher'  SERVER side: the PHP code (ChatifyMessenger) sends events to Reverb and
    |             signs private-channel logins. Inside Docker this is http://reverb:8080.
    |             Holds the SECRET: never printed to a page.
    |   'client'  BROWSER side: the only part that reaches the page (resources/views/vendor/
    |             Chatify/layouts/footerLinks.blade.php). Public key, public host, no secret.
    |             Inside Docker this is wss://APP_DOMAIN/ws, i.e. through Caddy and your
    |             web server.
    */
    'driver' => $driver,

    'pusher' => $driver === 'pusher' ? [
        'debug' => env('APP_DEBUG', false),
        'key' => (string) env('PUSHER_APP_KEY', ''),
        'secret' => (string) env('PUSHER_APP_SECRET', ''),
        'app_id' => (string) env('PUSHER_APP_ID', ''),
        'options' => [
            'cluster' => env('PUSHER_APP_CLUSTER', 'mt1'),
            'host' => env('PUSHER_HOST') ?: 'api-'.env('PUSHER_APP_CLUSTER', 'mt1').'.pusher.com',
            'port' => env('PUSHER_PORT', 443),
            'scheme' => env('PUSHER_SCHEME', 'https'),
            'encrypted' => true,
            'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
        ],
    ] : [
        'debug' => env('APP_DEBUG', false),
        'key' => (string) env('REVERB_APP_KEY', ''),
        'secret' => (string) env('REVERB_APP_SECRET', ''),
        'app_id' => (string) env('REVERB_APP_ID', ''),
        'options' => [
            // Where PHP finds Reverb: the `reverb` container (inside Docker) or localhost (`php artisan reverb:start`).
            'host' => env('REVERB_HOST', 'localhost'),
            'port' => (int) env('REVERB_PORT', 8080),
            'scheme' => env('REVERB_SCHEME', 'http'),
            'encrypted' => env('REVERB_SCHEME', 'http') === 'https',
            'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
            // URL prefix of the Reverb server (REVERB_SERVER_PATH). The trailing slash is needed so that the
            // Pusher PHP SDK (Guzzle) appends "apps/{id}/events" to it instead of to its parent directory.
            'path' => $serverPath.'/',
        ],
    ],

    // Handed to the browser, so it holds NO secret and no app id: the public key, a debug flag and the
    // connection options. footerLinks.blade.php prints exactly this (and nothing else) into the page.
    'client' => $driver === 'pusher' ? [
        'debug' => env('APP_DEBUG', false),
        'key' => (string) env('PUSHER_APP_KEY', ''),
        'options' => [
            'cluster' => env('PUSHER_APP_CLUSTER', 'mt1'),
            'host' => env('PUSHER_HOST') ?: 'api-'.env('PUSHER_APP_CLUSTER', 'mt1').'.pusher.com',
            'port' => env('PUSHER_PORT', 443),
            'encrypted' => true,
            'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
        ],
    ] : [
        'debug' => env('APP_DEBUG', false),
        'key' => (string) env('REVERB_APP_KEY', ''),
        'options' => [
            // Pusher JS refuses to start without a `cluster` option, even when wsHost is set (Laravel Echo passes
            // the same empty string for Reverb). There is no cluster here: the host below is what counts.
            'cluster' => '',
            'host' => env('REVERB_CLIENT_HOST', $appUrl['host'] ?? 'localhost'),
            'port' => (int) env('REVERB_CLIENT_PORT', $clientScheme === 'https' ? 443 : 8080),
            'useTLS' => $clientScheme === 'https',
            'encrypted' => $clientScheme === 'https',
            // Same prefix as the server; public/js/chatify/code.js hands it to Pusher JS as `wsPath`.
            'path' => $serverPath,
            // Self-hosted server: no Pusher cluster, WebSocket only, no usage statistics sent to pusher.com.
            'selfHosted' => true,
        ],
    ],

    /*
    |-------------------------------------
    | User Avatar
    |-------------------------------------
    */
    'user_avatar' => [
        'folder' => 'users-avatar',
        'default' => 'avatar.png',
    ],

    /*
    |-------------------------------------
    | Gravatar
    |
    | imageset property options:
    | [ 404 | mp | identicon (default) | monsterid | wavatar ]
    |-------------------------------------
    */
    'gravatar' => [
        'enabled' => true,
        'image_size' => 200,
        'imageset' => 'identicon'
    ],

    /*
    |-------------------------------------
    | Attachments
    |-------------------------------------
    */
    'attachments' => [
        'folder' => 'attachments',
        'download_route_name' => 'attachments.download',
        'allowed_images' => (array) ['png','jpg','jpeg','gif'],
        'allowed_files' => (array) ['zip','rar','txt'],
        'max_upload_size' => env('CHATIFY_MAX_FILE_SIZE', 150), // MB
    ],

    /*
    |-------------------------------------
    | Messenger's colors
    |-------------------------------------
    */
    'colors' => (array) [
        '#2180f3',
        '#2196F3',
        '#00BCD4',
        '#3F51B5',
        '#673AB7',
        '#4CAF50',
        '#FFC107',
        '#FF9800',
        '#ff2522',
        '#9C27B0',
    ],
    /*
    |-------------------------------------
    | Sounds
    | You can enable/disable the sounds and
    | change sound's name/path placed at
    | `public/` directory of your app.
    |
    |-------------------------------------
    */
    'sounds' => [
        'enabled' => true,
        'public_path' => 'sounds/chatify',
        'new_message' => 'new-message-sound.mp3',
    ]
];
