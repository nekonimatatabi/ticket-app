<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('anyone can view a published event', function (?Closure $makeViewer) {
    $event = Event::factory()->published()->create();

    $viewer = $makeViewer?->call($this);

    expect(Gate::forUser($viewer)->allows('view', $event))->toBeTrue();
})->with([
    'guest' => null,
    'customer' => fn () => User::factory()->create(),
    'other organizer' => fn () => User::factory()->organizer()->create(),
]);

test('only the owner and admins can view an event that is not published', function (EventStatus $status) {
    $event = Event::factory()->create(['status' => $status]);

    expect(Gate::forUser($event->organizer)->allows('view', $event))->toBeTrue()
        ->and(Gate::forUser(User::factory()->admin()->create())->allows('view', $event))->toBeTrue()
        ->and(Gate::forUser(User::factory()->organizer()->create())->allows('view', $event))->toBeFalse()
        ->and(Gate::forUser(User::factory()->create())->allows('view', $event))->toBeFalse()
        ->and(Gate::forUser(null)->allows('view', $event))->toBeFalse();
})->with([
    'draft' => EventStatus::Draft,
    'unpublished' => EventStatus::Unpublished,
]);

test('hides an event that is not published from other users as not found', function (?Closure $makeViewer) {
    $event = Event::factory()->create();

    $viewer = $makeViewer?->call($this);
    $response = Gate::forUser($viewer)->inspect('view', $event);

    expect($response->allowed())->toBeFalse()
        ->and($response->status())->toBe(404);
})->with([
    'guest' => null,
    'customer' => fn () => User::factory()->create(),
    'other organizer' => fn () => User::factory()->organizer()->create(),
]);

test('only organizers can create events', function () {
    expect(Gate::forUser(User::factory()->organizer()->create())->allows('create', Event::class))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows('create', Event::class))->toBeFalse()
        ->and(Gate::forUser(User::factory()->admin()->create())->allows('create', Event::class))->toBeFalse();
});

test('only the owner can update and delete an event', function (string $ability) {
    $event = Event::factory()->published()->create();

    expect(Gate::forUser($event->organizer)->allows($ability, $event))->toBeTrue()
        ->and(Gate::forUser(User::factory()->organizer()->create())->allows($ability, $event))->toBeFalse()
        ->and(Gate::forUser(User::factory()->admin()->create())->allows($ability, $event))->toBeFalse()
        ->and(Gate::forUser(User::factory()->create())->allows($ability, $event))->toBeFalse();
})->with(['update', 'delete']);

test('only admins can unpublish an event', function () {
    $event = Event::factory()->published()->create();

    expect(Gate::forUser(User::factory()->admin()->create())->allows('unpublish', $event))->toBeTrue()
        ->and(Gate::forUser($event->organizer)->allows('unpublish', $event))->toBeFalse()
        ->and(Gate::forUser(User::factory()->create())->allows('unpublish', $event))->toBeFalse();
});
