<?php

use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;

test('shows the details of a published event to guests', function () {
    app()->setLocale('ja');
    $event = Event::factory()->published()->create([
        'title' => '春のマルシェ',
        'description' => '商店街で開く小さなマルシェです。',
        'venue' => '中央公園',
        'starts_at' => '2030-05-01 10:00:00',
        'ends_at' => '2030-05-01 12:30:00',
    ]);

    $this->get(route('events.show', $event))
        ->assertOk()
        ->assertSee('春のマルシェ')
        ->assertSee('商店街で開く小さなマルシェです。')
        ->assertSee('中央公園')
        ->assertSee('5月1日(水) 10:00')
        ->assertSee('5月1日(水) 12:30')
        ->assertSee($event->organizer->name);
});

test('does not show the organizer email address', function () {
    $event = Event::factory()->published()->create();

    $this->get(route('events.show', $event))
        ->assertOk()
        ->assertDontSee($event->organizer->email);
});

test('escapes the description and keeps line breaks', function () {
    $event = Event::factory()->published()->create([
        'description' => "1行目<script>alert('xss')</script>\n2行目",
    ]);

    $this->get(route('events.show', $event))
        ->assertOk()
        ->assertSee('&lt;script&gt;', false)
        ->assertDontSee("<script>alert('xss')</script>", false)
        ->assertSee('1行目', false)
        ->assertSee('<br', false)
        ->assertSee('2行目', false);
});

test('shows an event that has already ended', function () {
    $event = Event::factory()->published()->past()->create(['title' => '終了したイベント']);

    $this->get(route('events.show', $event))
        ->assertOk()
        ->assertSee('終了したイベント');
});

test('returns 404 for an event that does not exist', function () {
    $this->get(route('events.show', 999999))->assertNotFound();
});

describe('unpublished events', function () {
    test('returns 404 for guests and users who are not the owner or an admin', function (EventStatus $status, ?Closure $makeViewer) {
        $event = Event::factory()->create(['title' => '見せてはいけないイベント', 'status' => $status]);
        $viewer = $makeViewer?->call($this);

        $response = $viewer ? $this->actingAs($viewer) : $this;

        $response->get(route('events.show', $event))
            ->assertNotFound()
            ->assertDontSee('見せてはいけないイベント');
    })->with([
        'draft for a guest' => [EventStatus::Draft, null],
        'draft for a customer' => [EventStatus::Draft, fn () => User::factory()->create()],
        'draft for another organizer' => [EventStatus::Draft, fn () => User::factory()->organizer()->create()],
        'unpublished for a guest' => [EventStatus::Unpublished, null],
        'unpublished for a customer' => [EventStatus::Unpublished, fn () => User::factory()->create()],
        'unpublished for another organizer' => [EventStatus::Unpublished, fn () => User::factory()->organizer()->create()],
    ]);

    test('lets the owner and admins see them', function (EventStatus $status) {
        $event = Event::factory()->create(['title' => '準備中のイベント', 'status' => $status]);

        $this->actingAs($event->organizer)
            ->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('準備中のイベント');

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('events.show', $event))
            ->assertOk();
    })->with([
        'draft' => EventStatus::Draft,
        'unpublished' => EventStatus::Unpublished,
    ]);
});

