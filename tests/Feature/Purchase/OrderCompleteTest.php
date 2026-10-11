<?php

use App\Models\Event;
use App\Models\Order;
use App\Models\User;

test('shows the buyer the order that was accepted', function () {
    $buyer = User::factory()->create();
    $event = Event::factory()->published()->create(['title' => '春のマルシェ']);
    $order = Order::factory()->for($buyer)->for($event)->create(['total_amount' => 7500]);

    $this->actingAs($buyer)
        ->get(route('orders.complete', $order))
        ->assertOk()
        ->assertSee('注文を受け付けました（支払いは平日課題で実装）')
        ->assertSee((string) $order->id)
        ->assertSee('春のマルシェ')
        ->assertSee('7,500円')
        ->assertSee('支払い待ち');
});

test('lets the organizer of the event and admins open the order', function () {
    $event = Event::factory()->published()->create();
    $order = Order::factory()->for($event)->create();

    $this->actingAs($event->organizer)->get(route('orders.complete', $order))->assertOk();
    $this->actingAs(User::factory()->admin()->create())->get(route('orders.complete', $order))->assertOk();
});

test('returns 404 to other users so that they cannot open someone else\'s order', function (Closure $makeViewer) {
    $order = Order::factory()->create();

    $this->actingAs($makeViewer->call($this))
        ->get(route('orders.complete', $order))
        ->assertNotFound();
})->with([
    'another customer' => fn () => User::factory()->create(),
    'organizer of another event' => fn () => User::factory()->organizer()->create(),
]);

test('redirects guests to the login page', function () {
    $order = Order::factory()->create();

    $this->get(route('orders.complete', $order))->assertRedirect(route('login'));
});

test('redirects users with an unverified email address to the verification notice', function () {
    $buyer = User::factory()->unverified()->create();
    $order = Order::factory()->for($buyer)->create();

    $this->actingAs($buyer)
        ->get(route('orders.complete', $order))
        ->assertRedirect(route('verification.notice'));
});

test('returns 404 for an order that does not exist', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('orders.complete', 999999))
        ->assertNotFound();
});

test('sends a guest back to the completion page after logging in', function () {
    $buyer = User::factory()->create();
    $order = Order::factory()->for($buyer)->create();

    $this->get(route('orders.complete', $order))->assertRedirect(route('login'));

    expect(session('url.intended'))->toBe(route('orders.complete', $order));

    $this->post(route('login.store'), ['email' => $buyer->email, 'password' => 'password'])
        ->assertRedirect(route('orders.complete', $order));
});

test('returns 404 to the organizer of a different event', function () {
    $order = Order::factory()->for(Event::factory()->published())->create();
    $otherEvent = Event::factory()->published()->create();

    $this->actingAs($otherEvent->organizer)
        ->get(route('orders.complete', $order))
        ->assertNotFound();
});

test('does not reveal the event or the total of someone else\'s order', function () {
    $event = Event::factory()->published()->create(['title' => '秘密のイベント']);
    $order = Order::factory()->for($event)->create(['total_amount' => 7777]);

    $this->actingAs(User::factory()->create())
        ->get(route('orders.complete', $order))
        ->assertNotFound()
        ->assertDontSee('秘密のイベント')
        ->assertDontSee('7,777円')
        ->assertDontSee('注文を受け付けました');
});
