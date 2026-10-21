<?php

use App\Models\Design;
use App\Models\Driver;
use App\Models\Listing;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeForumClient;

/*
| R1.2: `forum:seed-demo` fills a demo forum. The test database holds the demo dataset (tests/bootstrap.php), so
| these tests look at the dataset as a whole; the FakeForumClient records what the command asked the forum to do.
*/

beforeEach(function () {
    config(['forum.throttle_wait' => 0]);
});

/** @return array<int, \Illuminate\Database\Eloquent\Model> every item that is public and gets a thread */
function publicForumSubjects(): array
{
    return array_merge(
        Design::where('active', true)->get()->all(),
        Driver::where('active', true)->get()->all(),
        Listing::where('status', 'active')->get()->all(),
    );
}

it('refuses to run outside the local and demo environments', function () {
    $forum = FakeForumClient::install();
    $this->app['env'] = 'production';

    $this->artisan('forum:seed-demo')
        ->expectsOutputToContain('only runs in the local and demo environments')
        ->assertFailed();

    expect($forum->discussions)->toBeEmpty()->and($forum->posts)->toBeEmpty()->and($forum->tags)->toBe([]);

    foreach (['staging', 'production'] as $env) {
        $this->app['env'] = $env;
        $this->artisan('forum:seed-demo')->assertFailed();
    }

    foreach (['local', 'demo'] as $env) {
        $this->app['env'] = $env;
        $this->artisan('forum:seed-demo')->assertSuccessful();
    }
});

it('needs an enabled, reachable forum', function () {
    $this->artisan('forum:seed-demo')->expectsOutputToContain('The forum is disabled')->assertFailed();

    $forum = FakeForumClient::install();
    $forum->reachable = false;
    $this->artisan('forum:seed-demo')->expectsOutputToContain('connection refused')->assertFailed();

    $forum->reachable = true;
    $forum->authorized = false;
    $this->artisan('forum:seed-demo')->assertFailed();

    expect($forum->discussions)->toBeEmpty()->and($forum->tags)->toBe([]);
});

it('creates the missing tags, then a thread for every public design, driver and listing', function () {
    // created before the forum is connected, like content that was published earlier
    $draftDesign = Design::factory()->draft()->create();
    $draftDriver = Driver::factory()->draft()->create();
    $draftListing = Listing::factory()->draft()->create();
    $storeListing = Listing::factory()->inStore()->create(); // store listings get a thread too

    $forum = FakeForumClient::install();
    $forum->tags = ['designs' => 11]; // the other two are missing

    $this->artisan('forum:seed-demo')->expectsOutputToContain('Forum demo content is ready')->assertSuccessful();

    expect($forum->tags)->toHaveKeys(['designs', 'drivers', 'marketplace']);

    $subjects = publicForumSubjects();
    expect($subjects)->not->toBeEmpty()->and($forum->discussions)->toHaveCount(count($subjects));

    foreach ($subjects as $subject) {
        expect($subject->fresh()->forum_slug)->not->toBeEmpty();
    }

    // each thread is written by its owner, in the tag of its kind
    $tags = collect($forum->discussions)->countBy('tag');
    expect($tags['designs'])->toBe(Design::where('active', true)->count())
        ->and($tags['drivers'])->toBe(Driver::where('active', true)->count())
        ->and($tags['marketplace'])->toBe(Listing::where('status', 'active')->count());

    expect($storeListing->fresh()->forum_slug)->not->toBeNull()
        ->and($draftDesign->fresh()->forum_slug)->toBeNull()
        ->and($draftDriver->fresh()->forum_slug)->toBeNull()
        ->and($draftListing->fresh()->forum_slug)->toBeNull();
});

it('adds 2 to 4 replies per thread from other demo users, and the seller answers questions on listings', function () {
    $forum = FakeForumClient::install();

    $this->artisan('forum:seed-demo')->assertSuccessful();

    $bySlug = collect($forum->posts)->groupBy('discussion');
    $subjects = publicForumSubjects();
    expect($bySlug)->toHaveCount(count($subjects));

    $demoIds = User::where('email', 'like', '%@sdlabs.test')->pluck('id')->all();

    foreach ($subjects as $subject) {
        $subject = $subject->fresh();
        $posts = $bySlug[$subject->forum_slug];
        $owner = match (true) {
            $subject instanceof Design => $subject->user_id,
            $subject instanceof Driver => $subject->user_id,
            default => $subject->seller_id,
        };

        expect($posts->count())->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(4)
            ->and($posts->pluck('author')->every(fn ($id) => in_array($id, $demoIds, true)))->toBeTrue()
            ->and($posts->pluck('content')->every(fn ($text) => filled($text)))->toBeTrue()
            ->and($posts->pluck('content')->unique()->count())->toBe($posts->count());

        if ($subject instanceof Listing) {
            // buyer question, seller answer, buyer question, seller answer (cut at the reply count)
            foreach ($posts->values() as $i => $post) {
                $i % 2 === 1 ? expect($post['author'])->toBe($owner) : expect($post['author'])->not->toBe($owner);
            }
        } else {
            expect($posts->pluck('author')->contains($owner))->toBeFalse();
        }
    }
});

