<?php

use App\Models\User;
use App\Services\Forum\FlarumClient;
use App\Services\Forum\ForumException;
use App\Services\Forum\UsernameSanitizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const FORUM_API = 'http://flarum.test:8000/forum/api';

function flarum(): FlarumClient
{
    return new FlarumClient(
        'https://shop.test/forum',
        'http://flarum.test:8000/forum',
        'master-key-123',
        1,
        new UsernameSanitizer(['forumadmin']),
    );
}

/** The recorded requests as [METHOD, URL path+query, decoded JSON body, Authorization header]. */
function recorded(): array
{
    return Http::recorded()->map(fn (array $pair) => [
        $pair[0]->method(),
        $pair[0]->url(),
        $pair[0]->data(),
        $pair[0]->header('Authorization')[0] ?? null,
    ])->all();
}

beforeEach(function () {
    Cache::flush();
});

it('builds public links from FLARUM_URL', function () {
    $client = flarum();

    expect($client->forumUrl())->toBe('https://shop.test/forum')
        ->and($client->discussionUrl('12-my-design'))->toBe('https://shop.test/forum/d/12-my-design')
        ->and($client->embedUrl('12-my-design'))->toBe('https://shop.test/forum/embed/12-my-design')
        ->and($client->enabled())->toBeTrue();
});

it('creates a forum account with a generated password, sanitized name and the master key', function () {
    Http::fake([
        FORUM_API.'/users?*' => Http::response(['data' => []]),
        FORUM_API.'/users' => Http::response(['data' => ['id' => '7', 'type' => 'users']], 201),
    ]);

    $user = User::factory()->create(['username' => 'José Müller', 'email' => 'jose@example.test', 'forum_password' => null]);

    expect(flarum()->ensureUser($user))->toBe(7);

    [$lookup, $create] = recorded();
    expect($lookup[0])->toBe('GET')
        ->and($lookup[1])->toContain('filter%5Bemail%5D=jose%40example.test')
        ->and($lookup[3])->toBe('Token master-key-123; userId=1');

    $attributes = $create[2]['data']['attributes'];
    $user->refresh();

    expect($create[0])->toBe('POST')
        ->and($attributes['username'])->toBe('Jose_Muller')
        ->and($attributes['email'])->toBe('jose@example.test')
        ->and($attributes['isEmailConfirmed'])->toBeTrue()
        ->and($user->forum_password)->not->toBeEmpty()
        ->and($attributes['password'])->toBe($user->forum_password);
});

it('does nothing when the account already exists', function () {
    Http::fake([FORUM_API.'/users?*' => Http::response(['data' => [['id' => '42']]])]);

    expect(flarum()->ensureUser(User::factory()->create()))->toBe(42);

    Http::assertSentCount(1);
});

it('tries another name when the username is taken, and never uses the forum admin name', function () {
    $taken = ['errors' => [['status' => '422', 'code' => 'validation_error', 'source' => ['pointer' => '/data/attributes/username']]]];

    Http::fake([
        FORUM_API.'/users?*' => Http::response(['data' => []]),
        FORUM_API.'/users' => Http::sequence()
            ->push($taken, 422)
            ->push(['data' => ['id' => '9']], 201),
    ]);

    $user = User::factory()->create(['username' => 'ForumAdmin']);

    expect(flarum()->ensureUser($user))->toBe(9);

    $names = collect(recorded())->where(0, 'POST')->map(fn ($r) => $r[2]['data']['attributes']['username'])->values()->all();

    // "ForumAdmin" is reserved, so the first attempt is already numbered; the next one follows.
    expect($names)->toBe(['ForumAdmin_2', 'ForumAdmin_3']);
});

it('adopts an existing forum account when the e-mail is already registered there', function () {
    $emailTaken = ['errors' => [['status' => '422', 'source' => ['pointer' => '/data/attributes/email']]]];

    Http::fake([
        FORUM_API.'/users?*' => Http::sequence()
            ->push(['data' => []])
            ->push(['data' => [['id' => '55']]]),
        FORUM_API.'/users' => Http::response($emailTaken, 422),
    ]);

    expect(flarum()->ensureUser(User::factory()->create()))->toBe(55);
});

