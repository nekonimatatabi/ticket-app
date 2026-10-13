<?php

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;

function paidOrderWithTickets(User $buyer): Order
{
    $event = Event::factory()->published()->create(['title' => '春のマルシェ', 'venue' => '八幡平市民ホール']);
    $order = Order::factory()->for($buyer)->for($event)->paid()->create(['total_amount' => 7500]);
    $general = OrderItem::factory()->for($order)->for(TicketType::factory()->for($event)->create(['name' => '一般']))
        ->create(['quantity' => 2, 'unit_price' => 3000]);
    $student = OrderItem::factory()->for($order)->for(TicketType::factory()->for($event)->create(['name' => '学生']))
        ->create(['quantity' => 1, 'unit_price' => 1500]);
    Ticket::factory()->for($general)->create(['code' => '01JTESTCODEGENERAL000001A']);
    Ticket::factory()->for($general)->create(['code' => '01JTESTCODEGENERAL000002B']);
    Ticket::factory()->for($student)->create(['code' => '01JTESTCODESTUDENT000003C']);

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
        ->assertSeeInOrder(['一般', '01JTESTCODEGENERAL000001A'])
        ->assertSee('01JTESTCODEGENERAL000002B')
        ->assertSeeInOrder(['学生', '01JTESTCODESTUDENT000003C'])
        ->assertDontSee('支払いが完了していません');
});

test('tells the buyer that the payment is not complete and shows no tickets while the order is pending', function () {
    $buyer = User::factory()->create();
    $order = Order::factory()->for($buyer)->create();
    $item = OrderItem::factory()->for($order)->create();
    Ticket::factory()->for($item)->create(['code' => '01JTESTCODEPENDING00000XYZ']);

    $this->actingAs($buyer)
        ->get(route('orders.show', $order))
        ->assertOk()
        ->assertSee('支払いが完了していません')
        ->assertSeeHtml('data-test="order-pending"')
        ->assertDontSeeHtml('data-test="ticket-list"')
        ->assertDontSee('01JTESTCODEPENDING00000XYZ');
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
        ->assertDontSee('01JTESTCODEGENERAL000001A')
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
    Ticket::factory()->for($item)->create(['code' => '01JTESTCODEFREE0000000001']);

    $this->actingAs($buyer)
        ->get(route('orders.show', $order))
        ->assertOk()
        ->assertSee('0円')
        ->assertSeeInOrder(['無料招待', '01JTESTCODEFREE0000000001']);
});

test('lists the tickets grouped by ticket type in the order of the items', function () {
    $buyer = User::factory()->create();
    $order = paidOrderWithTickets($buyer);

    $html = $this->actingAs($buyer)->get(route('orders.show', $order))->assertOk()->getContent();

    expect(substr_count($html, 'data-test="ticket"'))->toBe(3)
        ->and(strpos($html, '01JTESTCODEGENERAL000002B'))->toBeLessThan(strpos($html, '01JTESTCODESTUDENT000003C'));
});

test('does not show tickets of another order', function () {
    $buyer = User::factory()->create();
    $order = paidOrderWithTickets($buyer);
    $otherItem = OrderItem::factory()->for(Order::factory()->paid())->create();
    Ticket::factory()->for($otherItem)->create(['code' => '01JTESTCODEOTHERORDER0001']);

    $this->actingAs($buyer)
        ->get(route('orders.show', $order))
        ->assertDontSee('01JTESTCODEOTHERORDER0001');
});

test('marks the navigation item as current on the detail page', function () {
    $buyer = User::factory()->create();
    $order = Order::factory()->for($buyer)->create();

    $this->actingAs($buyer)
        ->get(route('orders.show', $order))
        ->assertSeeHtml('href="'.route('orders.index').'"')
        ->assertSeeHtml('data-current');
});
