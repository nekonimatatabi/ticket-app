<?php

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use Illuminate\Support\Facades\DB;

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

function reserveTickets(TicketType $ticketType, int $quantity, OrderStatus $status = OrderStatus::Paid, ?DateTimeInterface $createdAt = null): OrderItem
{
    $order = Order::factory()->for($ticketType->event)->create([
        'status' => $status,
        'created_at' => $createdAt ?? now(),
    ]);

    return OrderItem::factory()->for($order)->for($ticketType)->create(['quantity' => $quantity]);
}

test('pending orders hold tickets for 15 minutes', function () {
    expect(TicketType::PENDING_MINUTES)->toBe(15);
});

test('remaining equals the capacity when nothing was ordered', function () {
    $ticketType = TicketType::factory()->create(['capacity' => 10]);

    expect($ticketType->remaining())->toBe(10);
});

test('remaining subtracts paid orders and recent pending orders', function () {
    $ticketType = TicketType::factory()->create(['capacity' => 10]);
    reserveTickets($ticketType, 3, OrderStatus::Paid);
    reserveTickets($ticketType, 2, OrderStatus::Pending);

    expect($ticketType->remaining())->toBe(5);
});

test('remaining counts a pending order up to exactly 15 minutes old but not older', function (int $secondsAgo, int $expected) {
    $this->travelTo('2030-05-01 12:00:00');
    $ticketType = TicketType::factory()->create(['capacity' => 10]);
    reserveTickets($ticketType, 4, OrderStatus::Pending, now()->subSeconds($secondsAgo));

    expect($ticketType->remaining())->toBe($expected);
})->with([
    'just created' => [0, 6],
    'one minute old' => [60, 6],
    'exactly 15 minutes old' => [900, 6],
    'one second past 15 minutes' => [901, 10],
    'an hour old' => [3600, 10],
]);

test('remaining keeps counting a paid order however old it is', function () {
    $this->travelTo('2030-05-01 12:00:00');
    $ticketType = TicketType::factory()->create(['capacity' => 10]);
    reserveTickets($ticketType, 4, OrderStatus::Paid, now()->subYear());

    expect($ticketType->remaining())->toBe(6);
});

test('remaining ignores cancelled and refunded orders', function (OrderStatus $status) {
    $ticketType = TicketType::factory()->create(['capacity' => 10]);
    reserveTickets($ticketType, 4, $status);

    expect($ticketType->remaining())->toBe(10);
})->with([
    'cancelled' => OrderStatus::Cancelled,
    'refunded' => OrderStatus::Refunded,
]);

test('remaining ignores orders of other ticket types of the same event', function () {
    $event = Event::factory()->create();
    $ticketType = TicketType::factory()->for($event)->create(['capacity' => 10]);
    $other = TicketType::factory()->for($event)->create(['capacity' => 10]);
    reserveTickets($other, 7);

    expect($ticketType->remaining())->toBe(10);
});

test('remaining adds up several orders', function () {
    $ticketType = TicketType::factory()->create(['capacity' => 10]);
    reserveTickets($ticketType, 1, OrderStatus::Paid);
    reserveTickets($ticketType, 2, OrderStatus::Paid);
    reserveTickets($ticketType, 3, OrderStatus::Pending);

    expect($ticketType->remaining())->toBe(4);
});

test('remaining never goes below zero', function () {
    $ticketType = TicketType::factory()->create(['capacity' => 2]);
    reserveTickets($ticketType, 5, OrderStatus::Paid);

    expect($ticketType->remaining())->toBe(0);
});

test('reserved quantities for many ticket types load in a single query', function () {
    $event = Event::factory()->create();
    $first = TicketType::factory()->for($event)->create(['capacity' => 10]);
    $second = TicketType::factory()->for($event)->create(['capacity' => 20]);
    reserveTickets($first, 3);
    reserveTickets($second, 8, OrderStatus::Pending);

    DB::enableQueryLog();
    $ticketTypes = $event->ticketTypes()->withReservedQuantity()->orderBy('id')->get();
    $remaining = $ticketTypes->map->remaining()->all();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($remaining)->toBe([7, 12])
        ->and($queries)->toBe(1);
});
