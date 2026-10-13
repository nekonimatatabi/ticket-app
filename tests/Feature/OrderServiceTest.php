<?php

use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Exceptions\PurchaseException;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\OrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\InvalidRequestException;

function placeOrder(User $user, Event $event, array $quantities): Order
{
    return app(OrderService::class)->createOrder($user, $event, $quantities);
}

function expectNothingWasOrdered(): void
{
    expect(Order::count())->toBe(0)
        ->and(OrderItem::count())->toBe(0);
}

test('has the purchase limits as constants', function () {
    expect(OrderService::PENDING_MINUTES)->toBe(35)
        ->and(OrderService::CHECKOUT_MINUTES)->toBe(30)
        ->and(OrderService::PENDING_MINUTES)->toBeGreaterThan(OrderService::CHECKOUT_MINUTES)
        ->and(OrderService::MAX_TICKETS_PER_EVENT)->toBe(10)
        ->and(OrderService::MAX_QUANTITY)->toBe(10);
});

describe('creating an order', function () {
    test('creates a pending order with items and the correct total', function () {
        $user = User::factory()->create();
        $event = Event::factory()->published()->create();
        $general = TicketType::factory()->for($event)->create(['price' => 3000]);
        $student = TicketType::factory()->for($event)->create(['price' => 1500]);

        $order = placeOrder($user, $event, [$general->id => 2, $student->id => 3]);

        expect($order)
            ->user_id->toBe($user->id)
            ->event_id->toBe($event->id)
            ->status->toBe(OrderStatus::Pending)
            ->total_amount->toBe(10500)
            ->stripe_checkout_session_id->toBeNull()
            ->paid_at->toBeNull()
            ->and($order->items->pluck('quantity', 'ticket_type_id')->all())->toEqual([$general->id => 2, $student->id => 3])
            ->and($order->items->pluck('unit_price', 'ticket_type_id')->all())->toEqual([$general->id => 3000, $student->id => 1500])
            ->and(Order::count())->toBe(1)
            ->and(OrderItem::count())->toBe(2);
    });

    test('saves the price at the time of the order and keeps it when the price changes later', function () {
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create(['price' => 3000]);

        $order = placeOrder(User::factory()->create(), $event, [$ticketType->id => 1]);
        $ticketType->update(['price' => 5000]);

        expect($order->items()->sole()->unit_price)->toBe(3000)
            ->and($order->fresh()->total_amount)->toBe(3000);
    });

    test('accepts quantities given as numeric strings', function () {
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create(['price' => 1000]);

        $order = placeOrder(User::factory()->create(), $event, [(string) $ticketType->id => '2']);

        expect($order->total_amount)->toBe(2000)
            ->and($order->items->sole()->quantity)->toBe(2);
    });

    test('creates an order of 0 yen for a free ticket', function () {
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create(['price' => 0]);

        $order = placeOrder(User::factory()->create(), $event, [$ticketType->id => 2]);

        expect($order->total_amount)->toBe(0)
            ->and($order->items->sole()->unit_price)->toBe(0);
    });

    test('ignores ticket types with a quantity of 0', function () {
        $event = Event::factory()->published()->create();
        $wanted = TicketType::factory()->for($event)->create(['price' => 3000]);
        $skipped = TicketType::factory()->for($event)->create(['price' => 1500]);

        $order = placeOrder(User::factory()->create(), $event, [$wanted->id => 2, $skipped->id => 0]);

        expect($order->items->pluck('ticket_type_id')->all())->toBe([$wanted->id])
            ->and($order->total_amount)->toBe(6000);
    });
});

