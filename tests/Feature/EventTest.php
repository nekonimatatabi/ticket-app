<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;

test('published scope returns only published events', function () {
    $published = Event::factory()->published()->create();
    Event::factory()->create();
    Event::factory()->unpublished()->create();

    expect(Event::published()->pluck('id')->all())->toBe([$published->id]);
});

test('upcoming scope excludes events that have already started', function () {
    $upcoming = Event::factory()->create();
    Event::factory()->past()->create();

    expect(Event::upcoming()->pluck('id')->all())->toBe([$upcoming->id]);
});

test('isPublished is true only for published events', function () {
    expect(Event::factory()->published()->make()->isPublished())->toBeTrue()
        ->and(Event::factory()->make()->isPublished())->toBeFalse()
        ->and(Event::factory()->unpublished()->make()->isPublished())->toBeFalse();
});

test('status is cast to the enum and defaults to draft', function () {
    $event = Event::factory()->create();

    expect($event->fresh()->status)->toBe(EventStatus::Draft);
});

test('status and organizer cannot be mass assigned', function () {
    $organizer = User::factory()->organizer()->create();
    $other = User::factory()->organizer()->create();

    $event = Event::factory()->for($organizer, 'organizer')->create();
    $event->fill(['status' => 'published', 'organizer_id' => $other->id])->save();

    expect($event->fresh())
        ->status->toBe(EventStatus::Draft)
        ->organizer_id->toBe($organizer->id);
});

test('event status labels and badge colors', function (EventStatus $status, string $label, string $color) {
    expect($status->label())->toBe($label)
        ->and($status->color())->toBe($color);
})->with([
    'draft' => [EventStatus::Draft, '下書き', 'zinc'],
    'published' => [EventStatus::Published, '公開中', 'green'],
    'unpublished' => [EventStatus::Unpublished, '非公開', 'red'],
]);

test('organizer owns events through the organizer relation', function () {
    $event = Event::factory()->create();

    expect($event->organizer->events->pluck('id')->all())->toBe([$event->id]);
});
