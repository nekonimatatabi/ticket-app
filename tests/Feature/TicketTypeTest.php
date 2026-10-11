<?php

use App\Models\Event;
use App\Models\TicketType;

test('is on sale only within the sales period including both boundaries', function (string $now, bool $expected) {
    $this->travelTo($now);

    $ticketType = TicketType::factory()->create([
        'sales_starts_at' => '2030-05-01 10:00:00',
        'sales_ends_at' => '2030-05-10 18:00:00',
    ]);

    expect($ticketType->isOnSale())->toBe($expected);
})->with([
    'one second before the start' => ['2030-05-01 09:59:59', false],
    'exactly at the start' => ['2030-05-01 10:00:00', true],
    'in the middle of the period' => ['2030-05-05 12:00:00', true],
    'exactly at the end' => ['2030-05-10 18:00:00', true],
    'one second after the end' => ['2030-05-10 18:00:01', false],
]);

test('belongs to an event that lists it among its ticket types', function () {
    $event = Event::factory()->create();
    $ticketType = TicketType::factory()->for($event)->create();

    expect($ticketType->event->is($event))->toBeTrue()
        ->and($event->ticketTypes->pluck('id')->all())->toBe([$ticketType->id]);
});

test('price and capacity are cast to integers', function () {
    $ticketType = TicketType::factory()->create(['price' => '3000', 'capacity' => '20']);

    expect($ticketType->fresh())
        ->price->toBeInt()->toBe(3000)
        ->capacity->toBeInt()->toBe(20);
});

test('event cannot be mass assigned', function () {
    $event = Event::factory()->create();
    $other = Event::factory()->create();

    $ticketType = TicketType::factory()->for($event)->create();
    $ticketType->fill(['event_id' => $other->id])->save();

    expect($ticketType->fresh()->event_id)->toBe($event->id);
});

test('an event can be published only when it has a ticket type', function () {
    $withoutTicketTypes = Event::factory()->create();
    $withTicketType = Event::factory()->has(TicketType::factory())->create();

    expect($withoutTicketTypes->canBePublished())->toBeFalse()
        ->and($withTicketType->canBePublished())->toBeTrue();
});

test('deleting an event deletes its ticket types', function () {
    $event = Event::factory()->has(TicketType::factory()->count(2))->create();

    $event->delete();

    expect(TicketType::count())->toBe(0);
});