describe('quantity', function () {
    test('accepts the lowest and the highest quantity', function (int $quantity) {
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create(['capacity' => 20]);

        $order = placeOrder(User::factory()->create(), $event, [$ticketType->id => $quantity]);

        expect($order->items->sole()->quantity)->toBe($quantity);
    })->with([1, OrderService::MAX_QUANTITY]);

    test('rejects a quantity above the maximum even when tickets remain', function () {
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create(['capacity' => 50]);

        expect(fn () => placeOrder(User::factory()->create(), $event, [$ticketType->id => 11]))
            ->toThrow(PurchaseException::class, '枚数は1〜10枚で指定してください。');

        expectNothingWasOrdered();
    });

    test('rejects quantities that are not whole numbers from 1 to 10', function (mixed $quantity) {
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create(['capacity' => 50]);

        expect(fn () => placeOrder(User::factory()->create(), $event, [$ticketType->id => $quantity]))
            ->toThrow(PurchaseException::class, '枚数は1〜10枚で指定してください。');

        expectNothingWasOrdered();
    })->with([
        'negative number' => -1,
        'negative string' => '-1',
        'decimal string' => '1.5',
        'float' => 1.5,
        'letters' => 'abc',
        'empty string' => '',
        'null' => null,
        'boolean' => true,
        'array' => [[2]],
        'string above the maximum' => '11',
        'huge number' => 1000000,
    ]);

    test('asks to choose a ticket when nothing is selected', function (array $quantities) {
        $event = Event::factory()->published()->create();
        TicketType::factory()->for($event)->create();

        expect(fn () => placeOrder(User::factory()->create(), $event, $quantities))
            ->toThrow(PurchaseException::class, 'チケットを1枚以上選んでください。');

        expectNothingWasOrdered();
    })->with([
        'empty list' => [[]],
    ]);

    test('asks to choose a ticket when every quantity is 0', function () {
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create();

        expect(fn () => placeOrder(User::factory()->create(), $event, [$ticketType->id => 0]))
            ->toThrow(PurchaseException::class, 'チケットを1枚以上選んでください。');

        expectNothingWasOrdered();
    });
});

describe('remaining tickets', function () {
    test('lets the buyer take exactly the remaining tickets', function () {
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create(['capacity' => 5]);
        OrderItem::factory()->for($ticketType)->for(Order::factory()->paid()->for($event), 'order')->create(['quantity' => 2]);
        OrderItem::factory()->for($ticketType)->for(Order::factory()->for($event), 'order')->create(['quantity' => 1]);

        $order = placeOrder(User::factory()->create(), $event, [$ticketType->id => 2]);

        expect($order->items->sole()->quantity)->toBe(2)
            ->and($ticketType->fresh()->remaining())->toBe(0);
    });

    test('rejects one ticket more than the remaining and names the ticket type and the remaining', function () {
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create(['name' => '一般', 'capacity' => 5]);
        OrderItem::factory()->for($ticketType)->for(Order::factory()->paid()->for($event), 'order')->create(['quantity' => 2]);
        OrderItem::factory()->for($ticketType)->for(Order::factory()->for($event), 'order')->create(['quantity' => 1]);
        $ordersBefore = Order::count();

        expect(fn () => placeOrder(User::factory()->create(), $event, [$ticketType->id => 3]))
            ->toThrow(PurchaseException::class, '「一般」は残り枚数が足りません（残り2枚）。');

        expect(Order::count())->toBe($ordersBefore)
            ->and(OrderItem::count())->toBe(2);
    });

    test('is not blocked by a pending order older than the pending minutes', function () {
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create(['capacity' => 5]);
        $expired = Order::factory()->for($event)->create([
            'status' => OrderStatus::Pending,
            'created_at' => now()->subMinutes(OrderService::PENDING_MINUTES + 1),
        ]);
        OrderItem::factory()->for($ticketType)->for($expired, 'order')->create(['quantity' => 5]);

        $order = placeOrder(User::factory()->create(), $event, [$ticketType->id => 5]);

        expect($order->items->sole()->quantity)->toBe(5);
    });

    test('is not blocked by cancelled or refunded orders', function (OrderStatus $status) {
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create(['capacity' => 3]);
        $closed = Order::factory()->for($event)->create(['status' => $status]);
        OrderItem::factory()->for($ticketType)->for($closed, 'order')->create(['quantity' => 3]);

        $order = placeOrder(User::factory()->create(), $event, [$ticketType->id => 3]);

        expect($order->items->sole()->quantity)->toBe(3);
    })->with([
        'cancelled' => OrderStatus::Cancelled,
        'refunded' => OrderStatus::Refunded,
    ]);
});

