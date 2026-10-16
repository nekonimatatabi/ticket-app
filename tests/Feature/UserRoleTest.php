<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Route;

test('role defaults to customer and casts to the enum', function () {
    $user = User::factory()->create();

    expect($user->fresh()->role)->toBe(UserRole::Customer)
        ->and($user->isCustomer())->toBeTrue()
        ->and($user->isOrganizer())->toBeFalse()
        ->and($user->isAdmin())->toBeFalse();
});

test('factory states set the role', function () {
    expect(User::factory()->organizer()->create()->isOrganizer())->toBeTrue()
        ->and(User::factory()->admin()->create()->isAdmin())->toBeTrue();
});

test('role cannot be mass assigned', function () {
    $user = User::factory()->create();

    $user->fill(['role' => 'admin'])->save();

    expect($user->fresh()->role)->toBe(UserRole::Customer);

    $created = User::create([
        'name' => 'Sneaky',
        'email' => 'sneaky@example.com',
        'password' => 'password',
        'role' => 'admin',
    ]);

    expect($created->fresh()->role)->toBe(UserRole::Customer);
});

test('role labels are in Japanese', function () {
    expect(UserRole::Customer->label())->toBe('参加者')
        ->and(UserRole::Organizer->label())->toBe('主催者')
        ->and(UserRole::Admin->label())->toBe('管理者');
});

describe('role middleware (organizer only)', function () {
    beforeEach(function () {
        Route::middleware(['web', 'auth', 'role:organizer'])
            ->get('/_organizer-only-test', fn () => 'ok');
    });

    test('allows organizers', function () {
        $this->actingAs(User::factory()->organizer()->create())
            ->get('/_organizer-only-test')
            ->assertOk();
    });

    test('rejects customers with 403', function () {
        $this->actingAs(User::factory()->create())
            ->get('/_organizer-only-test')
            ->assertForbidden();
    });

    test('rejects admins when only organizer is permitted', function () {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/_organizer-only-test')
            ->assertForbidden();
    });
});

describe('role middleware', function () {
    beforeEach(function () {
        Route::middleware(['web', 'auth', 'role:organizer,admin'])
            ->get('/_role-test', fn () => 'ok');
    });

    test('allows users with a permitted role', function (string $state) {
        $this->actingAs(User::factory()->{$state}()->create())
            ->get('/_role-test')
            ->assertOk();
    })->with(['organizer', 'admin']);

    test('rejects users with another role', function () {
        $this->actingAs(User::factory()->create())
            ->get('/_role-test')
            ->assertForbidden();
    });

    test('rejects guests', function () {
        $this->get('/_role-test')->assertRedirect(route('login'));
    });
});
