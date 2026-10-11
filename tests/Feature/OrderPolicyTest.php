<?php

use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('the buyer, the organizer of the event, and admins can view an order', function () {
    $organizer = User::factory()->organizer()->create();
    $buyer = User::factory()->create();
    $order = Order::factory()->for($buyer)->for(Event::factory()->for($organizer, 'organizer'))->create();

    expect(Gate::forUser($buyer)->allows('view', $order))->toBeTrue()
        ->and(Gate::forUser($organizer)->allows('view', $order))->toBeTrue()
        ->and(Gate::forUser(User::factory()->admin()->create())->allows('view', $order))->toBeTrue();
});

test('hides an order from other users as not found', function (Closure $makeViewer) {
    $order = Order::factory()->create();

    $response = Gate::forUser($makeViewer->call($this))->inspect('view', $order);

    expect($response->allowed())->toBeFalse()
        ->and($response->status())->toBe(404);
})->with([
    'another customer' => fn () => User::factory()->create(),
    'organizer of another event' => fn () => User::factory()->organizer()->create(),
    'customer who bought a different order' => fn () => Order::factory()->create()->user,
]);

test('does not let a guest view an order', function () {
    $order = Order::factory()->create();

    expect(Gate::forUser(null)->allows('view', $order))->toBeFalse();
});