describe('sales period', function () {
    test('sells only within the sales period including both boundaries', function (string $now, bool $canBuy) {
        $this->travelTo($now);
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create([
            'name' => '一般',
            'sales_starts_at' => '2030-05-01 10:00:00',
            'sales_ends_at' => '2030-05-10 18:00:00',
        ]);

        $buy = fn () => placeOrder(User::factory()->create(), $event, [$ticketType->id => 1]);

        if ($canBuy) {
            expect($buy()->items)->toHaveCount(1);
        } else {
            expect($buy)->toThrow(PurchaseException::class, '「一般」は販売期間外です。');
            expectNothingWasOrdered();
        }
    })->with([
        'one second before the start' => ['2030-05-01 09:59:59', false],
        'exactly at the start' => ['2030-05-01 10:00:00', true],
        'exactly at the end' => ['2030-05-10 18:00:00', true],
        'one second after the end' => ['2030-05-10 18:00:01', false],
    ]);
});

describe('tampered input', function () {
    test('rejects a ticket type of another event', function () {
        $event = Event::factory()->published()->create();
        $foreign = TicketType::factory()->for(Event::factory()->published())->create();

        expect(fn () => placeOrder(User::factory()->create(), $event, [$foreign->id => 1]))
            ->toThrow(PurchaseException::class, '選択された券種が見つかりません。');

        expectNothingWasOrdered();
    });

    test('rejects ticket types that do not exist', function (int|string $ticketTypeId) {
        $event = Event::factory()->published()->create();

        expect(fn () => placeOrder(User::factory()->create(), $event, [$ticketTypeId => 1]))
            ->toThrow(PurchaseException::class, '選択された券種が見つかりません。');

        expectNothingWasOrdered();
    })->with([
        'unknown id' => 999999,
        'id that is not a number' => 'abc',
    ]);

    test('rejects an event that is not published', function (EventStatus $status) {
        $event = Event::factory()->create(['status' => $status]);
        $ticketType = TicketType::factory()->for($event)->create();

        expect(fn () => placeOrder(User::factory()->create(), $event, [$ticketType->id => 1]))
            ->toThrow(PurchaseException::class, 'このイベントは現在購入できません。');

        expectNothingWasOrdered();
    })->with([
        'draft' => EventStatus::Draft,
        'unpublished' => EventStatus::Unpublished,
    ]);
});

describe('all or nothing', function () {
    test('creates nothing when one of several ticket types is not available', function () {
        $event = Event::factory()->published()->create();
        $available = TicketType::factory()->for($event)->create(['capacity' => 10]);
        $soldOut = TicketType::factory()->for($event)->create(['name' => '学生', 'capacity' => 1]);
        OrderItem::factory()->for($soldOut)->for(Order::factory()->paid()->for($event), 'order')->create(['quantity' => 1]);
        $ordersBefore = Order::count();
        $itemsBefore = OrderItem::count();

        expect(fn () => placeOrder(User::factory()->create(), $event, [$available->id => 2, $soldOut->id => 1]))
            ->toThrow(PurchaseException::class, '「学生」は残り枚数が足りません（残り0枚）。');

        expect(Order::count())->toBe($ordersBefore)
            ->and(OrderItem::count())->toBe($itemsBefore);
    });

    test('creates nothing when one of several quantities is invalid', function () {
        $event = Event::factory()->published()->create();
        $first = TicketType::factory()->for($event)->create();
        $second = TicketType::factory()->for($event)->create();

        expect(fn () => placeOrder(User::factory()->create(), $event, [$first->id => 2, $second->id => 11]))
            ->toThrow(PurchaseException::class);

        expectNothingWasOrdered();
    });
});

