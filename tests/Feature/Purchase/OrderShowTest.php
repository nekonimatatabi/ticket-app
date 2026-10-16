<?php

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\TicketQrCode;

function paidOrderWithTickets(User $buyer): Order
{
    $event = Event::factory()->published()->create(['title' => '春のマルシェ', 'venue' => '八幡平市民ホール']);
    $order = Order::factory()->for($buyer)->for($event)->paid()->create(['total_amount' => 7500]);
    $general = OrderItem::factory()->for($order)->for(TicketType::factory()->for($event)->create(['name' => '一般']))
        ->create(['quantity' => 2, 'unit_price' => 3000]);
    $student = OrderItem::factory()->for($order)->for(TicketType::factory()->for($event)->create(['name' => '学生']))
        ->create(['quantity' => 1, 'unit_price' => 1500]);
    Ticket::factory()->for($general)->create(['code' => '01HZX3K9F2M7N8P4Q5R6S7T8V1']);
    Ticket::factory()->for($general)->create(['code' => '01HZX3K9F2M7N8P4Q5R6S7T8V2']);
    Ticket::factory()->for($student)->create(['code' => '01HZX3K9F2M7N8P4Q5R6S7T8V3']);

    return $order;
}

test('shows the buyer the order with the items and the issued tickets once it is paid', function () {
    $buyer = User::factory()->create();
    $order = paidOrderWithTickets($buyer);

    $this->actingAs($buyer)
        ->get(route('orders.show', $order))
        ->assertOk()
        ->assertSee('春のマルシェ')
        ->assertSee('八幡平市民ホール')
        ->assertSee('支払い済み')
        ->assertSee('3,000円')
        ->assertSee('6,000円')
        ->assertSee('1,500円')
        ->assertSee('7,500円')
        ->assertSeeInOrder(['一般', '01HZX3K9F2M7N8P4Q5R6S7T8V1'])
        ->assertSee('01HZX3K9F2M7N8P4Q5R6S7T8V2')
        ->assertSeeInOrder(['学生', '01HZX3K9F2M7N8P4Q5R6S7T8V3'])
        ->assertDontSee('支払いが完了していません');
});

test('tells the buyer that the payment is not complete and shows no tickets while the order is pending', function () {
    $buyer = User::factory()->create();
    $order = Order::factory()->for($buyer)->create();
    $item = OrderItem::factory()->for($order)->create();
    Ticket::factory()->for($item)->create(['code' => '01HZX3K9F2M7N8P4Q5R6S7T8V6']);

    $this->actingAs($buyer)
        ->get(route('orders.show', $order))
        ->assertOk()
        ->assertSee('支払いが完了していません')
        ->assertSeeHtml('data-test="order-pending"')
        ->assertDontSeeHtml('data-test="ticket-list"')
        ->assertDontSee('01HZX3K9F2M7N8P4Q5R6S7T8V6');
});

test('shows only the status for cancelled and refunded orders', function (OrderStatus $status) {
    $buyer = User::factory()->create();
    $order = Order::factory()->for($buyer)->create();
    $order->forceFill(['status' => $status])->save();
    OrderItem::factory()->for($order)->create();

    $this->actingAs($buyer)
        ->get(route('orders.show', $order))
        ->assertOk()
        ->assertSee($status->label())
        ->assertDontSee('支払いが完了していません')
        ->assertDontSeeHtml('data-test="ticket-list"');
})->with([OrderStatus::Cancelled, OrderStatus::Refunded]);

test('lets the organizer of the event and admins see the order', function () {
    $order = paidOrderWithTickets(User::factory()->create());

    $this->actingAs($order->event->organizer)->get(route('orders.show', $order))->assertOk();
    $this->actingAs(User::factory()->admin()->create())->get(route('orders.show', $order))->assertOk();
});