it('returns the remember token on the first try for a known member', function () {
    Http::fake([FORUM_API.'/token' => Http::response(['token' => 'abc123', 'userId' => 7])]);

    $user = User::factory()->create(['email' => 'a@example.test', 'forum_password' => 'secret-forum-pass']);

    expect(flarum()->issueRememberToken($user))->toBe('abc123');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->url() === FORUM_API.'/token'
        && $request['identification'] === 'a@example.test'
        && $request['password'] === 'secret-forum-pass'
        && $request['remember'] === 1);
});

it('creates the account on the first login and then logs in', function () {
    Http::fake([
        FORUM_API.'/token' => Http::sequence()
            ->push(['errors' => [['code' => 'not_authenticated']]], 401)
            ->push(['token' => 'fresh-token', 'userId' => 8]),
        FORUM_API.'/users?*' => Http::response(['data' => []]),
        FORUM_API.'/users' => Http::response(['data' => ['id' => '8']], 201),
    ]);

    $user = User::factory()->create(['forum_password' => null]);

    expect(flarum()->issueRememberToken($user))->toBe('fresh-token');

    $methods = collect(recorded())->map(fn ($r) => $r[0].' '.parse_url($r[1], PHP_URL_PATH))->all();
    expect($methods)->toBe([
        'POST /forum/api/token',
        'GET /forum/api/users',
        'POST /forum/api/users',
        'POST /forum/api/token',
    ]);
});

it('resets the forum password of an account that exists but does not match', function () {
    Http::fake([
        FORUM_API.'/token' => Http::sequence()
            ->push([], 401)
            ->push([], 401)
            ->push(['token' => 'repaired', 'userId' => 12]),
        FORUM_API.'/users?*' => Http::response(['data' => [['id' => '12']]]),
        FORUM_API.'/users/12' => Http::response(['data' => ['id' => '12']]),
    ]);

    $user = User::factory()->create(['email' => 'new@example.test', 'forum_password' => 'pw-from-app']);

    expect(flarum()->issueRememberToken($user))->toBe('repaired');

    $patch = collect(recorded())->first(fn ($r) => $r[0] === 'PATCH');
    expect($patch[2]['data']['attributes'])->toMatchArray([
        'email' => 'new@example.test',
        'password' => 'pw-from-app',
    ]);
});

it('never throws into the login when the forum is down, and backs off briefly', function () {
    Http::fake(fn () => throw new ConnectionException('connection refused'));

    $user = User::factory()->create();

    expect(flarum()->issueRememberToken($user))->toBeNull();

    // The second attempt does not even try to connect.
    Http::fake(['*' => Http::response([], 500)]);
    expect(flarum()->issueRememberToken($user))->toBeNull()
        ->and(flarum()->ensureUser($user))->toBeNull();

    Http::assertNothingSent();
});

it('returns null (not an exception) on forum errors during login', function () {
    Http::fake([FORUM_API.'/token' => Http::response('boom', 500)]);

    expect(flarum()->issueRememberToken(User::factory()->create()))->toBeNull();
});

it('looks up the tag by slug and posts the discussion as the author', function () {
    Http::fake([
        FORUM_API.'/users?*' => Http::response(['data' => [['id' => '31']]]),
        FORUM_API.'/tags' => Http::response(['data' => [
            ['id' => '1', 'type' => 'tags', 'attributes' => ['slug' => 'general']],
            ['id' => '5', 'type' => 'tags', 'attributes' => ['slug' => 'designs']],
        ]]),
        FORUM_API.'/discussions' => Http::response(['data' => ['id' => '77', 'attributes' => ['slug' => '77-nice-speaker']]], 201),
    ]);

    $slug = flarum()->createDiscussion(
        "  Nice   <b>Speaker</b>\n".str_repeat('x', 100),
        'Hello **world**',
        'designs',
        User::factory()->create(),
    );

    expect($slug)->toBe('77-nice-speaker');

    $post = collect(recorded())->first(fn ($r) => $r[0] === 'POST');
    $data = $post[2]['data'];

    expect($post[3])->toBe('Token master-key-123; userId=31')
        ->and($data['type'])->toBe('discussions')
        ->and($data['attributes']['content'])->toBe('Hello **world**')
        ->and($data['attributes']['title'])->toStartWith('Nice Speaker xxx')->and(strlen($data['attributes']['title']))->toBeLessThanOrEqual(80)
        ->and($data['relationships']['tags']['data'])->toBe([['type' => 'tags', 'id' => '5']]);
});