describe('marking an order as paid', function () {
    test('marks a pending order as paid, saves the payment intent, and issues the tickets', function () {
        $order = pendingOrder([2, 1]);

        expect(app(OrderService::class)->markAsPaid($order, 'pi_test_123'))->toBeTrue();

        expect($order->status)->toBe(OrderStatus::Paid)
            ->and($order->fresh())
            ->status->toBe(OrderStatus::Paid)
            ->stripe_payment_intent_id->toBe('pi_test_123')
            ->paid_at->not->toBeNull()
            ->and($order->tickets()->count())->toBe(3)
            ->and(Ticket::query()->pluck('code')->unique())->toHaveCount(3);
    });

    test('accepts no payment intent for a free order', function () {
        $order = pendingOrder([1]);

        expect(app(OrderService::class)->markAsPaid($order, null))->toBeTrue()
            ->and($order->fresh()->stripe_payment_intent_id)->toBeNull();
    });

    test('returns false and issues nothing more when the order was already marked as paid', function () {
        $order = pendingOrder([2]);
        $service = app(OrderService::class);

        expect($service->markAsPaid($order, 'pi_first'))->toBeTrue()
            ->and($service->markAsPaid($order, 'pi_second'))->toBeFalse();

        expect(Ticket::count())->toBe(2)
            ->and($order->fresh()->stripe_payment_intent_id)->toBe('pi_first');
    });

    test('returns false when another process marked the same order as paid first', function () {
        $staleCopy = pendingOrder([2]);
        $sameOrder = Order::findOrFail($staleCopy->id);

        app(OrderService::class)->markAsPaid($sameOrder, 'pi_first');

        expect(app(OrderService::class)->markAsPaid($staleCopy, 'pi_second'))->toBeFalse()
            ->and(Ticket::count())->toBe(2);
    });

    test('does not touch an order that is not pending', function (OrderStatus $status) {
        Log::spy();

        $order = pendingOrder([2]);
        $order->forceFill(['status' => $status])->save();

        expect(app(OrderService::class)->markAsPaid($order, 'pi_test_123'))->toBeFalse();

        expect($order->fresh())
            ->status->toBe($status)
            ->paid_at->toBeNull()
            ->and(Ticket::count())->toBe(0);
    })->with([OrderStatus::Cancelled, OrderStatus::Refunded]);

    test('logs a warning when a payment comes in for a cancelled order', function () {
        Log::spy();

        $order = pendingOrder([1]);
        $order->forceFill(['status' => OrderStatus::Cancelled])->save();

        app(OrderService::class)->markAsPaid($order, 'pi_test_123');

        Log::shouldHaveReceived('warning')->once();
    });

    test('rolls the order back to pending when the tickets cannot be issued', function () {
        $order = pendingOrder([2]);
        Ticket::factory()->for(OrderItem::query()->firstOrFail())->create();

        DB::listen(function ($query) {
            if (str_starts_with($query->sql, 'insert into "tickets"')) {
                throw new RuntimeException('ticket insert failed');
            }
        });

        expect(fn () => app(OrderService::class)->markAsPaid($order, 'pi_test_123'))
            ->toThrow(RuntimeException::class);

        expect($order->fresh())
            ->status->toBe(OrderStatus::Pending)
            ->paid_at->toBeNull();
    });
});

function holdingOrder(User $user, Event $event, TicketType $ticketType, OrderStatus $status, int $quantity, array $attributes = []): Order
{
    $order = Order::factory()->for($user)->for($event)->create($attributes);
    $order->forceFill(['status' => $status])->save();
    OrderItem::factory()->for($order)->for($ticketType)->create(['quantity' => $quantity]);

    return $order;
}

