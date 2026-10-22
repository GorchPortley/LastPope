<?php

/*
|--------------------------------------------------------------------------
| Chatify over the self-hosted Reverb server (replaces pusher.com)
|--------------------------------------------------------------------------
|
| config/chatify.php has two halves: 'pusher' (what PHP uses to reach the server; holds the secret) and
| 'client' (what the browser gets). resources/views/vendor/Chatify/layouts/footerLinks.blade.php prints only
| the client half. No network here: Reverb itself is exercised by hand, see docs/DEPLOY.md.
*/

use App\Models\User;

/** Env names config/chatify.php (and config/broadcasting.php) read. */
const CHATIFY_ENV_KEYS = [
    'CHATIFY_DRIVER', 'APP_URL',
    'REVERB_APP_ID', 'REVERB_APP_KEY', 'REVERB_APP_SECRET', 'REVERB_HOST', 'REVERB_PORT', 'REVERB_SCHEME',
    'REVERB_SERVER_PATH', 'REVERB_CLIENT_HOST', 'REVERB_CLIENT_PORT', 'REVERB_CLIENT_SCHEME',
    'PUSHER_APP_ID', 'PUSHER_APP_KEY', 'PUSHER_APP_SECRET', 'PUSHER_APP_CLUSTER', 'PUSHER_HOST', 'PUSHER_PORT', 'PUSHER_SCHEME',
];

/**
 * config/chatify.php evaluated with exactly this environment (everything in CHATIFY_ENV_KEYS not listed is unset),
 * because the file reads env() when it is loaded.
 *
 * @param  array<string, string>  $env
 * @return array<string, mixed>
 */