test('returns 404 to other users and does not reveal the tickets', function (Closure $makeViewer) {
    $order = paidOrderWithTickets(User::factory()->create());

    $this->actingAs($makeViewer->call($this))
        ->get(route('orders.show', $order))
        ->assertNotFound()
        ->assertDontSee('01HZX3K9F2M7N8P4Q5R6S7T8V1')
        ->assertDontSee('春のマルシェ');
})->with([
    'another customer' => fn () => User::factory()->create(),
    'organizer of another event' => fn () => User::factory()->organizer()->create(),
]);

test('returns 404 for an order that does not exist', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('orders.show', 999999))
        ->assertNotFound();
});

test('redirects guests to the login page', function () {
    $order = Order::factory()->create();

    $this->get(route('orders.show', $order))->assertRedirect(route('login'));
});

test('redirects users with an unverified email address to the verification notice', function () {
    $buyer = User::factory()->unverified()->create();
    $order = Order::factory()->for($buyer)->create();

    $this->actingAs($buyer)
        ->get(route('orders.show', $order))
        ->assertRedirect(route('verification.notice'));
});

test('shows the tickets of a free order', function () {
    $buyer = User::factory()->create();
    $event = Event::factory()->published()->create();
    $order = Order::factory()->for($buyer)->for($event)->paid()->create(['total_amount' => 0]);
    $item = OrderItem::factory()->for($order)->for(TicketType::factory()->for($event)->create(['name' => '無料招待']))
        ->create(['quantity' => 1, 'unit_price' => 0]);
    Ticket::factory()->for($item)->create(['code' => '01HZX3K9F2M7N8P4Q5R6S7T8V4']);

    $this->actingAs($buyer)
        ->get(route('orders.show', $order))
        ->assertOk()
        ->assertSee('0円')
        ->assertSeeInOrder(['無料招待', '01HZX3K9F2M7N8P4Q5R6S7T8V4']);
});

test('lists the tickets grouped by ticket type in the order of the items', function () {
    $buyer = User::factory()->create();
    $order = paidOrderWithTickets($buyer);

    $html = $this->actingAs($buyer)->get(route('orders.show', $order))->assertOk()->getContent();

    expect(substr_count($html, 'data-test="ticket"'))->toBe(3)
        ->and(strpos($html, '01HZX3K9F2M7N8P4Q5R6S7T8V2'))->toBeLessThan(strpos($html, '01HZX3K9F2M7N8P4Q5R6S7T8V3'));
});

test('does not show tickets of another order', function () {
    $buyer = User::factory()->create();
    $order = paidOrderWithTickets($buyer);
    $otherItem = OrderItem::factory()->for(Order::factory()->paid())->create();
    Ticket::factory()->for($otherItem)->create(['code' => '01HZX3K9F2M7N8P4Q5R6S7T8V5']);

    $this->actingAs($buyer)
        ->get(route('orders.show', $order))
        ->assertDontSee('01HZX3K9F2M7N8P4Q5R6S7T8V5');
});

test('marks the navigation item as current on the detail page', function () {
    $buyer = User::factory()->create();
    $order = Order::factory()->for($buyer)->create();

    $this->actingAs($buyer)
        ->get(route('orders.show', $order))
        ->assertSeeHtml('href="'.route('orders.index').'"')
        ->assertSeeHtml('data-current');
});

