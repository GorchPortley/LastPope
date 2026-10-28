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
        FORUM_API.'/users/7' => Http::response(['data' => ['id' => '7']]),
    ]);

    $user = User::factory()->create(['username' => 'José Müller', 'email' => 'jose@example.test', 'forum_password' => null]);

    expect(flarum()->ensureUser($user))->toBe(7);

    [$lookup, $create, $preferences] = recorded();
    expect($lookup[0])->toBe('GET')
        ->and($lookup[1])->toContain('filter%5Bemail%5D=jose%40example.test')
        ->and($lookup[3])->toBe('Token master-key-123; userId=1');

    // a member can only change their own preferences: the new account switches off Byobu's "added to a private discussion"
    expect($preferences[0])->toBe('PATCH')
        ->and($preferences[1])->toBe(FORUM_API.'/users/7')
        ->and($preferences[3])->toBe('Token master-key-123; userId=7')
        ->and($preferences[2]['data']['attributes']['preferences'])->toBe([
            'notify_byobuPrivateDiscussionAdded_alert' => false,
            'notify_byobuPrivateDiscussionAdded_email' => false,
        ]);

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

it('trusts the stored forum id only while that account still exists, and keeps the pin otherwise', function () {
    Http::fake([FORUM_API.'/users/42' => Http::response(['data' => ['id' => '42']])]);

    $user = User::factory()->create(['forum_user_id' => 42]);

    expect(flarum()->ensureUser($user))->toBe(42);

    // one cheap existence check, no e-mail search and no new account
    expect(collect(recorded())->map(fn ($r) => $r[0].' '.parse_url($r[1], PHP_URL_PATH))->all())->toBe(['GET /forum/api/users/42']);
});

it('looks the account up again when the stored forum id is gone (forum was reset)', function () {
    Http::fake([
        FORUM_API.'/users/42' => Http::response(['errors' => [['status' => '404', 'code' => 'not_found']]], 404),
        FORUM_API.'/users?*' => Http::response(['data' => []]),
        FORUM_API.'/users' => Http::response(['data' => ['id' => '7']], 201),
    ]);

    $user = User::factory()->create(['forum_user_id' => 42]);

    expect(flarum()->ensureUser($user))->toBe(7)
        ->and($user->refresh()->forum_user_id)->toBe(7);
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
        FORUM_API.'/users/8' => Http::response(['data' => ['id' => '8']]),
    ]);

    $user = User::factory()->create(['forum_password' => null]);

    expect(flarum()->issueRememberToken($user))->toBe('fresh-token');

    $methods = collect(recorded())->map(fn ($r) => $r[0].' '.parse_url($r[1], PHP_URL_PATH))->all();
    expect($methods)->toBe([
        'POST /forum/api/token',
        'GET /forum/api/users',
        'POST /forum/api/users',
        'PATCH /forum/api/users/8',
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

it('replaces a forum password that no longer decrypts (APP_KEY changed) and resets the account to it', function () {
    Http::fake([
        FORUM_API.'/token' => Http::sequence()
            ->push([], 401)
            ->push([], 401)
            ->push(['token' => 'repaired', 'userId' => 12]),
        FORUM_API.'/users?*' => Http::response(['data' => [['id' => '12']]]),
        FORUM_API.'/users/12' => Http::response(['data' => ['id' => '12']]),
    ]);

    $user = User::factory()->create(['email' => 'old@example.test']);
    // what an encrypted value written with another APP_KEY looks like to this app
    Illuminate\Support\Facades\DB::table('users')->where('id', $user->id)->update(['forum_password' => Illuminate\Support\Str::random(60)]);
    $user = $user->fresh();

    expect(flarum()->issueRememberToken($user))->toBe('repaired');

    $patch = collect(recorded())->first(fn ($r) => $r[0] === 'PATCH');
    expect($patch[2]['data']['attributes']['password'])->toBe($user->fresh()->forum_password)
        ->and(strlen($user->fresh()->forum_password))->toBe(40);
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
                ['id' => '2', 'attributes' => ['slug' => 'designs', 'isPrimary' => true, 'position' => 0, 'isChild' => false]],
                ['id' => '4', 'attributes' => ['slug' => 'misc', 'isPrimary' => false, 'position' => null, 'isChild' => false]],
            ]])
            ->push(['data' => ['id' => '9', 'attributes' => ['slug' => 'drivers']]], 201),
    ]);

    expect(flarum()->tags())->toBe([
        'designs' => ['id' => 2, 'primary' => true],
        'misc' => ['id' => 4, 'primary' => false],
    ])->and(flarum()->createTag('drivers', 'Drivers', 'About drivers', '#2F7FBF'))->toBe(9);

    // Flarum only accepts a discussion with exactly one *primary* tag, so new tags must be primary. (Flarum 2 names
    // the attribute isPrimary and rejects unknown attributes with a 400, so "primary" would fail.)
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request['data']['attributes']['isPrimary'] === true
        && $request['data']['attributes']['slug'] === 'drivers'
        && $request['data']['attributes']['name'] === 'Drivers'
        && $request['data']['attributes']['color'] === '#2F7FBF');
});

