<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * The data every installation needs: roles, categories, settings and the active theme.
 *
 * Safe to run any number of times, in any environment, including production: it only inserts rows
 * that are missing (matched by their natural key) and never updates, deletes or truncates anything,
 * so values an admin has changed since stay as they are. It creates no users.
 */
class BaseDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->roles();
        $this->categories();
        $this->settings();
        $this->themes();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function roles(): void
    {
        $roles = [
            'admin' => 'The admin user has full access to all features including the ability to access the admin panel.',
            'registered' => 'This is the default user role. If a user has this role they have created an account; however, they have are not a subscriber.',
            'manufacturer' => 'Assigned only to speaker manufacturers to manage official driverdb and design entries.',
        ];

        foreach ($roles as $name => $description) {
            $this->insertMissing('roles', ['name' => $name, 'guard_name' => 'web'], ['description' => $description]);
        }
    }

    private function categories(): void
    {
        foreach ([['Site News', 'site-news'], ['Tutorials', 'tutorials']] as [$name, $slug]) {
            $this->insertMissing('categories', ['slug' => $slug], ['name' => $name, 'order' => 1, 'parent_id' => null]);
        }
    }

    private function settings(): void
    {
        $settings = [
            ['site.title', 'Site Title', 'SDLabs.cc', 1],
            ['site.description', 'Site Description', 'The Speaker Design Marketplace', 2],
            ['site.google_analytics_tracking_id', 'Google Analytics Tracking ID', null, 4],
        ];

        foreach ($settings as [$key, $displayName, $value, $order]) {
            $this->insertMissing('settings', ['key' => $key], [
                'display_name' => $displayName,
                'value' => $value,
                'details' => '',
                'type' => 'text',
                'order' => $order,
                'group' => 'Site',
            ]);
        }
    }

    private function themes(): void
    {
        // Only the very first run activates the theme; an admin's later choice is never touched.
        $this->insertMissing('themes', ['folder' => 'blank'], [
            'name' => 'blank',
            'active' => DB::table('themes')->where('active', 1)->exists() ? 0 : 1,
            'version' => '1.0',
        ]);
    }

    /**
     * Insert a row unless one with these identifying columns exists. Existing rows are left alone.
     *
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $values
     */
    private function insertMissing(string $table, array $identity, array $values): void
    {
        if (DB::table($table)->where($identity)->exists()) {
            return;
        }

        DB::table($table)->insert($identity + $values + ['created_at' => now(), 'updated_at' => now()]);
    }
}