describe('the QR code of a ticket', function () {
    test('shows the buyer a QR code of each ticket made from its code, with the code', function () {
        $buyer = User::factory()->create();
        $order = paidOrderWithTickets($buyer);

        $page = $this->actingAs($buyer)->get(route('orders.show', $order))->assertOk();

        expect(substr_count($page->getContent(), 'data-test="ticket-qr"'))->toBe(3);

        foreach (['01HZX3K9F2M7N8P4Q5R6S7T8V1', '01HZX3K9F2M7N8P4Q5R6S7T8V2', '01HZX3K9F2M7N8P4Q5R6S7T8V3'] as $code) {
            $page->assertSee(app(TicketQrCode::class)->svg($code, 144), false)->assertSee($code);
        }

        $page->assertSee('受付でこの QR コードをお見せください');
    });

    test('shows the organizer and admins only the ticket types and whether they were checked in, not the codes or the QR codes', function (Closure $makeViewer) {
        $order = paidOrderWithTickets(User::factory()->create());

        $this->actingAs($makeViewer->call($this, $order))
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('一般')
            ->assertSee('学生')
            ->assertSee('未受付')
            ->assertDontSee('01HZX3K9F2M7N8P4Q5R6S7T8V1')
            ->assertDontSee('01HZX3K9F2M7N8P4Q5R6S7T8V3')
            ->assertDontSeeHtml('data-test="ticket-qr"')
            ->assertDontSee('QR コードをお見せください');
    })->with([
        'organizer of the event' => fn (Order $order) => $order->event->organizer,
        'admin' => fn () => User::factory()->admin()->create(),
    ]);

    test('shows that a ticket was checked in, and dims its QR code', function () {
        $buyer = User::factory()->create();
        $order = paidOrderWithTickets($buyer);
        $checkedIn = Ticket::query()->where('code', '01HZX3K9F2M7N8P4Q5R6S7T8V1')->firstOrFail();
        $checkedIn->forceFill(['checked_in_at' => now(), 'checked_in_by' => $order->event->organizer->id])->save();

        $page = $this->actingAs($buyer)->get(route('orders.show', $order))->assertOk();

        $page->assertSee('受付済み（'.now()->isoFormat('M月D日(ddd) HH:mm').'）')
            ->assertSee('未受付');

        expect(substr_count($page->getContent(), 'data-checked-in="true"'))->toBe(1)
            ->and(substr_count($page->getContent(), 'data-checked-in="false"'))->toBe(2)
            ->and(substr_count($page->getContent(), 'opacity-30'))->toBe(1);
    });

    test('does not show QR codes for an order that is not paid', function () {
        $buyer = User::factory()->create();
        $order = Order::factory()->for($buyer)->create();
        $item = OrderItem::factory()->for($order)->create();
        Ticket::factory()->for($item)->create();

        $this->actingAs($buyer)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertDontSeeHtml('data-test="ticket-qr"');
    });
});

describe('the QR code in other situations', function () {
    test('does not show QR codes or codes to the buyer of a cancelled or refunded order', function (OrderStatus $status) {
        $buyer = User::factory()->create();
        $order = paidOrderWithTickets($buyer);
        $order->forceFill(['status' => $status])->save();

        $this->actingAs($buyer)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertDontSeeHtml('data-test="ticket-qr"')
            ->assertDontSee('01HZX3K9F2M7N8P4Q5R6S7T8V1')
            ->assertDontSee('QR コードをお見せください');
    })->with([OrderStatus::Cancelled, OrderStatus::Refunded]);

    test('keeps the QR code of a ticket that was checked in', function () {
        $buyer = User::factory()->create();
        $order = paidOrderWithTickets($buyer);
        Ticket::query()->where('code', '01HZX3K9F2M7N8P4Q5R6S7T8V1')->firstOrFail()->forceFill(['checked_in_at' => now()])->save();

        $page = $this->actingAs($buyer)->get(route('orders.show', $order))->assertOk();

        expect(substr_count($page->getContent(), 'data-test="ticket-qr"'))->toBe(3);
        $page->assertSee(app(TicketQrCode::class)->svg('01HZX3K9F2M7N8P4Q5R6S7T8V1', 144), false);
    });

    test('does not mix in the QR codes of tickets of another buyer', function () {
        $buyer = User::factory()->create();
        $order = paidOrderWithTickets($buyer);
        $otherItem = OrderItem::factory()->for(Order::factory()->paid())->create();
        $other = Ticket::factory()->for($otherItem)->create(['code' => '01HZX3K9F2M7N8P4Q5R6S7T8V5']);

        $page = $this->actingAs($buyer)->get(route('orders.show', $order))->assertOk();

        expect(substr_count($page->getContent(), 'data-test="ticket-qr"'))->toBe(3);
        $page->assertDontSee(app(TicketQrCode::class)->svg($other->code, 144), false);
    });
});