it('checks the connection and the API key, and reads the Flarum version from the system info', function () {
    Http::fake([
        FORUM_API => Http::response(['data' => ['attributes' => ['title' => 'My Forum']]]),
        FORUM_API.'/users/1' => Http::response(['data' => ['attributes' => ['username' => 'forumadmin', 'email' => 'a@b.test']]]),
        FORUM_API.'/system-info/system' => Http::response(['data' => ['attributes' => ['content' => "Flarum core: 2.0.2\nPHP version: CLI: 8.3.35\n"]]]),
    ]);

    expect(flarum()->checkConnection())->toMatchArray([
        'reachable' => true,
        'authorized' => true,
        'title' => 'My Forum',
        'version' => '2.0.2',
    ]);
});

it('still reports a working connection when the version cannot be read', function () {
    Http::fake([
        FORUM_API => Http::response(['data' => ['attributes' => ['title' => 'My Forum']]]),
        FORUM_API.'/users/1' => Http::response(['data' => ['attributes' => ['email' => 'a@b.test']]]),
        FORUM_API.'/system-info/system' => Http::response(['errors' => []], 404),
    ]);

    expect(flarum()->checkConnection())->toMatchArray(['reachable' => true, 'authorized' => true, 'version' => null]);
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

// --- replies (R1.2) ---------------------------------------------------------------------------------------

it('posts a reply to the discussion as the author, with the same per-member credentials as discussions', function () {
    Http::fake([
        FORUM_API.'/users?*' => Http::response(['data' => [['id' => '44']]]),
        FORUM_API.'/posts' => Http::response(['data' => ['type' => 'posts', 'id' => '501', 'attributes' => ['number' => 2]]], 201),
    ]);

    $postId = flarum()->createPost('12-my-design', 'Great build, how thick is the baffle?', User::factory()->create());

    expect($postId)->toBe(501);

    $post = collect(recorded())->first(fn ($r) => $r[0] === 'POST');
    $data = $post[2]['data'];

    expect($post[1])->toBe(FORUM_API.'/posts')
        ->and($post[3])->toBe('Token master-key-123; userId=44') // acts as the author, not as the admin
        ->and($data['type'])->toBe('posts')
        ->and($data['attributes']['content'])->toBe('Great build, how thick is the baffle?')
        ->and($data['relationships']['discussion']['data'])->toBe(['type' => 'discussions', 'id' => '12']);
});

it('creates the forum account of a replier that has none yet', function () {
    Http::fake([
        FORUM_API.'/users?*' => Http::response(['data' => []]),
        FORUM_API.'/users' => Http::response(['data' => ['id' => '61']], 201),
        FORUM_API.'/users/61' => Http::response(['data' => ['id' => '61']]),
        FORUM_API.'/posts' => Http::response(['data' => ['id' => '7']], 201),
    ]);

    expect(flarum()->createPost('3-driver-thread', 'Nice measurements.', User::factory()->create()))->toBe(7);

    $methods = collect(recorded())->map(fn ($r) => $r[0].' '.parse_url($r[1], PHP_URL_PATH))->all();
    expect($methods)->toBe(['GET /forum/api/users', 'POST /forum/api/users', 'PATCH /forum/api/users/61', 'POST /forum/api/posts']);
});

it('takes the discussion id from the stored slug, and rejects anything else', function () {
    Http::fake([
        FORUM_API.'/users?*' => Http::response(['data' => [['id' => '44']]]),
        FORUM_API.'/posts' => Http::response(['data' => ['id' => '1']], 201),
    ]);

    flarum()->createPost('345', 'Id only is fine.', User::factory()->create());
    expect(collect(recorded())->first(fn ($r) => $r[0] === 'POST')[2]['data']['relationships']['discussion']['data']['id'])->toBe('345');

    expect(fn () => flarum()->createPost('my-design', 'No id.', User::factory()->create()))
        ->toThrow(ForumException::class, 'not a forum discussion slug');
});

it('reports Flarum errors when replying, and lets the caller see a throttled reply', function () {
    Http::fake([
        FORUM_API.'/users?*' => Http::response(['data' => [['id' => '44']]]),
        FORUM_API.'/posts' => Http::sequence()
            ->push(['errors' => [['status' => '429', 'code' => 'too_many_requests']]], 429)
            ->push(['errors' => [['detail' => 'You are not allowed to do that.']]], 403),
    ]);

    try {
        flarum()->createPost('12-x', 'Slow down?', User::factory()->create());
        $this->fail('Expected a ForumException.');
    } catch (ForumException $e) {
        expect($e->isThrottled())->toBeTrue()->and($e->getCode())->toBe(429);
    }

    try {
        flarum()->createPost('12-x', 'Forbidden?', User::factory()->create());
        $this->fail('Expected a ForumException.');
    } catch (ForumException $e) {
        expect($e->isThrottled())->toBeFalse()->and($e->getMessage())->toContain('HTTP 403');
    }
});

it('counts the replies of a discussion without its opening post', function () {
    Http::fake([
        FORUM_API.'/discussions/12' => Http::response(['data' => ['id' => '12', 'attributes' => ['commentCount' => 4]]]),
        FORUM_API.'/discussions/13' => Http::response(['data' => ['id' => '13', 'attributes' => ['commentCount' => 1]]]),
        FORUM_API.'/discussions/14' => Http::response(['errors' => []], 404),
    ]);

    expect(flarum()->replyCount('12-busy-thread'))->toBe(3)
        ->and(flarum()->replyCount('13-quiet-thread'))->toBe(0)
        ->and(flarum()->replyCount('14-gone'))->toBeNull();

    // read as the forum admin
    expect(collect(recorded())->first()[3])->toBe('Token master-key-123; userId=1');
});

it('does nothing for replies when the forum is disabled', function () {
    $null = new App\Services\Forum\NullForumClient;

    expect($null->createPost('12-x', 'Hello', User::factory()->create()))->toBeNull()
        ->and($null->replyCount('12-x'))->toBeNull();

    Http::fake();
    Http::assertNothingSent();
});

/** Flarum's answer for a discussion as the admin sees it (fof/byobu adds the two attributes). */
function discussionState(bool $private, bool $canMakePublic = true): array
{
    return ['data' => ['id' => '12', 'attributes' => ['isPrivateDiscussion' => $private, 'canMakePublic' => $canMakePublic]]];
}

it('makes a discussion private to the given members and the Admin group, and reads the result back', function () {
    Http::fake([
        FORUM_API.'/users/41' => Http::response(['data' => ['id' => '41']]),
        FORUM_API.'/users/42' => Http::response(['data' => ['id' => '42']]),
        FORUM_API.'/discussions/12' => Http::sequence()->push(discussionState(false))->push(discussionState(true)),
    ]);

    $designer = User::factory()->create(['forum_user_id' => 41]);
    $buyer = User::factory()->create(['forum_user_id' => 42]);

    // listed twice on purpose (an admin who is also the designer)
    flarum()->makeDiscussionPrivate('12-my-design', [$designer, $buyer, $designer]);

    $patch = collect(recorded())->first(fn ($r) => $r[0] === 'PATCH');

    expect($patch[1])->toBe(FORUM_API.'/discussions/12')
        ->and($patch[3])->toBe('Token master-key-123; userId=1') // the admin may edit recipients; the designer may not
        ->and($patch[2]['data']['id'])->toBe('12')
        ->and($patch[2]['data']['relationships']['recipientUsers']['data'])->toBe([
            ['type' => 'users', 'id' => '41'],
            ['type' => 'users', 'id' => '42'],
        ])
        ->and($patch[2]['data']['relationships']['recipientGroups']['data'])->toBe([['type' => 'groups', 'id' => '1']]);

    expect(collect(recorded())->last()[0])->toBe('GET'); // read back
});

it('changes nothing when a member has no forum account: a partial list would remove that member', function () {
    Http::fake([
        FORUM_API.'/users/41' => Http::response(['data' => ['id' => '41']]),
        FORUM_API.'/users?*' => Http::response(['data' => []]),
        FORUM_API.'/users' => Http::response(['errors' => [['status' => '500']]], 500),
    ]);

    $designer = User::factory()->create(['forum_user_id' => 41]);
    $stranger = User::factory()->create();

    expect(fn () => flarum()->makeDiscussionPrivate('12-my-design', [$designer, $stranger]))
        ->toThrow(ForumException::class, "Could not find or create the forum account of user #{$stranger->id}");

    expect(collect(recorded())->where(0, 'PATCH'))->toBeEmpty();
});

it('does not trust a 200 from Flarum: the discussion is read back after making it private', function () {
    Http::fake([
        FORUM_API.'/users/41' => Http::response(['data' => ['id' => '41']]),
        FORUM_API.'/discussions/12' => Http::response(discussionState(false)),
    ]);

    expect(fn () => flarum()->makeDiscussionPrivate('12-my-design', [User::factory()->create(['forum_user_id' => 41])]))
        ->toThrow(ForumException::class, 'still public');
});

it('makes a private discussion public with the recipients emptied, as Byobu wants it', function () {
    Http::fake([
        FORUM_API.'/discussions/12' => Http::sequence()
            ->push(discussionState(true))
            ->push(discussionState(false)) // the PATCH answer
            ->push(discussionState(false)), // the read back
    ]);

    flarum()->makeDiscussionPublic('12-my-design');

    $patch = collect(recorded())->first(fn ($r) => $r[0] === 'PATCH');

    expect($patch[2]['data']['attributes'])->toBe(['public' => 12])
        ->and($patch[2]['data']['relationships'])->toBe(['recipientUsers' => ['data' => []], 'recipientGroups' => ['data' => []]])
        ->and($patch[3])->toBe('Token master-key-123; userId=1');
});

it('leaves a public discussion alone', function () {
    Http::fake([FORUM_API.'/discussions/12' => Http::response(discussionState(false))]);

    flarum()->makeDiscussionPublic('12-my-design');

    Http::assertSentCount(1);
});

it('switches on the Byobu setting "make public" when the forum has it off', function () {
    Http::fake([
        FORUM_API.'/settings' => Http::response(null, 204),
        FORUM_API.'/discussions/12' => Http::sequence()
            ->push(discussionState(true, canMakePublic: false))
            ->push(discussionState(false))
            ->push(discussionState(false)),
    ]);

    flarum()->makeDiscussionPublic('12-my-design');

    $methods = collect(recorded())->map(fn ($r) => $r[0].' '.parse_url($r[1], PHP_URL_PATH))->all();

    expect($methods)->toBe(['GET /forum/api/discussions/12', 'POST /forum/api/settings', 'PATCH /forum/api/discussions/12', 'GET /forum/api/discussions/12'])
        ->and(collect(recorded())->first(fn ($r) => $r[0] === 'POST')[2])->toBe(['fof-byobu.makePublic' => '1']);
});

it('reports a discussion Flarum does not know, and does nothing while the forum is disabled', function () {
    Http::fake([FORUM_API.'/discussions/12' => Http::response(['errors' => [['status' => '404', 'code' => 'not_found']]], 404)]);

    expect(fn () => flarum()->makeDiscussionPublic('12-gone'))->toThrow(ForumException::class, 'HTTP 404');

    $null = new App\Services\Forum\NullForumClient;
    $null->makeDiscussionPublic('12-x');
    $null->makeDiscussionPrivate('12-x', [User::factory()->create()]);

    Http::assertSentCount(1);
});
