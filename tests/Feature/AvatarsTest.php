<?php

use App\Jobs\SyncForumAvatar;
use App\Models\User;
use App\Support\Avatars;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeForumClient;

beforeEach(function () {
    Storage::fake('public');
});

it('serves the bundled default avatar to users without a picture, with a content hash for caching', function () {
    foreach (['', null, 'demo/default.png', 'avatar.png'] as $value) {
        expect(Avatars::url($value))->toMatch('#/images/avatar-default\.png\?v=[0-9a-f]{8}$#');
    }

    expect(Avatars::url('avatars/ada-x1.jpg'))->toEndWith('/storage/avatars/ada-x1.jpg')
        ->and((new User(['avatar' => 'avatars/ada-x1.jpg']))->avatar())->toEndWith('/storage/avatars/ada-x1.jpg');
});

it('stores an upload as a square 400 pixel JPEG under a new name and deletes the previous one', function () {
    $user = User::factory()->create(['username' => 'ada']);

    $big = UploadedFile::fake()->image('me.png', 1200, 800);
    $small = UploadedFile::fake()->image('me2.png', 300, 300);
    $first = Avatars::store($user, $big->getRealPath());
    $second = Avatars::store($user, $small->getRealPath());

    expect($first)->toMatch('#^avatars/ada-[a-z0-9]{8}\.jpg$#')
        ->and($second)->not->toBe($first)
        ->and($user->fresh()->avatar)->toBe($second);

    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);
    expect(getimagesizefromstring(Storage::disk('public')->get($second)))->toMatchArray([0 => 400, 1 => 400, 'mime' => 'image/jpeg']);
});

it('does not delete pictures it did not upload, like the demo default', function () {
    Storage::disk('public')->put('demo/default.png', 'x');
    $user = User::factory()->create(['avatar' => 'demo/default.png']);

    $picture = UploadedFile::fake()->image('me.png');
    Avatars::store($user, $picture->getRealPath());

    Storage::disk('public')->assertExists('demo/default.png');
});

it('sends the new avatar to the forum when it changes, and the default one to new forum accounts', function () {
    $forum = FakeForumClient::install();
    $user = User::factory()->create();

    $user->update(['name' => 'Only the name']);
    expect($forum->avatarSyncs)->toBe([]);

    $picture = UploadedFile::fake()->image('me.png');
    Avatars::store($user, $picture->getRealPath());
    expect($forum->avatarSyncs)->toBe([$user->id]);

    app(\App\Listeners\UserCreated::class)->handle(new Registered($user));
    expect($forum->avatarSyncs)->toBe([$user->id, $user->id]);
});

it('queues every user for the forum with forum:sync-avatars', function () {
    FakeForumClient::install();
    \Illuminate\Support\Facades\Queue::fake();
    $users = User::factory()->count(2)->create();

    $this->artisan('forum:sync-avatars')->assertSuccessful();

    \Illuminate\Support\Facades\Queue::assertPushed(SyncForumAvatar::class, User::count());
    \Illuminate\Support\Facades\Queue::assertPushed(SyncForumAvatar::class, fn ($job) => $job->user->is($users[1]));
});

it('shows chat avatars from the same place as the site', function () {
    expect(app('ChatifyMessenger')->getUserAvatarUrl('avatars/ada-x1.jpg'))->toEndWith('/storage/avatars/ada-x1.jpg')
        ->and(app('ChatifyMessenger')->getUserAvatarUrl('avatar.png'))->toContain('/images/avatar-default.png');
});

it('makes a picture uploaded in the chat settings the site avatar', function () {
    $user = User::factory()->create(['avatar' => 'demo/default.png']);

    $this->actingAs($user)
        ->post(route('avatar.update'), ['avatar' => UploadedFile::fake()->image('chat.png', 500, 500)])
        ->assertOk()
        ->assertJson(['status' => 1, 'error' => 0]);

    expect($user->fresh()->avatar)->toMatch('#^avatars/.+\.jpg$#');
    Storage::disk('public')->assertExists($user->fresh()->avatar);
});

it('no longer reloads the header avatar on every page with a timestamp', function () {
    $this->actingAs(User::factory()->create())->get('/dashboard/designs')
        ->assertOk()
        ->assertDontSee("+ '?' + new Date().getTime()", false);
});
