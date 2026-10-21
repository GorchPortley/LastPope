<?php

use App\Enums\EntitlementSource;
use App\Models\Design;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('private');
    Storage::fake('public');
});

function designSnapshotOf(Design $design)
{
    return $design->snapshots()->firstOrFail();
}

it('uses the documented routes', function () {
    $snapshot = designSnapshotOf(paidDesign());

    expect(route('design-snapshots.download', $snapshot, false))->toBe('/downloads/design-snapshots/'.$snapshot->id)
        ->and(route('driver-snapshots.download', 5, false))->toBe('/downloads/driver-snapshots/5');
});

it('sends guests to the login page', function () {
    $snapshot = designSnapshotOf(paidDesign());

    $this->get(route('design-snapshots.download', $snapshot))->assertRedirect();
});

it('forbids a stranger from downloading a paid design', function () {
    $snapshot = designSnapshotOf(paidDesign());

    $this->actingAs(User::factory()->create())
        ->get(route('design-snapshots.download', $snapshot))
        ->assertForbidden();
});

it('streams the right file to the owner, an admin and an entitled buyer', function () {
    $owner = onboardedSeller();
    $design = paidDesign($owner);
    $snapshot = designSnapshotOf($design);
    $buyer = User::factory()->create();
    $buyer->grantEntitlement($design, EntitlementSource::PURCHASE);

    foreach ([$owner, User::factory()->admin()->create(), $buyer] as $user) {
        $response = $this->actingAs($user)->get(route('design-snapshots.download', $snapshot));

        $response->assertOk();
        expect($response->headers->get('content-disposition'))->toContain($snapshot->snapshot_name)
            ->and($response->headers->get('content-type'))->toContain('zip')
            ->and($response->streamedContent())->toBe(Storage::disk('private')->get($snapshot->download_path));
    }
});

it('forbids a buyer whose entitlement was revoked', function () {
    $design = paidDesign();
    $buyer = User::factory()->create();
    $buyer->grantEntitlement($design)->revoke();

    $this->actingAs($buyer)->get(route('design-snapshots.download', designSnapshotOf($design)))->assertForbidden();
});

it('lets any signed-in user download a free design and records a free entitlement', function () {
    $design = Design::factory()->withSnapshot('v1.0', true)->create();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('design-snapshots.download', designSnapshotOf($design)))->assertOk();

    expect($user->entitlementFor($design)?->source)->toBe(EntitlementSource::FREE);
});

it('answers 404 when the file is gone', function () {
    $design = paidDesign(withFile: false); // snapshot row, no ZIP on the disk
    $this->actingAs($design->designer)->get(route('design-snapshots.download', designSnapshotOf($design)))->assertNotFound();
});

it('lets anybody download the snapshots of an active driver, with no login', function () {
    $driver = Driver::factory()->withSnapshot('v1.0', true)->create();
    $snapshot = $driver->snapshots()->first();
    $stranger = User::factory()->create();
    $expected = Storage::disk('private')->get($snapshot->download_path);

    $guest = $this->get(route('driver-snapshots.download', $snapshot))->assertOk();
    expect($guest->streamedContent())->toBe($expected)
        ->and($guest->headers->get('Content-Type'))->toBe('application/zip');

    expect($this->actingAs($stranger)->get(route('driver-snapshots.download', $snapshot))->assertOk()->streamedContent())->toBe($expected);

    // nothing is granted or recorded: the data is simply free
    expect($stranger->entitlements()->count())->toBe(0);
});

it('keeps the snapshots of an inactive driver for its owner and admins', function () {
    $owner = User::factory()->create();
    $driver = Driver::factory()->for($owner, 'creator')->draft()->withSnapshot('v1.0', true)->create();
    $snapshot = $driver->snapshots()->first();
    $url = route('driver-snapshots.download', $snapshot);

    // everybody else is told it is not there
    $this->get($url)->assertNotFound();
    $this->actingAs(User::factory()->create())->get($url)->assertNotFound();

    $this->actingAs($owner)->get($url)->assertOk();
    $this->actingAs(User::factory()->admin()->create())->get($url)->assertOk();

    // publishing it opens the download to the world
    $driver->update(['active' => true]);
    auth()->logout();
    $this->get($url)->assertOk();
});

it('still needs a login for design snapshots, and answers 404 when a public driver file is gone', function () {
    $design = paidDesign();
    $this->get(route('design-snapshots.download', designSnapshotOf($design)))->assertRedirect();

    $driver = Driver::factory()->withSnapshot('v1.0', false)->create(); // snapshot row, no ZIP on the disk
    $this->get(route('driver-snapshots.download', $driver->snapshots()->first()))->assertNotFound();
});

it('does not expose private files under /storage', function () {
    $design = paidDesign();
    $snapshot = designSnapshotOf($design);

    expect(Storage::disk('private')->exists($snapshot->download_path))->toBeTrue();

    $this->get('/storage/'.$snapshot->download_path)->assertNotFound();
    $this->actingAs($design->designer)->get('/storage/'.$snapshot->download_path)->assertNotFound();
});

it('keeps the private disk outside of everything that is served', function () {
    // the real config (Storage::fake only swaps the disk root inside a test)
    $config = require config_path('filesystems.php');
    $private = $config['disks']['private'];

    expect($private['driver'])->toBe('local')
        ->and($private['visibility'])->toBe('private')
        ->and($private)->not->toHaveKey('url')
        ->and(realpath(dirname($private['root'])) ?: dirname($private['root']))->not->toBe(public_path())
        ->and(str_starts_with($private['root'], $config['disks']['public']['root']))->toBeFalse()
        ->and($config['links'])->not->toHaveKey($private['root'])
        ->and(array_values($config['links']))->not->toContain($private['root']);
});

it('has no public route to the old model download methods', function () {
    $uris = collect(app('router')->getRoutes()->getRoutes())->map->uri()->all();

    expect($uris)->not->toContain('design-snapshots/{snapshot}/download')
        ->and($uris)->not->toContain('driver-snapshots/{snapshot}/download')
        ->and($uris)->toContain('downloads/design-snapshots/{snapshot}')
        ->and($uris)->toContain('downloads/driver-snapshots/{snapshot}');
});
