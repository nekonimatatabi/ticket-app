<?php

use App\Enums\UserRole;
use App\Models\User;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk()
        ->assertSee('参加者')
        ->assertSee('主催者');
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role' => 'customer',
    ]);

    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
    expect(User::where('email', 'test@example.com')->first()->role)->toBe(UserRole::Customer);
});

test('users can register as an organizer', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Organizer',
        'email' => 'organizer@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role' => 'organizer',
    ]);

    $response->assertSessionHasNoErrors();

    $this->assertAuthenticated();
    expect(User::where('email', 'organizer@example.com')->first()->isOrganizer())->toBeTrue();
});

test('users cannot register as an admin', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Sneaky',
        'email' => 'sneaky@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role' => 'admin',
    ]);

    $response->assertSessionHasErrors('role');

    $this->assertGuest();
    expect(User::where('email', 'sneaky@example.com')->exists())->toBeFalse();
});

test('users cannot register with an unknown role', function (string $role) {
    $response = $this->post(route('register.store'), [
        'name' => 'Unknown Role',
        'email' => 'unknown@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role' => $role,
    ]);

    $response->assertSessionHasErrors('role');

    $this->assertGuest();
    expect(User::where('email', 'unknown@example.com')->exists())->toBeFalse();
})->with(['foo', 'Organizer', 'ORGANIZER', '0']);

test('role is required to register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'No Role',
        'email' => 'norole@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasErrors('role');

    $this->assertGuest();
});