describe('the limit per person and event', function () {
    test('rejects an order that would take the person over the limit together with what they already hold', function () {
        $user = User::factory()->create();
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create(['capacity' => 50]);
        holdingOrder($user, $event, $ticketType, OrderStatus::Paid, 6);

        expect(fn () => placeOrder($user, $event, [$ticketType->id => 5]))
            ->toThrow(PurchaseException::class, '1つのイベントで購入できるのは、1人あたり合計10枚までです。（購入済み・手続き中の6枚を含みます）');

        expect(Order::count())->toBe(1);
    });

    test('accepts an order that reaches the limit exactly', function () {
        $user = User::factory()->create();
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create(['capacity' => 50]);
        holdingOrder($user, $event, $ticketType, OrderStatus::Paid, 6);

        expect(placeOrder($user, $event, [$ticketType->id => 4]))->toBeInstanceOf(Order::class);
    });

    test('applies to free tickets too, across ticket types', function () {
        $user = User::factory()->create();
        $event = Event::factory()->published()->create();
        $first = TicketType::factory()->for($event)->create(['price' => 0, 'capacity' => 50]);
        $second = TicketType::factory()->for($event)->create(['price' => 0, 'capacity' => 50]);
        holdingOrder($user, $event, $first, OrderStatus::Paid, 6);

        expect(fn () => placeOrder($user, $event, [$second->id => 5]))->toThrow(PurchaseException::class);
    });

    test('counts a recent pending order because it holds tickets', function () {
        $user = User::factory()->create();
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create(['capacity' => 50]);
        holdingOrder($user, $event, $ticketType, OrderStatus::Pending, 8);

        expect(fn () => placeOrder($user, $event, [$ticketType->id => 3]))->toThrow(PurchaseException::class);
    });

    test('does not count what no longer counts or belongs to someone or something else', function (string $case) {
        $user = User::factory()->create();
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create(['capacity' => 50]);

        match ($case) {
            'a cancelled order' => holdingOrder($user, $event, $ticketType, OrderStatus::Cancelled, 9),
            'a refunded order' => holdingOrder($user, $event, $ticketType, OrderStatus::Refunded, 9),
            'a pending order past the holding time' => holdingOrder($user, $event, $ticketType, OrderStatus::Pending, 9, ['created_at' => now()->subMinutes(OrderService::PENDING_MINUTES + 1)]),
            'another person' => holdingOrder(User::factory()->create(), $event, $ticketType, OrderStatus::Paid, 9),
            'another event' => (function () use ($user) {
                $other = Event::factory()->published()->create();
                holdingOrder($user, $other, TicketType::factory()->for($other)->create(['capacity' => 50]), OrderStatus::Paid, 9);
            })(),
        };

        expect(placeOrder($user, $event, [$ticketType->id => 10]))->toBeInstanceOf(Order::class);
    })->with([
        'a cancelled order',
        'a refunded order',
        'a pending order past the holding time',
        'another person',
        'another event',
    ]);
});