it('refuses to create a discussion in a tag that does not exist', function () {
    Http::fake([
        FORUM_API.'/users?*' => Http::response(['data' => [['id' => '31']]]),
        FORUM_API.'/tags' => Http::response(['data' => [['id' => '1', 'attributes' => ['slug' => 'general']]]]),
    ]);

    flarum()->createDiscussion('Title', 'Body', 'designs', User::factory()->create());
})->throws(ForumException::class, "tag 'designs' does not exist");

it('reports Flarum validation errors when creating a discussion', function () {
    Http::fake([
        FORUM_API.'/users?*' => Http::response(['data' => [['id' => '31']]]),
        FORUM_API.'/tags' => Http::response(['data' => [['id' => '5', 'attributes' => ['slug' => 'designs']]]]),
        FORUM_API.'/discussions' => Http::response(['errors' => [['detail' => 'You are not allowed to do that.', 'source' => ['pointer' => '/data']]]], 403),
    ]);

    flarum()->createDiscussion('Title', 'Body', 'designs', User::factory()->create());
})->throws(ForumException::class, 'HTTP 403');

it('lists tags by slug and creates new ones', function () {
    Http::fake([
        FORUM_API.'/tags' => Http::sequence()
            ->push(['data' => [
                ['id' => '2', 'attributes' => ['slug' => 'designs', 'position' => 0, 'isChild' => false]],
                ['id' => '4', 'attributes' => ['slug' => 'misc', 'position' => null, 'isChild' => false]],
            ]])
            ->push(['data' => ['id' => '9', 'attributes' => ['slug' => 'drivers']]], 201),
    ]);

    expect(flarum()->tags())->toBe([
        'designs' => ['id' => 2, 'primary' => true],
        'misc' => ['id' => 4, 'primary' => false],
    ])->and(flarum()->createTag('drivers', 'Drivers', 'About drivers', '#2F7FBF'))->toBe(9);

    // Flarum only accepts a discussion with exactly one *primary* tag, so new tags must be primary.
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request['data']['attributes']['primary'] === true
        && $request['data']['attributes']['slug'] === 'drivers'
        && $request['data']['attributes']['name'] === 'Drivers'
        && $request['data']['attributes']['color'] === '#2F7FBF');
});

it('checks the connection and the API key', function () {
    Http::fake([
        FORUM_API => Http::response(['data' => ['attributes' => ['title' => 'My Forum', 'version' => '1.8.20']]]),
        FORUM_API.'/users/1' => Http::response(['data' => ['attributes' => ['username' => 'forumadmin', 'email' => 'a@b.test']]]),
    ]);

    expect(flarum()->checkConnection())->toMatchArray([
        'reachable' => true,
        'authorized' => true,
        'title' => 'My Forum',
        'version' => '1.8.20',
    ]);
});

it('detects a rejected API key (no e-mail visible = guest)', function () {
    Http::fake([
        FORUM_API => Http::response(['data' => ['attributes' => ['title' => 'My Forum']]]),
        FORUM_API.'/users/1' => Http::response(['data' => ['attributes' => ['username' => 'forumadmin']]]),
    ]);

    $status = flarum()->checkConnection();

    expect($status['reachable'])->toBeTrue()
        ->and($status['authorized'])->toBeFalse()
        ->and($status['message'])->toContain('rejected FLARUM_API_KEY');
});

it('reports an unreachable forum', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    $status = flarum()->checkConnection();

    expect($status['reachable'])->toBeFalse()
        ->and($status['message'])->toContain('Cannot connect');
});
