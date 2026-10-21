<?php

use App\Models\Design;
use App\Models\User;
use Database\Seeders\BaseDataSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
| `php artisan db:seed --force` is run on the production server after every update. It must
| never delete or overwrite anything there, and must not create demo users.
*/

it('never deletes data when db:seed runs in production, however often', function () {
    $this->app['env'] = 'production';

    DB::table('settings')->where('key', 'site.title')->update(['value' => 'My Own Title']);
    DB::table('categories')->where('slug', 'tutorials')->update(['name' => 'Renamed by an admin']);
    DB::table('themes')->where('folder', 'blank')->update(['active' => 0]); // an admin deactivated it

    $before = [
        'users' => User::count(),
        'designs' => Design::count(),
        'roles' => DB::table('roles')->count(),
        'model_has_roles' => DB::table('model_has_roles')->count(),
        'settings' => DB::table('settings')->count(),
        'themes' => DB::table('themes')->count(),
        'categories' => DB::table('categories')->count(),
        'changelogs' => DB::table('changelogs')->count(),
    ];

    Artisan::call('db:seed', ['--force' => true]);
    Artisan::call('db:seed', ['--force' => true]);

    $after = [
        'users' => User::count(),
        'designs' => Design::count(),
        'roles' => DB::table('roles')->count(),
        'model_has_roles' => DB::table('model_has_roles')->count(),
        'settings' => DB::table('settings')->count(),
        'themes' => DB::table('themes')->count(),
        'categories' => DB::table('categories')->count(),
        'changelogs' => DB::table('changelogs')->count(),
    ];

    expect($after)->toBe($before)
        ->and(DB::table('roles')->pluck('name')->sort()->values()->all())->toBe(['admin', 'manufacturer', 'registered'])
        // nothing an admin changed was reset
        ->and(DB::table('settings')->where('key', 'site.title')->value('value'))->toBe('My Own Title')
        ->and(DB::table('categories')->where('slug', 'tutorials')->value('name'))->toBe('Renamed by an admin')
        ->and((int) DB::table('themes')->where('folder', 'blank')->value('active'))->toBe(0)
        // and the old placeholder admin of the Wave seeders is gone for good
        ->and(User::where('email', 'admin@sdlabs.cc')->exists())->toBeFalse();
});

it('does not seed demo users in production even on an empty database', function () {
    $this->app['env'] = 'production';
    DB::table('model_has_roles')->delete();
    Schema::disableForeignKeyConstraints();
    foreach (['orders', 'seller_orders', 'order_items', 'entitlements', 'cart_items', 'carts', 'addresses', 'listings', 'design_driver', 'design_snapshots', 'driver_snapshots', 'designs', 'drivers', 'seller_profiles', 'reviews', 'users'] as $table) {
        DB::table($table)->delete();
    }
    Schema::enableForeignKeyConstraints();

    Artisan::call('db:seed', ['--force' => true]);

    expect(User::count())->toBe(0)
        ->and(DB::table('roles')->count())->toBe(3);
});

it('creates the base data that is missing, without touching what exists or creating users', function () {
    $users = User::count();
    $role = DB::table('roles')->where('name', 'manufacturer')->first();

    DB::table('model_has_roles')->where('role_id', $role->id)->delete();
    DB::table('roles')->where('name', 'manufacturer')->delete();
    DB::table('settings')->where('key', 'site.description')->delete();
    DB::table('categories')->where('slug', 'site-news')->delete();
    DB::table('themes')->delete();

    (new BaseDataSeeder())->run();
    (new BaseDataSeeder())->run();

    expect(DB::table('roles')->where('name', 'manufacturer')->count())->toBe(1)
        ->and(DB::table('settings')->where('key', 'site.description')->value('value'))->toBe('The Speaker Design Marketplace')
        ->and(DB::table('categories')->where('slug', 'site-news')->count())->toBe(1)
        ->and(DB::table('themes')->count())->toBe(1)
        ->and((int) DB::table('themes')->value('active'))->toBe(1)
        ->and(User::count())->toBe($users);
});

it('keeps the legacy placeholder admin out of every environment', function () {
    expect(User::where('email', 'admin@sdlabs.cc')->exists())->toBeFalse()
        ->and(User::where('email', 'admin@sdlabs.test')->first()->hasRole('admin'))->toBeTrue();
});

it('has a nullable forum_user_id column on users', function () {
    $user = User::factory()->create();

    expect(Schema::hasColumn('users', 'forum_user_id'))->toBeTrue()
        ->and($user->refresh()->forum_user_id)->toBeNull();

    $user->forum_user_id = 42;
    $user->save();

    expect($user->refresh()->forum_user_id)->toBe(42);
});