describe('abandoning a pending order', function () {
    test('expires the Stripe checkout session first and then cancels the order', function () {
        $order = pendingOrder([2]);

        $this->mock(CheckoutService::class)->expects('expireSession')->with($order->stripe_checkout_session_id);

        expect(app(OrderService::class)->abandon($order))->toBeTrue()
            ->and($order->fresh()->status)->toBe(OrderStatus::Cancelled);
    });

    test('cancels an order that has no checkout session without calling Stripe', function () {
        $order = pendingOrder([2]);
        $order->forceFill(['stripe_checkout_session_id' => null])->save();

        $this->mock(CheckoutService::class)->shouldNotReceive('expireSession');

        expect(app(OrderService::class)->abandon($order))->toBeTrue()
            ->and($order->fresh()->status)->toBe(OrderStatus::Cancelled);
    });

    test('leaves the order alone when the session cannot be expired', function () {
        Log::spy();
        $order = pendingOrder([2]);

        $this->mock(CheckoutService::class, function ($mock) {
            $mock->expects('expireSession')->andThrow(new ApiConnectionException('down'));
            $mock->expects('retrieveSession')->andThrow(new ApiConnectionException('down'));
        });

        expect(app(OrderService::class)->abandon($order))->toBeFalse()
            ->and($order->fresh()->status)->toBe(OrderStatus::Pending);

        Log::shouldHaveReceived('warning')->once();
    });

    test('cancels the order when the session can no longer be expired because it has already expired by itself', function () {
        $order = pendingOrder([2]);
        $session = stripeSession($order, ['status' => 'expired', 'payment_status' => 'unpaid']);

        $this->mock(CheckoutService::class, function ($mock) use ($session) {
            $mock->expects('expireSession')->andThrow(new InvalidRequestException('Only Checkout Sessions with a status of "open" can be expired.'));
            $mock->expects('retrieveSession')->andReturn($session);
        });

        expect(app(OrderService::class)->abandon($order))->toBeTrue()
            ->and($order->fresh()->status)->toBe(OrderStatus::Cancelled);
    });

    test('confirms the order and does not cancel it when the session turns out to be paid', function () {
        $order = pendingOrder([2]);
        $session = stripeSession($order, ['status' => 'complete']);

        $this->mock(CheckoutService::class, function ($mock) use ($session) {
            $mock->expects('expireSession')->andThrow(new InvalidRequestException('Only Checkout Sessions with a status of "open" can be expired.'));
            $mock->expects('retrieveSession')->andReturn($session);
        });

        expect(app(OrderService::class)->abandon($order))->toBeFalse()
            ->and($order->fresh()->status)->toBe(OrderStatus::Paid)
            ->and(Ticket::count())->toBe(2);
    });

    test('leaves the order alone when the session is completed but the money has not arrived yet', function () {
        Log::spy();
        $order = pendingOrder([2]);
        $session = stripeSession($order, ['status' => 'complete', 'payment_status' => 'unpaid']);

        $this->mock(CheckoutService::class, function ($mock) use ($session) {
            $mock->expects('expireSession')->andThrow(new InvalidRequestException('Only Checkout Sessions with a status of "open" can be expired.'));
            $mock->expects('retrieveSession')->andReturn($session);
        });

        expect(app(OrderService::class)->abandon($order))->toBeFalse()
            ->and($order->fresh()->status)->toBe(OrderStatus::Pending);

        Log::shouldHaveReceived('warning')->once();
    });

    test('does nothing to an order that is already paid', function () {
        $order = pendingOrder([2]);
        $order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => now()])->save();

        $this->mock(CheckoutService::class)->shouldNotReceive('expireSession');

        expect(app(OrderService::class)->abandon($order))->toBeFalse()
            ->and($order->fresh()->status)->toBe(OrderStatus::Paid);
    });

    test('abandons only the pending orders of this person for this event', function () {
        $user = User::factory()->create();
        $event = Event::factory()->published()->create();
        $mine = pendingOrder([1], $user);
        $mine->forceFill(['event_id' => $event->id])->save();
        $otherEvent = pendingOrder([1], $user);
        $otherPerson = pendingOrder([1]);
        $otherPerson->forceFill(['event_id' => $event->id])->save();

        $this->mock(CheckoutService::class)->expects('expireSession')->once()->with($mine->stripe_checkout_session_id);

        app(OrderService::class)->abandonPendingOrders($user, $event);

        expect($mine->fresh()->status)->toBe(OrderStatus::Cancelled)
            ->and($otherEvent->fresh()->status)->toBe(OrderStatus::Pending)
            ->and($otherPerson->fresh()->status)->toBe(OrderStatus::Pending);
    });

    test('stops with a message when a pending order cannot be abandoned', function () {
        $user = User::factory()->create();
        $order = pendingOrder([1], $user);

        $this->mock(CheckoutService::class, function ($mock) {
            $mock->expects('expireSession')->andThrow(new ApiConnectionException('down'));
            $mock->expects('retrieveSession')->andThrow(new ApiConnectionException('down'));
        });

        expect(fn () => app(OrderService::class)->abandonPendingOrders($user, $order->event))
            ->toThrow(PurchaseException::class, '支払い手続き中の注文があります');
    });
});

