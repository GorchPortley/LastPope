<?php

use Database\Factories\UserFactory;

use function Pest\Laravel\get;

it('responds with 200 for all public routes', function (string $route) {
    get($route)->assertStatus(200);
})->with('routes');

test('guests are redirected away from the dashboard', function () {
    get('/dashboard')->assertRedirect();
});

test('responds with 200 for all auth routes', function (string $url) {
    $user = UserFactory::new()->create();

    $this->actingAs($user);

    $this->get($url)->assertStatus(200);
})->with('authroutes');

test('shows the profile page of a user', function () {
    $user = UserFactory::new()->create();

    $this->get('/profile/'.$user->username)->assertStatus(200);
});

test('responds with 200 for all admin routes', function (string $url) {
    $user = UserFactory::new()->create();
    $user->syncRoles('admin');

    $this->actingAs($user);

    $this->get($url)->assertStatus(200);
})->with('adminroutes');

test('regular users cannot open the admin panel', function () {
    $this->actingAs(UserFactory::new()->create());

    $this->get('/admin')->assertForbidden();
});
