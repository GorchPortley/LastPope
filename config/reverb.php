<?php

/*
|--------------------------------------------------------------------------
| Laravel Reverb: the self-hosted WebSocket server (a Pusher replacement)
|--------------------------------------------------------------------------
|
| Used by Chatify (live chat). The `reverb` container of compose.yaml runs
| `php artisan reverb:start`; the app talks to it over HTTP inside the Docker
| network, browsers connect to it through the web server (wss://DOMAIN/ws/...).
| See "Live chat (Reverb)" in docs/DEPLOY.md and config/chatify.php.
|
| Everything is routed under one path prefix (default /ws), so the WebSocket
| endpoint is /ws/app/{key} and the server API is /ws/apps/{id}/events. The prefix
| keeps these URLs clear of the Laravel / Folio / Wave routes (which could one day
| get an /app or /apps page) and lets the web server send one `location` to Reverb.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Default Reverb Server
    |--------------------------------------------------------------------------
    |
    | This option controls the default server used by Reverb to handle
    | incoming messages as well as broadcasting message to all your
    | connected clients. At this time only "reverb" is supported.
    |
    */

    'default' => env('REVERB_SERVER', 'reverb'),

    /*
    |--------------------------------------------------------------------------
    | Reverb Servers
    |--------------------------------------------------------------------------
    |
    | Here you may define details for each of the supported Reverb servers.
    | Each server has its own configuration options that are defined in
    | the array below. You should ensure all the options are present.
    |
    */

    'servers' => [

        'reverb' => [
            // Where `php artisan reverb:start` listens. The compose service passes --host/--port as well.
            'host' => env('REVERB_SERVER_HOST', '0.0.0.0'),
            'port' => env('REVERB_SERVER_PORT', 8080),
            // Prefix of every URL this server answers (see the note at the top). Empty = none.
            'path' => env('REVERB_SERVER_PATH', '/ws'),
            'hostname' => env('REVERB_HOST'),
            'options' => [
                'tls' => [],
            ],
            'max_request_size' => env('REVERB_MAX_REQUEST_SIZE', 10_000),
            'scaling' => [
                'enabled' => env('REVERB_SCALING_ENABLED', false),
                'channel' => env('REVERB_SCALING_CHANNEL', 'reverb'),
                'server' => [
                    'url' => env('REDIS_URL'),
                    'host' => env('REDIS_HOST', '127.0.0.1'),
                    'port' => env('REDIS_PORT', '6379'),
                    'username' => env('REDIS_USERNAME'),
                    'password' => env('REDIS_PASSWORD'),
                    'database' => env('REDIS_DB', '0'),
                    'timeout' => env('REDIS_TIMEOUT', 60),
                ],
            ],
            'pulse_ingest_interval' => env('REVERB_PULSE_INGEST_INTERVAL', 15),
            'telescope_ingest_interval' => env('REVERB_TELESCOPE_INGEST_INTERVAL', 15),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Reverb Applications
    |--------------------------------------------------------------------------
    |
    | Here you may define how Reverb applications are managed. If you choose
    | to use the "config" provider, you may define an array of apps which
    | your server will support, including their connection credentials.
    |
    | allowed_origins: only pages served from these host names may open a WebSocket
    | (wildcards allowed, "*" = any site). Default: the host of APP_URL, so a page on
    | another site cannot use your chat server. Several hosts: REVERB_ALLOWED_ORIGINS=a.com,b.com
    |
    */

    'apps' => [

        'provider' => 'config',

        'apps' => [
            [
                'key' => env('REVERB_APP_KEY'),
                'secret' => env('REVERB_APP_SECRET'),
                'app_id' => env('REVERB_APP_ID'),
                'options' => [
                    'host' => env('REVERB_HOST'),
                    'port' => env('REVERB_PORT', 443),
                    'scheme' => env('REVERB_SCHEME', 'https'),
                    'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
                ],
                'allowed_origins' => array_values(array_filter(array_map(
                    'trim',
                    explode(',', (string) env('REVERB_ALLOWED_ORIGINS', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: '*')),
                ))) ?: ['*'],
                'ping_interval' => env('REVERB_APP_PING_INTERVAL', 60),
                'activity_timeout' => env('REVERB_APP_ACTIVITY_TIMEOUT', 30),
                'max_connections' => env('REVERB_APP_MAX_CONNECTIONS'),
                'max_message_size' => env('REVERB_APP_MAX_MESSAGE_SIZE', 10_000),
                'accept_client_events_from' => env('REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM', 'members'),
                'rate_limiting' => [
                    'enabled' => env('REVERB_APP_RATE_LIMITING_ENABLED', false),
                    'max_attempts' => env('REVERB_APP_RATE_LIMIT_MAX_ATTEMPTS', 60),
                    'decay_seconds' => env('REVERB_APP_RATE_LIMIT_DECAY_SECONDS', 60),
                    'terminate_on_limit' => env('REVERB_APP_RATE_LIMIT_TERMINATE', false),
                ],
            ],
        ],

    ],

];
