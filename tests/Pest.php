<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Marketplace helpers
|--------------------------------------------------------------------------
*/

/** A user whose Stripe account can take charges and receive payouts. */
function onboardedSeller(): App\Models\User
{
    return App\Models\User::factory()->onboardedSeller()->create();
}

/** A paid, active design with a snapshot (and its ZIP on the private disk when $withFile), by $seller. */
function paidDesign(?App\Models\User $seller = null, int $cents = 1500, bool $withFile = true): App\Models\Design
{
    return App\Models\Design::factory()
        ->for($seller ?? onboardedSeller(), 'designer')
        ->paid($cents)
        ->withSnapshot('v1.0', $withFile)
        ->create();
}

/** Open a Folio/Volt dashboard page as $user and return its Livewire test component. */
function dashboardPage(Tests\TestCase $test, App\Models\User $user, string $url): Livewire\Features\SupportTesting\Testable
{
    $html = $test->actingAs($user)->get($url)->assertOk()->getContent();
    preg_match('/wire:snapshot="([^"]+)"/', $html, $matches);
    $snapshot = json_decode(html_entity_decode($matches[1]), true);

    return Livewire\Livewire::test($snapshot['memo']['name']);
}