it('is idempotent: a second run adds nothing', function () {
    $forum = FakeForumClient::install();

    $this->artisan('forum:seed-demo')->assertSuccessful();

    $discussions = count($forum->discussions);
    $posts = count($forum->posts);
    expect($discussions)->toBeGreaterThan(30)->and($posts)->toBeGreaterThan($discussions);

    $this->artisan('forum:seed-demo')->expectsOutputToContain('Nothing to do')->assertSuccessful();

    expect($forum->discussions)->toHaveCount($discussions)->and($forum->posts)->toHaveCount($posts);
});

it('skips a thread that already has replies and one that already exists', function () {
    $forum = FakeForumClient::install();

    $busy = Design::factory()->create(['forum_slug' => '900-busy-thread']);
    $quiet = Design::factory()->create(['forum_slug' => '901-quiet-thread']);
    $forum->existingReplies['900-busy-thread'] = 6;

    $this->artisan('forum:seed-demo')->assertSuccessful();

    $targets = collect($forum->posts)->pluck('discussion')->unique();
    expect($targets)->not->toContain('900-busy-thread')
        ->and($targets)->toContain('901-quiet-thread')
        ->and(collect($forum->discussions)->pluck('title')->all())->not->toContain($busy->name, $quiet->name)
        ->and($busy->fresh()->forum_slug)->toBe('900-busy-thread');
});

it('picks the same replies for the same items every time', function () {
    $first = FakeForumClient::install();
    $this->artisan('forum:seed-demo')->assertSuccessful();

    Design::query()->update(['forum_slug' => null]);
    Driver::query()->update(['forum_slug' => null]);
    Listing::query()->update(['forum_slug' => null]);

    $second = FakeForumClient::install();
    $this->artisan('forum:seed-demo')->assertSuccessful();

    expect($second->discussions)->toBe($first->discussions)
        ->and($second->posts)->toBe($first->posts);
});

it('carries on when the forum says slow down', function () {
    $forum = FakeForumClient::install();
    $forum->throttle = 6;

    $this->artisan('forum:seed-demo')->expectsOutputToContain('Forum demo content is ready')->assertSuccessful();

    expect($forum->throttle)->toBe(0)
        ->and($forum->discussions)->toHaveCount(count(publicForumSubjects()))
        ->and(collect($forum->posts)->pluck('discussion')->unique())->toHaveCount(count(publicForumSubjects()));
});

it('reports the steps that keep failing and succeeds on the next run', function () {
    $forum = FakeForumClient::install();
    $forum->throttle = 100000; // never lets anything through

    $this->artisan('forum:seed-demo')->expectsOutputToContain('step(s) failed')->assertFailed();
    expect($forum->discussions)->toBeEmpty();

    $forum->throttle = 0;
    $this->artisan('forum:seed-demo')->assertSuccessful();
    expect($forum->discussions)->toHaveCount(count(publicForumSubjects()));
});

// --- DemoSeeder -----------------------------------------------------------------------------------

it('tells how to fill the forum later when the forum is off or not reachable', function () {
    $this->artisan('db:seed', ['--class' => DemoSeeder::class])
        ->expectsOutputToContain('Run `php artisan forum:setup && php artisan forum:seed-demo` once the forum is up.')
        ->assertSuccessful();

    $forum = FakeForumClient::install();
    $forum->reachable = false;
    config(['forum.enabled' => true]);

    $this->artisan('db:seed', ['--class' => DemoSeeder::class])
        ->expectsOutputToContain('Run `php artisan forum:setup && php artisan forum:seed-demo` once the forum is up.')
        ->assertSuccessful();

    expect($forum->discussions)->toBeEmpty();
});

it('ends the demo seeding with the forum content when the forum is up', function () {
    $forum = FakeForumClient::install();
    config(['forum.enabled' => true]);

    $this->artisan('db:seed', ['--class' => DemoSeeder::class])->assertSuccessful();

    expect($forum->discussions)->toHaveCount(count(publicForumSubjects()))
        ->and($forum->posts)->not->toBeEmpty();
});

// --- with the real Flarum client against a pretend Flarum --------------------------------------------------

