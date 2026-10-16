<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('users are redirected to the page for their role', function (?string $state, string $path) {
    $factory = User::factory();
    $user = ($state ? $factory->{$state}() : $factory)->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect($path);
})->with([
    'admin' => ['admin', '/admin/events'],
    'organizer' => ['organizer', '/organizer/events'],
    'customer' => [null, '/orders'],
]);
