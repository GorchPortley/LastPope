<?php

use App\Models\User;
use App\Services\Forum\FlarumClient;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeForumClient;

it('sets the flarum_remember cookie for the /forum path after a web login', function () {
    $forum = FakeForumClient::install();
    $user = User::factory()->create();

    event(new Login('web', $user, false));

    $cookie = Cookie::queued('flarum_remember', null, '/forum');

    expect($forum->tokenRequests)->toBe([$user->id])
        ->and($cookie)->not->toBeNull()
        ->and($cookie->getValue())->toBe('remember-token-123')
        ->and($cookie->getPath())->toBe('/forum')
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax')
        ->and($cookie->getExpiresTime())->toBeGreaterThan(time() + 13 * 86400);
});

it('marks the cookie secure when the request came in over https', function () {
    FakeForumClient::install();
    $user = User::factory()->create();

    app('request')->server->set('HTTPS', 'on');
    event(new Login('web', $user, false));

    expect(Cookie::queued('flarum_remember', null, '/forum')->isSecure())->toBeTrue();
});

it('sets no cookie while the forum is disabled', function () {
    $forum = FakeForumClient::install(enabled: false);

    event(new Login('web', User::factory()->create(), false));

    expect(Cookie::getQueuedCookies())->toBeEmpty()->and($forum->tokenRequests)->toBeEmpty();
});

it('sets no cookie when the forum cannot issue a token (forum down)', function () {
    $forum = FakeForumClient::install();
    $forum->token = null;

    event(new Login('web', User::factory()->create(), false));

    expect(Cookie::getQueuedCookies())->toBeEmpty();
});

it('ignores logins on other guards, such as the JWT API', function () {
    $forum = FakeForumClient::install();

    event(new Login('api', User::factory()->create(), false));

    expect($forum->tokenRequests)->toBeEmpty();
});

it('clears both forum cookies on logout', function () {
    FakeForumClient::install();

    event(new Logout('web', User::factory()->create()));

    foreach (['flarum_remember', 'flarum_session'] as $name) {
        $cookie = Cookie::queued($name, null, '/forum');

        expect($cookie)->not->toBeNull()
            ->and($cookie->getExpiresTime())->toBeLessThan(time());
    }
});

it('creates the forum account when a member registers', function () {
    $forum = FakeForumClient::install();
    $user = User::factory()->create();

    event(new Registered($user));

    expect($forum->ensuredUsers)->toBe([$user->id]);
});

it('leaves registrations alone while the forum is disabled', function () {
    $forum = FakeForumClient::install(enabled: false);

    event(new Registered(User::factory()->create()));

    expect($forum->ensuredUsers)->toBeEmpty();
});

it('never calls Flarum over HTTP in the default test setup', function () {
    Http::fake();

    $user = User::factory()->create();
    event(new Registered($user));
    event(new Login('web', $user, false));

    Http::assertNothingSent();
    expect(app(\App\Services\Forum\ForumClient::class))->not->toBeInstanceOf(FlarumClient::class);
});
