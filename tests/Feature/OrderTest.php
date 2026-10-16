<?php

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('order status labels are in Japanese', function (OrderStatus $status, string $label) {
    expect($status->label())->toBe($label);
})->with([
    'pending' => [OrderStatus::Pending, '支払い待ち'],
    'paid' => [OrderStatus::Paid, '支払い済み'],
    'cancelled' => [OrderStatus::Cancelled, 'キャンセル'],
    'refunded' => [OrderStatus::Refunded, '返金済み'],
]);

test('order status values match the stored strings', function () {
    expect(array_column(OrderStatus::cases(), 'value'))
        ->toBe(['pending', 'paid', 'cancelled', 'refunded']);
});

test('an order defaults to pending in the database', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    DB::table('orders')->insert([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'total_amount' => 3000,
    ]);

    expect(Order::sole())
        ->status->toBe(OrderStatus::Pending)
        ->stripe_checkout_session_id->toBeNull()
        ->paid_at->toBeNull();
});

test('order attributes are cast to their types', function () {
    $order = Order::factory()->paid()->create(['total_amount' => '4500']);

    expect($order->fresh())
        ->status->toBe(OrderStatus::Paid)
        ->total_amount->toBeInt()->toBe(4500)
        ->paid_at->toBeInstanceOf(CarbonInterface::class);
});

test('order item quantity and unit price are cast to integers', function () {
    $item = OrderItem::factory()->create(['quantity' => '2', 'unit_price' => '3000']);

    expect($item->fresh())
        ->quantity->toBeInt()->toBe(2)
        ->unit_price->toBeInt()->toBe(3000);
});

test('an order belongs to a user and an event and has items', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();
    $order = Order::factory()->for($user)->for($event)->create();
    $item = OrderItem::factory()->for($order)->create();

    expect($order->user->is($user))->toBeTrue()
        ->and($order->event->is($event))->toBeTrue()
        ->and($order->items->pluck('id')->all())->toBe([$item->id])
        ->and($user->orders->pluck('id')->all())->toBe([$order->id])
        ->and($event->orders->pluck('id')->all())->toBe([$order->id]);
});

test('an order item belongs to an order and a ticket type', function () {
    $ticketType = TicketType::factory()->create();
    $item = OrderItem::factory()->for($ticketType)->create();

    expect($item->order->items->pluck('id')->all())->toBe([$item->id])
        ->and($item->ticketType->is($ticketType))->toBeTrue()
        ->and($ticketType->orderItems->pluck('id')->all())->toBe([$item->id]);
});

test('order state, amount, and owner cannot be mass assigned', function (string $attribute, mixed $value) {
    expect(fn () => (new Order)->fill([$attribute => $value]))
        ->toThrow(MassAssignmentException::class);
})->with([
    'user' => ['user_id', 1],
    'event' => ['event_id', 1],
    'status' => ['status', 'paid'],
    'total amount' => ['total_amount', 1],
    'stripe session' => ['stripe_checkout_session_id', 'cs_test'],
    'paid at' => ['paid_at', '2030-01-01 00:00:00'],
]);

test('the order of an order item cannot be mass assigned', function () {
    $order = Order::factory()->create();

    $item = (new OrderItem)->fill(['order_id' => $order->id, 'quantity' => 1]);

    expect($item->order_id)->toBeNull()
        ->and($item->quantity)->toBe(1);
});

test('the same ticket type cannot appear twice in one order', function () {
    $order = Order::factory()->create();
    $ticketType = TicketType::factory()->create();
    OrderItem::factory()->for($order)->for($ticketType)->create();

    expect(fn () => OrderItem::factory()->for($order)->for($ticketType)->create())
        ->toThrow(QueryException::class);
});

test('the same ticket type can appear in different orders', function () {
    $ticketType = TicketType::factory()->create();

    OrderItem::factory()->for($ticketType)->create();
    OrderItem::factory()->for($ticketType)->create();

    expect(OrderItem::count())->toBe(2);
});

test('a stripe checkout session id cannot be reused but many orders can have none', function () {
    Order::factory()->count(2)->create();
    Order::factory()->create(['stripe_checkout_session_id' => 'cs_test_1']);

    expect(Order::count())->toBe(3)
        ->and(fn () => Order::factory()->create(['stripe_checkout_session_id' => 'cs_test_1']))
        ->toThrow(QueryException::class);
});

test('an event with an order cannot be deleted', function () {
    $order = Order::factory()->create();

    expect(fn () => $order->event->delete())->toThrow(QueryException::class)
        ->and(Event::whereKey($order->event_id)->exists())->toBeTrue();
});

test('a user with an order cannot be deleted', function () {
    $order = Order::factory()->create();

    expect(fn () => $order->user->delete())->toThrow(QueryException::class)
        ->and(User::whereKey($order->user_id)->exists())->toBeTrue();
});

test('a ticket type that was ordered cannot be deleted', function () {
    $item = OrderItem::factory()->create();

    expect(fn () => $item->ticketType->delete())->toThrow(QueryException::class)
        ->and(TicketType::whereKey($item->ticket_type_id)->exists())->toBeTrue();
});

test('deleting an order deletes its items', function () {
    $order = Order::factory()->has(OrderItem::factory()->count(2), 'items')->create();

    $order->delete();

    expect(OrderItem::count())->toBe(0);
});

test('an event without orders can still be deleted with its ticket types', function () {
    $event = Event::factory()->has(TicketType::factory())->create();

    $event->delete();

    expect(Event::count())->toBe(0)
        ->and(TicketType::count())->toBe(0);
});