function chatifyConfigFor(array $env): array
{
    $saved = [];

    foreach (CHATIFY_ENV_KEYS as $key) {
        $saved[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    foreach ($env as $key => $value) {
        putenv("$key=$value");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    try {
        return require config_path('chatify.php');
    } finally {
        foreach ($saved as $key => [$getenv, $envValue, $serverValue]) {
            $getenv === false ? putenv($key) : putenv("$key=$getenv");
            $envValue === null ? null : $_ENV[$key] = $envValue;
            $serverValue === null ? null : $_SERVER[$key] = $serverValue;
            if ($envValue === null) {
                unset($_ENV[$key]);
            }
            if ($serverValue === null) {
                unset($_SERVER[$key]);
            }
        }
    }
}

/** The environment compose.yaml gives the app containers, plus the secrets docker/setup.sh generates. */
function dockerChatifyEnv(array $extra = []): array
{
    return array_merge([
        'APP_URL' => 'https://shop.example.com',
        'REVERB_APP_ID' => '48151623',
        'REVERB_APP_KEY' => 'publickey0123456789',
        'REVERB_APP_SECRET' => 'serversecret-do-not-print',
        'REVERB_HOST' => 'reverb',
        'REVERB_PORT' => '8080',
        'REVERB_SCHEME' => 'http',
        'REVERB_SERVER_PATH' => '/ws',
        'REVERB_CLIENT_HOST' => 'shop.example.com',
        'REVERB_CLIENT_PORT' => '443',
        'REVERB_CLIENT_SCHEME' => 'https',
    ], $extra);
}

// ---------------------------------------------------------------------------
// config/chatify.php
// ---------------------------------------------------------------------------

it('sends events to the reverb container inside Docker, signed with the server secret', function () {
    $config = chatifyConfigFor(dockerChatifyEnv());

    expect($config['driver'])->toBe('reverb')
        ->and($config['pusher']['key'])->toBe('publickey0123456789')
        ->and($config['pusher']['secret'])->toBe('serversecret-do-not-print')
        ->and($config['pusher']['app_id'])->toBe('48151623')
        ->and($config['pusher']['options'])->toMatchArray([
            'host' => 'reverb',
            'port' => 8080,
            'scheme' => 'http',
            'useTLS' => false,
            // trailing slash: the Pusher PHP SDK appends "apps/{id}/events" to it
            'path' => '/ws/',
        ]);
});

it('gives the browser the public address and key only', function () {
    $client = chatifyConfigFor(dockerChatifyEnv())['client'];

    expect(array_keys($client))->toBe(['debug', 'key', 'options'])
        ->and($client['key'])->toBe('publickey0123456789')
        ->and($client['options'])->toMatchArray([
            'host' => 'shop.example.com',
            'port' => 443,
            'useTLS' => true,
            'path' => '/ws',
            'selfHosted' => true,
            // Pusher JS throws "Options object must provide a cluster" when it is null (an empty string is fine)
            'cluster' => '',
        ]);

    expect(json_encode($client))
        ->not->toContain('serversecret-do-not-print')
        ->not->toContain('48151623')
        ->not->toContain('reverb:');
});

it('derives the browser address from an https APP_URL when nothing else is set', function () {
    $env = dockerChatifyEnv(['APP_URL' => 'https://chat.example.org']);
    unset($env['REVERB_CLIENT_HOST'], $env['REVERB_CLIENT_PORT'], $env['REVERB_CLIENT_SCHEME']);

    expect(chatifyConfigFor($env)['client']['options'])
        ->toMatchArray(['host' => 'chat.example.org', 'port' => 443, 'useTLS' => true]);
});

it('defaults to ws://localhost:8080 without TLS for local development', function () {
    $config = chatifyConfigFor(['APP_URL' => 'http://localhost:8000', 'REVERB_APP_KEY' => 'k', 'REVERB_APP_SECRET' => 's', 'REVERB_APP_ID' => '1']);

    expect($config['client']['options'])->toMatchArray(['host' => 'localhost', 'port' => 8080, 'useTLS' => false, 'path' => '/ws'])
        ->and($config['pusher']['options'])->toMatchArray(['host' => 'localhost', 'port' => 8080, 'scheme' => 'http', 'useTLS' => false, 'path' => '/ws/']);
});

it('does not fail when no key is configured yet (the Pusher SDK wants strings)', function () {
    $config = chatifyConfigFor(['APP_URL' => 'http://localhost:8000']);

    expect($config['pusher']['key'])->toBe('')
        ->and($config['pusher']['secret'])->toBe('')
        ->and($config['pusher']['app_id'])->toBe('');
});

it('can drop the path prefix', function () {
    $config = chatifyConfigFor(dockerChatifyEnv(['REVERB_SERVER_PATH' => '']));

    expect($config['client']['options']['path'])->toBe('')
        ->and($config['pusher']['options']['path'])->toBe('/');
});

it('keeps pusher.com available with CHATIFY_DRIVER=pusher', function () {
    $config = chatifyConfigFor(dockerChatifyEnv([
        'CHATIFY_DRIVER' => 'pusher',
        'PUSHER_APP_ID' => '777',
        'PUSHER_APP_KEY' => 'pusher-key',
        'PUSHER_APP_SECRET' => 'pusher-secret',
        'PUSHER_APP_CLUSTER' => 'eu',
    ]));

    expect($config['driver'])->toBe('pusher')
        ->and($config['pusher']['secret'])->toBe('pusher-secret')
        ->and($config['pusher']['options'])->toMatchArray(['host' => 'api-eu.pusher.com', 'port' => 443, 'scheme' => 'https', 'useTLS' => true])
        ->and($config['client']['key'])->toBe('pusher-key')
        ->and($config['client']['options'])->not->toHaveKey('selfHosted');

    expect(json_encode($config['client']))->not->toContain('pusher-secret')->not->toContain('"777"');
});

it('ignores PUSHER_* values unless CHATIFY_DRIVER=pusher (an old secret in .env is not used)', function () {
    $config = chatifyConfigFor(dockerChatifyEnv(['PUSHER_APP_KEY' => 'old-key', 'PUSHER_APP_SECRET' => 'old-secret']));

    expect($config['driver'])->toBe('reverb')
        ->and($config['pusher']['secret'])->toBe('serversecret-do-not-print')
        ->and(json_encode($config))->not->toContain('old-secret')->not->toContain('old-key');
});

it('registers a reverb broadcasting connection and a /ws server prefix in the app config', function () {
    expect(config('broadcasting.connections.reverb.driver'))->toBe('reverb')
        ->and(config('reverb.servers.reverb.path'))->toBe('/ws')
        ->and(config('chatify.client.options.path'))->toBe('/ws')
        ->and(config('reverb.apps.apps.0.allowed_origins'))->not->toBe(['*'])
        ->and(config('chatify.driver'))->toBe('reverb');
});

// ---------------------------------------------------------------------------
// The messenger page and the auth endpoint
// ---------------------------------------------------------------------------

it('never prints the secret or the app id on the messenger page', function () {
    config([
        'chatify.pusher.key' => 'publickey0123456789',
        'chatify.pusher.secret' => 'serversecret-do-not-print',
        'chatify.pusher.app_id' => '48151623',
        'chatify.pusher.options.host' => 'reverb',
        // Someone adds a secret to the client block by mistake: the view must still drop it.
        'chatify.client' => [
            'debug' => false,
            'key' => 'publickey0123456789',
            'secret' => 'serversecret-do-not-print',
            'app_id' => '48151623',
            'options' => ['host' => 'chat.example.org', 'port' => 443, 'useTLS' => true, 'encrypted' => true, 'path' => '/ws', 'selfHosted' => true],
        ],
    ]);

    $response = $this->actingAs(User::factory()->create())->get('/chatify')->assertOk();

    $response
        ->assertDontSee('serversecret-do-not-print', false)
        ->assertDontSee('48151623', false)
        ->assertDontSee('"secret"', false)
        ->assertDontSee('app_id', false)
        ->assertDontSee('js.pusher.com', false)
        ->assertSee('/js/chatify/pusher.min.js', false)
        ->assertSee('publickey0123456789', false)
        ->assertSee('"host":"chat.example.org"', false)
        ->assertSee('"port":443', false)
        ->assertSee('"useTLS":true', false)
        ->assertSee('"path":"\/ws"', false);
});

it('hands the browser exactly the client block by default', function () {
    config(['chatify.pusher.secret' => 'serversecret-do-not-print', 'chatify.pusher.app_id' => '48151623']);

    $this->actingAs(User::factory()->create())->get('/chatify')
        ->assertOk()
        ->assertDontSee('serversecret-do-not-print', false)
        ->assertDontSee('48151623', false)
        ->assertSee('"selfHosted":true', false);
});

it('signs private channel logins with the server secret and only for logged-in users', function () {
    config(['chatify.pusher.key' => 'publickey0123456789', 'chatify.pusher.secret' => 'serversecret-do-not-print', 'chatify.pusher.app_id' => '48151623']);

    $user = User::factory()->create();
    $channel = 'private-chatify.'.$user->id;
    $socketId = '1234.5678';

    $this->post(route('pusher.auth'), ['channel_name' => $channel, 'socket_id' => $socketId])->assertRedirect();

    $login = $this->actingAs($user)
        ->post(route('pusher.auth'), ['channel_name' => $channel, 'socket_id' => $socketId])
        ->assertOk()
        ->json();

    // Pusher protocol: key:HMAC-SHA256(secret, "socket_id:channel[:channel_data]"). Reverb checks it with the same secret.
    expect($login['auth'])->toBe('publickey0123456789:'.hash_hmac('sha256', "{$socketId}:{$channel}:{$login['channel_data']}", 'serversecret-do-not-print'))
        ->and(json_decode($login['channel_data'], true)['user_id'])->toBe($user->id);
});

// ---------------------------------------------------------------------------
// Who may join which channel (Chatify signs any channel for any user; ChannelAuthorizer is the missing check)
// ---------------------------------------------------------------------------

/** Log in as $user and ask the Chatify auth endpoint for a login to $channel. */
function channelLogin($test, User $user, string $channel, string $route = 'pusher.auth')
{
    return $test->actingAs($user)->postJson(route($route), ['channel_name' => $channel, 'socket_id' => '1234.5678']);
}

beforeEach(function () {
    config(['chatify.pusher.key' => 'publickey0123456789', 'chatify.pusher.secret' => 'serversecret-do-not-print', 'chatify.pusher.app_id' => '48151623']);
});

it('answers Chatify\'s auth route, web and API, with our controller', function () {
    $routes = app('router')->getRoutes();

    expect($routes->getByName('pusher.auth')->getController())->toBeInstanceOf(App\Http\Controllers\Chatify\MessagesController::class)
        ->and($routes->getByName('api.pusher.auth')->getController())->toBeInstanceOf(App\Http\Controllers\Chatify\Api\MessagesController::class);
});

it('signs a person\'s own inbox, the presence channel and the pairs they belong to', function () {
    [$me, $other] = User::factory()->count(2)->create()->sortBy('id')->values()->all();

    foreach ([
        'private-chatify.'.$me->id,
        'presence-activeStatus',
        App\Support\Chatify\ChannelAuthorizer::pairChannel($me->id, $other->id),
        App\Support\Chatify\ChannelAuthorizer::pairChannel($other->id, $me->id), // same channel, whichever way round
    ] as $channel) {
        $login = channelLogin($this, $me, $channel)->assertOk()->json();

        expect($login['auth'])->toStartWith('publickey0123456789:')
            ->and($login['auth'])->toBe('publickey0123456789:'.hash_hmac('sha256', "1234.5678:{$channel}:{$login['channel_data']}", 'serversecret-do-not-print'));
    }

    // Chatify's presence data shape is kept
    expect(json_decode(channelLogin($this, $me, 'presence-activeStatus')->json('channel_data'), true))
        ->toBe(['user_id' => $me->id, 'user_info' => ['name' => $me->name]]);
});

it('refuses another person\'s inbox and signs nothing', function () {
    [$me, $other] = User::factory()->count(2)->create()->all();

    channelLogin($this, $me, 'private-chatify.'.$other->id)->assertForbidden()->assertJsonMissingPath('auth');
    // the same through the API route
    channelLogin($this, $me, 'private-chatify.'.$other->id, 'api.pusher.auth')->assertForbidden()->assertJsonMissingPath('auth');
    channelLogin($this, $me, 'private-chatify.'.$me->id, 'api.pusher.auth')->assertOk();
});

it('refuses conversation channels the person is not part of, or that are malformed', function () {
    [$me, $a, $b] = User::factory()->count(3)->create()->sortBy('id')->values()->all();
    $missing = (User::max('id') ?? 0) + 1000;

    foreach ([
        "private-chatify-chat.{$a->id}.{$b->id}",          // two other people
        "private-chatify-chat.{$b->id}.{$a->id}",
        "private-chatify-chat.{$me->id}.{$me->id}",        // with oneself
        "private-chatify-chat.{$me->id}.{$missing}",       // the other person does not exist
        'private-chatify-chat.0.'.$me->id,
        "private-chatify-chat.0{$me->id}.{$b->id}",        // not the canonical spelling of the id
        "private-chatify-chat.{$me->id}.{$b->id}.1",
        "private-chatify-chat.{$me->id}",
        'private-chatify-chat.',
        'private-chatify.',
        "private-chatify.{$me->id}x",
        "private-chatify.0{$me->id}",
        'private-chatify',
        'private-anything',
        'presence-other',
        'presence-activeStatus2',
        'public-chat',
        '',
    ] as $channel) {
        $response = channelLogin($this, $me, $channel);
        expect($response->status())->toBe(403, "channel [{$channel}] must be refused")
            ->and($response->json('auth'))->toBeNull();
    }

    // Control: the same user may join a genuine pair
    channelLogin($this, $me, "private-chatify-chat.{$me->id}.{$b->id}")->assertOk();
    channelLogin($this, $b, "private-chatify-chat.{$me->id}.{$b->id}")->assertOk();
    channelLogin($this, $a, "private-chatify-chat.{$me->id}.{$b->id}")->assertForbidden();
});

it('rejects a malformed socket id and anonymous requests', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('pusher.auth'), ['channel_name' => 'private-chatify.'.$user->id, 'socket_id' => 'abc'])->assertStatus(422);
    $this->actingAs($user)->postJson(route('pusher.auth'), ['channel_name' => 'private-chatify.'.$user->id])->assertStatus(422);

    auth()->logout();
    $this->postJson(route('pusher.auth'), ['channel_name' => 'private-chatify.1', 'socket_id' => '1234.5678'])->assertUnauthorized();
});

// ---------------------------------------------------------------------------
// The deployment files agree with each other
// ---------------------------------------------------------------------------

it('wires the reverb container, the Caddy route and the secrets consistently', function () {
    $compose = file_get_contents(base_path('compose.yaml'));
    // The text of one top-level block: the x-app-env anchor or a service (2-space indented key).
    $block = fn (string $start, string $indent) => preg_match('/^'.preg_quote($start, '/').'\n((?:\h*\n|'.$indent.'.*\n)*)/m', $compose, $m) ? $m[1] : '';

    $reverb = $block('  reverb:', '    ');
    expect($reverb)->toContain('command: ["php", "artisan", "reverb:start", "--host=0.0.0.0", "--port=8080"]', '/ws/up', 'healthcheck:', 'service_healthy')
        // restart: unless-stopped, logging and the volumes come from x-app-common
        ->and($reverb)->toContain('<<: *app-common')
        ->and($reverb)->not->toContain('ports:')
        ->and($reverb)->not->toContain('AUTORUN_ENABLED');

    $env = $block('x-app-env: &app-env', '  ');
    expect($env)->toContain('REVERB_APP_ID:', 'REVERB_APP_KEY:', 'REVERB_APP_SECRET:', 'REVERB_CLIENT_HOST: ${APP_DOMAIN}')
        ->and($env)->toContain('REVERB_HOST: reverb', 'REVERB_PORT: "8080"', 'REVERB_SCHEME: http', 'REVERB_SERVER_PATH: '.config('reverb.servers.reverb.path'));

    // queue and reverb get the same environment as the app (x-app-env), so a broadcast event queued later works.
    expect($block('  queue:', '    '))->toContain('<<: *app-env')
        ->and($block('  reverb:', '    '))->toContain('<<: *app-env');

    $caddy = file_get_contents(base_path('docker/caddy/site.caddy'));
    expect($caddy)->toContain('@reverb path /ws/app/*', 'reverse_proxy reverb:8080')
        ->and(strpos($caddy, 'reverse_proxy reverb:8080'))->toBeLessThan(strpos($caddy, 'reverse_proxy app:8080'))
        // Reverb's signed HTTP API (/ws/apps/...) is internal only: no Caddy rule (comments aside) mentions it
        ->and(collect(explode("\n", $caddy))->reject(fn ($line) => str_starts_with(trim($line), '#'))->implode("\n"))->not->toContain('/ws/apps');

    $setup = file_get_contents(base_path('docker/setup.sh'));
    $example = file_get_contents(base_path('.env.example'));
    foreach (['REVERB_APP_ID', 'REVERB_APP_KEY', 'REVERB_APP_SECRET'] as $name) {
        expect($setup)->toContain("ensure_secret $name ")
            ->and($example)->toMatch("/^{$name}=$/m");
    }
    expect($example)->not->toMatch('/^(PUSHER|MIX_PUSHER)_\w+=/m');

    $deploy = file_get_contents(base_path('docs/DEPLOY.md'));
    expect($deploy)->toContain('location /ws/app/', 'proxy_set_header Upgrade $http_upgrade;', 'proxy_set_header Connection "upgrade";', 'proxy_read_timeout 3600s;', 'rotate');
});

it('keeps the patched Chatify script: Reverb path, and client events on the pair channel only', function () {
    // `php artisan chatify:publish --force` would overwrite it with the package copy (no wsPath, client events on
    // the other person's inbox).
    $script = file_get_contents(public_path('js/chatify/code.js'));

    expect($script)->toContain('wsPath: chatify.pusher.options.path', 'disableStats: pusherSelfHosted')
        ->and($script)->toContain('private-chatify-chat.${Math.min(me, other)}.${Math.max(me, other)}', 'function sendClientEvent(', 'pusher.unsubscribe(pairChannelName)')
        // the open conversation loads even when the server was faster than document-ready
        ->and($script)->toContain('channel.bind("pusher:subscription_succeeded", loadOpenConversation);', 'if (channel.subscribed) loadOpenConversation();')
        // nothing subscribes to the other person's inbox any more
        ->and($script)->not->toContain('pusher.subscribe(`${channelName}.${getMessengerId()}`)')
        ->and($script)->not->toContain('clientSendChannel.trigger(')
        ->and(public_path('js/chatify/pusher.min.js'))->toBeFile()
        ->and(file_get_contents(public_path('js/chatify/pusher.min.js')))->toContain('Pusher JavaScript Library v8');

    // the JavaScript and PHP build the pair channel name the same way
    expect(App\Support\Chatify\ChannelAuthorizer::pairChannel(9, 4))->toBe('private-chatify-chat.4.9');
});