describe('ticket types', function () {
    test('shows each ticket type with its price, remaining tickets, and sales period', function () {
        app()->setLocale('ja');
        $event = Event::factory()->published()->create();
        TicketType::factory()->for($event)->create([
            'name' => '一般',
            'price' => 3000,
            'capacity' => 20,
            'sales_starts_at' => '2030-04-01 10:00:00',
            'sales_ends_at' => '2030-04-30 18:00:00',
        ]);

        $this->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('一般')
            ->assertSee('3,000円')
            ->assertSee('残り20枚')
            ->assertSee('4月1日(月) 10:00')
            ->assertSee('4月30日(火) 18:00');
    });

    test('shows a free ticket as 0 yen', function () {
        $event = Event::factory()->published()->create();
        TicketType::factory()->for($event)->create(['name' => '無料券', 'price' => 0]);

        $this->get(route('events.show', $event))->assertSee('0円');
    });

    test('lists ticket types in the order of sales start', function () {
        $event = Event::factory()->published()->create();
        TicketType::factory()->for($event)->create(['name' => '後から販売', 'sales_starts_at' => now()->addDays(5), 'sales_ends_at' => now()->addDays(10)]);
        TicketType::factory()->for($event)->create(['name' => '先に販売', 'sales_starts_at' => now()->subDay(), 'sales_ends_at' => now()->addDays(10)]);

        $this->get(route('events.show', $event))->assertSeeInOrder(['先に販売', '後から販売']);
    });

    test('subtracts paid orders and recent pending orders from the remaining tickets', function () {
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create(['capacity' => 10]);
        OrderItem::factory()->for($ticketType)->for(Order::factory()->paid()->for($event), 'order')->create(['quantity' => 3]);
        OrderItem::factory()->for($ticketType)->for(Order::factory()->for($event), 'order')->create(['quantity' => 2]);

        $this->get(route('events.show', $event))->assertSee('残り5枚');
    });

    test('does not subtract pending orders older than 15 minutes', function () {
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create(['capacity' => 10]);
        $expired = Order::factory()->for($event)->create(['status' => OrderStatus::Pending, 'created_at' => now()->subMinutes(16)]);
        OrderItem::factory()->for($ticketType)->for($expired, 'order')->create(['quantity' => 4]);

        $this->get(route('events.show', $event))->assertSee('残り10枚');
    });

    test('shows the remaining tickets according to the order status', function (OrderStatus $status, int $secondsAgo, string $expected) {
        $this->travelTo('2030-05-01 12:00:00');
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create(['capacity' => 10, 'sales_starts_at' => now()->subDay(), 'sales_ends_at' => now()->addDay()]);
        $order = Order::factory()->for($event)->create(['status' => $status, 'created_at' => now()->subSeconds($secondsAgo)]);
        OrderItem::factory()->for($ticketType)->for($order, 'order')->create(['quantity' => 4]);

        $this->get(route('events.show', $event))
            ->assertOk()
            ->assertSee($expected);
    })->with([
        'paid' => [OrderStatus::Paid, 0, '残り6枚'],
        'old paid' => [OrderStatus::Paid, 86400, '残り6枚'],
        'pending just now' => [OrderStatus::Pending, 0, '残り6枚'],
        'pending exactly 15 minutes ago' => [OrderStatus::Pending, 900, '残り6枚'],
        'pending 15 minutes and 1 second ago' => [OrderStatus::Pending, 901, '残り10枚'],
        'cancelled' => [OrderStatus::Cancelled, 0, '残り10枚'],
        'refunded' => [OrderStatus::Refunded, 0, '残り10枚'],
    ]);

    test('shows sold out when recent pending orders take every ticket and frees them after 15 minutes', function (int $secondsAgo, string $expected, string $unexpected) {
        $this->travelTo('2030-05-01 12:00:00');
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create(['capacity' => 5, 'sales_starts_at' => now()->subDay(), 'sales_ends_at' => now()->addDay()]);
        $order = Order::factory()->for($event)->create(['created_at' => now()->subSeconds($secondsAgo)]);
        OrderItem::factory()->for($ticketType)->for($order, 'order')->create(['quantity' => 5]);

        $this->get(route('events.show', $event))
            ->assertOk()
            ->assertSee($expected)
            ->assertDontSee($unexpected);
    })->with([
        'pending exactly 15 minutes ago' => [900, '売り切れ', '残り5枚'],
        'pending 15 minutes and 1 second ago' => [901, '残り5枚', '売り切れ'],
    ]);

    test('shows sold out when no tickets remain', function () {
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create(['capacity' => 5]);
        OrderItem::factory()->for($ticketType)->for(Order::factory()->paid()->for($event), 'order')->create(['quantity' => 5]);

        $this->get(route('events.show', $event))
            ->assertSee('売り切れ')
            ->assertDontSee('残り0枚');
    });

    test('shows the sales status of each ticket type', function () {
        $event = Event::factory()->published()->create();
        TicketType::factory()->for($event)->create(['name' => '販売中の券', 'sales_starts_at' => now()->subDay(), 'sales_ends_at' => now()->addDay()]);
        TicketType::factory()->for($event)->create(['name' => '販売前の券', 'sales_starts_at' => now()->addDays(2), 'sales_ends_at' => now()->addDays(3)]);
        TicketType::factory()->for($event)->create(['name' => '販売終了の券', 'sales_starts_at' => now()->subDays(3), 'sales_ends_at' => now()->subDays(2)]);

        $this->get(route('events.show', $event))
            ->assertSee('販売中')
            ->assertSee('販売前')
            ->assertSee('販売終了');
    });

    test('shows a message when the event has no ticket types', function () {
        $event = Event::factory()->published()->create();

        $this->get(route('events.show', $event))->assertSee('券種はまだありません');
    });
});