it('seeds a forum through the real client: authors act as themselves, throttled posts are retried, a second run posts nothing', function () {
    config([
        'forum.enabled' => true,
        'forum.url' => 'https://shop.test/forum',
        'forum.internal_url' => 'http://flarum.test:8000/forum',
        'forum.api_key' => 'master-key-123',
        'forum.admin_user_id' => 1,
    ]);
    Illuminate\Support\Facades\Cache::flush();

    // A tiny stateful Flarum: tags, users (id derived from the e-mail), discussions, posts, and a 429 now and then.
    $flarum = new class {
        public int $next = 100;
        public int $writes = 0;
        public int $throttled = 0;
        /** @var array<int, array{id: int, author: int, title: string}> */
        public array $discussions = [];
        /** @var array<int, array{id: int, discussion: int, author: int, content: string}> */
        public array $posts = [];
    };

    Http::fake(function (Illuminate\Http\Client\Request $request) use ($flarum) {
        $route = substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/forum/api'));
        $method = $request->method();
        $actor = (int) (preg_match('/userId=(\d+)/', $request->header('Authorization')[0] ?? '', $m) ? $m[1] : 0);
        $json = fn (array $body, int $status = 200) => Http::response($body, $status);

        if ($method === 'POST' && in_array($route, ['/discussions', '/posts'], true) && ++$flarum->writes % 7 === 0) {
            $flarum->throttled++;

            return $json(['errors' => [['status' => '429', 'code' => 'too_many_requests']]], 429);
        }

        return match (true) {
            $route === '' => $json(['data' => ['attributes' => ['title' => 'Pretend Forum', 'version' => '1.8.0']]]),
            $route === '/users/1' => $json(['data' => ['attributes' => ['email' => 'forumadmin@forum.test']]]),
            $route === '/tags' => $json(['data' => collect(['designs', 'drivers', 'marketplace'])->map(fn ($slug, $i) => [
                'id' => (string) ($i + 1), 'attributes' => ['slug' => $slug, 'position' => $i, 'isChild' => false],
            ])->all()]),
            $route === '/users' && $method === 'GET' => (function () use ($request, $json) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return $json(['data' => [['id' => (string) (1000 + abs(crc32($query['filter']['email'] ?? '')) % 90000)]]]);
            })(),
            $route === '/discussions' && $method === 'POST' => (function () use ($request, $flarum, $actor, $json) {
                $id = $flarum->next++;
                $title = (string) $request['data']['attributes']['title'];
                $flarum->discussions[$id] = ['id' => $id, 'author' => $actor, 'title' => $title];

                return $json(['data' => ['id' => (string) $id, 'attributes' => ['slug' => $id.'-'.Illuminate\Support\Str::slug($title)]]], 201);
            })(),
            $route === '/posts' && $method === 'POST' => (function () use ($request, $flarum, $actor, $json) {
                $id = $flarum->next++;
                $flarum->posts[$id] = [
                    'id' => $id,
                    'discussion' => (int) $request['data']['relationships']['discussion']['data']['id'],
                    'author' => $actor,
                    'content' => (string) $request['data']['attributes']['content'],
                ];

                return $json(['data' => ['type' => 'posts', 'id' => (string) $id]], 201);
            })(),
            (bool) preg_match('#^/discussions/(\d+)$#', $route, $m) => (function () use ($flarum, $m, $json) {
                $id = (int) $m[1];

                return isset($flarum->discussions[$id])
                    ? $json(['data' => ['id' => (string) $id, 'attributes' => ['commentCount' => 1 + count(array_filter($flarum->posts, fn ($p) => $p['discussion'] === $id))]]])
                    : $json(['errors' => []], 404);
            })(),
            default => $json(['errors' => [['detail' => "unexpected {$method} {$route}"]]], 500),
        };
    });

    $this->artisan('forum:seed-demo')->expectsOutputToContain('Forum demo content is ready')->assertSuccessful();

    $subjects = publicForumSubjects();
    expect($flarum->throttled)->toBeGreaterThan(5)
        ->and($flarum->discussions)->toHaveCount(count($subjects));

    $repliesByThread = collect($flarum->posts)->groupBy('discussion');
    expect($repliesByThread)->toHaveCount(count($subjects));

    foreach ($subjects as $subject) {
        $subject = $subject->fresh();
        [$id] = explode('-', $subject->forum_slug);
        $posts = $repliesByThread[(int) $id];

        expect($posts->count())->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(4);

        // a design or driver thread is answered by other members than the one who started it
        if (! $subject instanceof Listing) {
            expect($posts->pluck('author')->contains($flarum->discussions[(int) $id]['author']))->toBeFalse();
        }
    }

    // every member wrote as their own Flarum account (the id the pretend forum derived from the e-mail)
    $authors = collect($flarum->posts)->pluck('author')->unique();
    expect($authors->count())->toBeGreaterThan(5)->and($authors->every(fn ($id) => $id >= 1000))->toBeTrue();

    // a second run only reads
    $posted = count($flarum->posts) + count($flarum->discussions);
    $this->artisan('forum:seed-demo')->expectsOutputToContain('Nothing to do')->assertSuccessful();
    expect(count($flarum->posts) + count($flarum->discussions))->toBe($posted);
});