describe('confirming a payment after the holding time', function () {
    function lateOrder(int $quantity, int $capacity): array
    {
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create(['capacity' => $capacity]);
        $order = holdingOrder(User::factory()->create(), $event, $ticketType, OrderStatus::Pending, $quantity);

        return [$order, $ticketType, $event];
    }

    test('confirms an order that is still inside the holding time without looking at the capacity', function () {
        [$order] = lateOrder(2, 2);

        expect(app(OrderService::class)->markAsPaid($order, 'pi_test_123'))->toBeTrue()
            ->and(Ticket::count())->toBe(2);
    });

    test('confirms a late order when there is still room', function () {
        [$order, $ticketType, $event] = lateOrder(2, 10);
        holdingOrder(User::factory()->create(), $event, $ticketType, OrderStatus::Paid, 5);

        $this->travel(OrderService::PENDING_MINUTES + 1)->minutes();

        expect(app(OrderService::class)->markAsPaid($order, 'pi_test_123'))->toBeTrue()
            ->and($order->fresh()->status)->toBe(OrderStatus::Paid)
            ->and(Ticket::count())->toBe(2);
    });

    test('does not issue tickets for a late order that would exceed the capacity, cancels it, and logs that a refund is needed', function () {
        Log::spy();
        [$order, $ticketType, $event] = lateOrder(2, 10);

        $this->travel(OrderService::PENDING_MINUTES + 1)->minutes();
        holdingOrder(User::factory()->create(), $event, $ticketType, OrderStatus::Paid, 9);

        expect(app(OrderService::class)->markAsPaid($order, 'pi_test_123'))->toBeFalse();

        expect($order->fresh())
            ->status->toBe(OrderStatus::Cancelled)
            ->paid_at->toBeNull()
            ->stripe_payment_intent_id->toBe('pi_test_123')
            ->and(Ticket::count())->toBe(0);

        Log::shouldHaveReceived('error')->once();
    });

    test('does not log the refund error again when the same payment is reported again', function () {
        Log::spy();
        [$order, $ticketType, $event] = lateOrder(2, 10);

        $this->travel(OrderService::PENDING_MINUTES + 1)->minutes();
        holdingOrder(User::factory()->create(), $event, $ticketType, OrderStatus::Paid, 9);

        app(OrderService::class)->markAsPaid($order, 'pi_test_123');
        app(OrderService::class)->markAsPaid($order, 'pi_test_123');
        app(OrderService::class)->markAsPaid($order, 'pi_test_123');

        Log::shouldHaveReceived('error')->once();
        expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
    });

    test('counts the orders of other people that are still holding tickets', function () {
        Log::spy();
        [$order, $ticketType, $event] = lateOrder(2, 10);

        $this->travel(OrderService::PENDING_MINUTES + 1)->minutes();
        holdingOrder(User::factory()->create(), $event, $ticketType, OrderStatus::Pending, 9);

        expect(app(OrderService::class)->markAsPaid($order, 'pi_test_123'))->toBeFalse();
    });

    test('does not count other people\'s orders that no longer hold tickets', function () {
        [$order, $ticketType, $event] = lateOrder(2, 10);

        $this->travel(OrderService::PENDING_MINUTES + 1)->minutes();
        holdingOrder(User::factory()->create(), $event, $ticketType, OrderStatus::Cancelled, 9);
        holdingOrder(User::factory()->create(), $event, $ticketType, OrderStatus::Pending, 9, ['created_at' => now()->subMinutes(OrderService::PENDING_MINUTES + 1)]);

        expect(app(OrderService::class)->markAsPaid($order, 'pi_test_123'))->toBeTrue();
    });

    test('does not log a refund error for a late payment on an order that is already paid', function () {
        Log::spy();
        [$order] = lateOrder(2, 10);
        $this->travel(OrderService::PENDING_MINUTES + 1)->minutes();

        app(OrderService::class)->markAsPaid($order, 'pi_test_123');
        app(OrderService::class)->markAsPaid($order, 'pi_test_123');

        Log::shouldNotHaveReceived('error');
    });
});

test('purchase errors can be shown to the user as they are', function () {
    $ticketType = TicketType::factory()->create(['name' => '一般', 'capacity' => 4]);

    expect(PurchaseException::notOnSale($ticketType)->getMessage())->toBe('「一般」は販売期間外です。')
        ->and(PurchaseException::notEnoughRemaining($ticketType)->getMessage())->toBe('「一般」は残り枚数が足りません（残り4枚）。')
        ->and(PurchaseException::invalidQuantity()->getMessage())->toBe('枚数は1〜10枚で指定してください。')
        ->and(PurchaseException::noTicketSelected()->getMessage())->toBe('チケットを1枚以上選んでください。')
        ->and(PurchaseException::unknownTicketType()->getMessage())->toBe('選択された券種が見つかりません。')
        ->and(PurchaseException::eventNotOnSale()->getMessage())->toBe('このイベントは現在購入できません。');
});
