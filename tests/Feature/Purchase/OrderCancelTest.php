<?php

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;
use App\Services\CheckoutService;
use Stripe\Exception\InvalidRequestException;

test('expires the Stripe checkout session before cancelling the pending order', function () {
    $buyer = User::factory()->create();
    $order = Order::factory()->for($buyer)->create(['stripe_checkout_session_id' => 'cs_test_123']);

    $this->mock(CheckoutService::class)->expects('expireSession')->with('cs_test_123');

    $this->actingAs($buyer)
        ->get(route('orders.cancel', $order))
        ->assertOk()
        ->assertSee('お支払いを中断しました');

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});

test('keeps the order pending and tells the buyer when the Stripe checkout session cannot be expired', function () {
    $buyer = User::factory()->create();
    $order = Order::factory()->for($buyer)->create(['stripe_checkout_session_id' => 'cs_test_123']);

    $this->mock(CheckoutService::class)
        ->expects('expireSession')
        ->andThrow(new InvalidRequestException('Only Checkout Sessions with status open can be expired.'));

    $this->actingAs($buyer)
        ->get(route('orders.cancel', $order))
        ->assertOk()
        ->assertSee('注文を取り消せませんでした')
        ->assertSeeHtml('data-test="could-not-cancel"')
        ->assertDontSee('注文は取り消しました');

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
});

test('cancels a pending order that has no Stripe checkout session without calling Stripe', function () {
    $buyer = User::factory()->create();
    $order = Order::factory()->for($buyer)->create(['stripe_checkout_session_id' => null]);

    $this->mock(CheckoutService::class)->shouldNotReceive('expireSession');

    $this->actingAs($buyer)->get(route('orders.cancel', $order))->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});

test('cancels the buyer\'s pending order and releases the reserved tickets', function () {
    $buyer = User::factory()->create();
    $event = Event::factory()->published()->create(['title' => '春のマルシェ']);
    $ticketType = TicketType::factory()->for($event)->create(['capacity' => 10]);
    $order = Order::factory()->for($buyer)->for($event)->create();
    OrderItem::factory()->for($order)->for($ticketType)->create(['quantity' => 4]);

    expect($ticketType->remaining())->toBe(6);

    $this->actingAs($buyer)
        ->get(route('orders.cancel', $order))
        ->assertOk()
        ->assertSee('お支払いを中断しました')
        ->assertSee('春のマルシェ')
        ->assertSee(route('events.show', $event));

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($ticketType->fresh()->remaining())->toBe(10);
});

test('does not cancel an order that has already been paid', function () {
    $buyer = User::factory()->create();
    $order = Order::factory()->for($buyer)->paid()->create(['stripe_checkout_session_id' => 'cs_test_123']);

    $this->mock(CheckoutService::class)->shouldNotReceive('expireSession');

    $this->actingAs($buyer)
        ->get(route('orders.cancel', $order))
        ->assertOk()
        ->assertSee('この注文は支払い済みです');

    expect($order->fresh()->status)->toBe(OrderStatus::Paid);
});

test('returns 404 to anyone other than the buyer and leaves the order unchanged', function (Closure $makeViewer) {
    $order = Order::factory()->create();

    $this->actingAs($makeViewer->call($this, $order))
        ->get(route('orders.cancel', $order))
        ->assertNotFound();

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
})->with([
    'another customer' => fn () => User::factory()->create(),
    'organizer of the event' => fn (Order $order) => $order->event->organizer,
    'admin' => fn () => User::factory()->admin()->create(),
]);

test('redirects guests to the login page', function () {
    $order = Order::factory()->create();

    $this->get(route('orders.cancel', $order))->assertRedirect(route('login'));

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
});

test('keeps an already cancelled order cancelled and does not call Stripe', function () {
    $buyer = User::factory()->create();
    $order = Order::factory()->for($buyer)->cancelled()->create(['stripe_checkout_session_id' => 'cs_test_123']);

    $this->mock(CheckoutService::class)->shouldNotReceive('expireSession');

    $this->actingAs($buyer)
        ->get(route('orders.cancel', $order))
        ->assertOk()
        ->assertSee('お支払いを中断しました');

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});

test('does not cancel a refunded order', function () {
    $buyer = User::factory()->create();
    $order = Order::factory()->for($buyer)->refunded()->create();

    $this->actingAs($buyer)
        ->get(route('orders.cancel', $order))
        ->assertOk()
        ->assertSee('この注文は返金済みです')
        ->assertDontSee('注文は取り消しました');

    expect($order->fresh()->status)->toBe(OrderStatus::Refunded);
});

test('redirects users with an unverified email address to the verification notice and leaves the order unchanged', function () {
    $buyer = User::factory()->unverified()->create();
    $order = Order::factory()->for($buyer)->create();

    $this->actingAs($buyer)
        ->get(route('orders.cancel', $order))
        ->assertRedirect(route('verification.notice'));

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
});
