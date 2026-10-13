<?php

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Ticket;
use App\Models\User;
use App\Services\CheckoutService;
use Livewire\Livewire;
use Stripe\Exception\ApiConnectionException;

test('shows the buyer that the payment is being confirmed while the order is pending', function () {
    $buyer = User::factory()->create();
    $event = Event::factory()->published()->create(['title' => '春のマルシェ']);
    $order = Order::factory()->for($buyer)->for($event)->create(['total_amount' => 7500]);

    $this->actingAs($buyer)
        ->get(route('orders.complete', $order))
        ->assertOk()
        ->assertSee('お支払いを確認しています')
        ->assertSeeHtml('wire:poll.3s="confirmPayment"')
        ->assertSee((string) $order->id)
        ->assertSee('春のマルシェ')
        ->assertSee('7,500円')
        ->assertSee('支払い待ち')
        ->assertDontSee('購入が完了しました');
});

test('shows the purchase as completed with the number of tickets once the order is paid', function () {
    $buyer = User::factory()->create();
    $order = Order::factory()->for($buyer)->paid()->create();
    $item = OrderItem::factory()->for($order)->create(['quantity' => 2]);
    Ticket::factory()->count(2)->for($item)->create();

    $this->actingAs($buyer)
        ->get(route('orders.complete', $order))
        ->assertOk()
        ->assertSee('購入が完了しました')
        ->assertSee('チケット 2 枚を発行しました')
        ->assertSee('支払い済み')
        ->assertSeeHtml('data-test="order-detail-button"')
        ->assertSee(route('orders.show', $order), false)
        ->assertDontSeeHtml('wire:poll');
});

test('does not say the purchase is completed for a cancelled order', function () {
    $buyer = User::factory()->create();
    $order = Order::factory()->for($buyer)->cancelled()->create();

    $this->actingAs($buyer)
        ->get(route('orders.complete', $order))
        ->assertOk()
        ->assertSee('この注文は購入できていません')
        ->assertDontSee('購入が完了しました')
        ->assertDontSeeHtml('wire:poll');
});

describe('confirming the payment with Stripe', function () {
    test('marks the order as paid and issues the tickets when Stripe says the session is paid', function () {
        $buyer = User::factory()->create();
        $order = pendingOrder([3], $buyer);

        $this->mock(CheckoutService::class)
            ->expects('retrieveSession')
            ->with($order->stripe_checkout_session_id)
            ->andReturn(stripeSession($order));

        $this->actingAs($buyer)
            ->get(route('orders.complete', ['order' => $order, 'session_id' => $order->stripe_checkout_session_id]))
            ->assertOk()
            ->assertSee('購入が完了しました')
            ->assertSee('チケット 3 枚を発行しました');

        expect($order->fresh())
            ->status->toBe(OrderStatus::Paid)
            ->stripe_payment_intent_id->toBe('pi_test_123')
            ->paid_at->not->toBeNull()
            ->and(Ticket::count())->toBe(3);
    });

    test('keeps the order pending and keeps polling when Stripe says the session is not paid yet', function () {
        $buyer = User::factory()->create();
        $order = pendingOrder([2], $buyer);

        $this->mock(CheckoutService::class)->expects('retrieveSession')->andReturn(stripeSession($order, ['payment_status' => 'unpaid']));

        $this->actingAs($buyer)
            ->get(route('orders.complete', ['order' => $order, 'session_id' => $order->stripe_checkout_session_id]))
            ->assertOk()
            ->assertSee('お支払いを確認しています')
            ->assertSeeHtml('wire:poll.3s="confirmPayment"');

        expect($order->fresh()->status)->toBe(OrderStatus::Pending)
            ->and(Ticket::count())->toBe(0);
    });

    test('confirms the payment when the page polls again after Stripe has been paid', function () {
        $buyer = User::factory()->create();
        $order = pendingOrder([2], $buyer);

        $this->mock(CheckoutService::class)
            ->expects('retrieveSession')
            ->twice()
            ->andReturn(stripeSession($order, ['payment_status' => 'unpaid']), stripeSession($order));

        $this->actingAs($buyer);

        Livewire::withQueryParams(['session_id' => $order->stripe_checkout_session_id])
            ->test('pages::orders.complete', ['order' => $order])
            ->assertSee('お支払いを確認しています')
            ->call('confirmPayment')
            ->assertSee('購入が完了しました');

        expect($order->fresh()->status)->toBe(OrderStatus::Paid);
    });

    test('does not call Stripe without a session_id or with one that is not the order\'s', function (?string $sessionId) {
        $buyer = User::factory()->create();
        $order = pendingOrder([2], $buyer);

        $this->mock(CheckoutService::class)->shouldNotReceive('retrieveSession');

        $this->actingAs($buyer)
            ->get(route('orders.complete', array_filter(['order' => $order, 'session_id' => $sessionId])))
            ->assertOk()
            ->assertSee('お支払いを確認しています');

        expect($order->fresh()->status)->toBe(OrderStatus::Pending);
    })->with([
        'no session_id' => null,
        'another session_id' => 'cs_test_other',
    ]);

    test('does not call Stripe again for an order that is already paid', function () {
        $buyer = User::factory()->create();
        $order = Order::factory()->for($buyer)->paid()->create(['stripe_checkout_session_id' => 'cs_test_123']);

        $this->mock(CheckoutService::class)->shouldNotReceive('retrieveSession');

        $this->actingAs($buyer)
            ->get(route('orders.complete', ['order' => $order, 'session_id' => $order->stripe_checkout_session_id]))
            ->assertOk()
            ->assertSee('購入が完了しました');

        expect(Ticket::count())->toBe(0);
    });

    test('does not confirm the order when the paid session does not match it', function (array $overrides) {
        $buyer = User::factory()->create();
        $order = pendingOrder([2], $buyer);

        $this->mock(CheckoutService::class)
            ->expects('retrieveSession')
            ->andReturn(stripeSession($order, $overrides));

        $this->actingAs($buyer)
            ->get(route('orders.complete', ['order' => $order, 'session_id' => $order->stripe_checkout_session_id]))
            ->assertOk()
            ->assertSee('お支払いを確認しています')
            ->assertDontSee('購入が完了しました');

        expect($order->fresh()->status)->toBe(OrderStatus::Pending)
            ->and(Ticket::count())->toBe(0);
    })->with([
        'another amount' => [['amount_total' => 1]],
        'another currency' => [['currency' => 'usd']],
        'another order id' => [['metadata' => ['order_id' => '999999']]],
    ]);

    test('keeps showing that the payment is being confirmed when Stripe cannot be reached', function () {
        $buyer = User::factory()->create();
        $order = pendingOrder([2], $buyer);

        $this->mock(CheckoutService::class)
            ->expects('retrieveSession')
            ->andThrow(new ApiConnectionException('Could not connect to Stripe.'));

        $this->actingAs($buyer)
            ->get(route('orders.complete', ['order' => $order, 'session_id' => $order->stripe_checkout_session_id]))
            ->assertOk()
            ->assertSee('お支払いを確認しています');

        expect($order->fresh()->status)->toBe(OrderStatus::Pending);
    });

    test('does not let anyone but the buyer, the organizer, or an admin confirm the payment', function () {
        $order = pendingOrder([2], User::factory()->create());

        $this->mock(CheckoutService::class)->shouldNotReceive('retrieveSession');

        $this->actingAs(User::factory()->create())
            ->get(route('orders.complete', ['order' => $order, 'session_id' => $order->stripe_checkout_session_id]))
            ->assertNotFound();

        expect($order->fresh()->status)->toBe(OrderStatus::Pending);
    });
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
        ->assertDontSee('お支払いを確認しています');
});

describe('when the webhook and the completion page both confirm the same order', function () {
    test('does not issue the tickets twice when the webhook comes first and the page is opened afterwards', function () {
        $buyer = User::factory()->create();
        $order = pendingOrder([2], $buyer);
        $payload = sessionCompletedPayload($order);

        postWebhook($payload, stripeSignature($payload))->assertOk();

        $this->mock(CheckoutService::class)->shouldNotReceive('retrieveSession');

        $this->actingAs($buyer)
            ->get(route('orders.complete', ['order' => $order, 'session_id' => $order->stripe_checkout_session_id]))
            ->assertOk()
            ->assertSee('購入が完了しました')
            ->assertSee('チケット 2 枚を発行しました');

        expect(Ticket::count())->toBe(2);
    });

    test('does not issue the tickets twice when the page comes first and the webhook arrives afterwards', function () {
        $buyer = User::factory()->create();
        $order = pendingOrder([2], $buyer);

        $this->partialMock(CheckoutService::class)
            ->expects('retrieveSession')
            ->once()
            ->andReturn(stripeSession($order));

        $this->actingAs($buyer)
            ->get(route('orders.complete', ['order' => $order, 'session_id' => $order->stripe_checkout_session_id]))
            ->assertSee('チケット 2 枚を発行しました');

        $paidAt = $order->fresh()->paid_at;
        $payload = sessionCompletedPayload($order, ['payment_intent' => 'pi_from_webhook']);

        $this->travel(5)->minutes();
        postWebhook($payload, stripeSignature($payload))->assertOk();

        expect(Ticket::count())->toBe(2)
            ->and($order->fresh())
            ->stripe_payment_intent_id->toBe('pi_test_123')
            ->paid_at->equalTo($paidAt)->toBeTrue();
    });
});

test('marks the order as paid and leaves the payment intent empty when Stripe returns none', function () {
    $buyer = User::factory()->create();
    $order = pendingOrder([1], $buyer);

    $this->mock(CheckoutService::class)->expects('retrieveSession')->andReturn(stripeSession($order, ['payment_intent' => null]));

    $this->actingAs($buyer)
        ->get(route('orders.complete', ['order' => $order, 'session_id' => $order->stripe_checkout_session_id]))
        ->assertSee('購入が完了しました');

    expect($order->fresh())
        ->status->toBe(OrderStatus::Paid)
        ->stripe_payment_intent_id->toBeNull()
        ->and(Ticket::count())->toBe(1);
});

test('does not call Stripe and does not say completed for a refunded order', function () {
    $buyer = User::factory()->create();
    $order = Order::factory()->for($buyer)->refunded()->create(['stripe_checkout_session_id' => 'cs_test_123']);

    $this->mock(CheckoutService::class)->shouldNotReceive('retrieveSession');

    $this->actingAs($buyer)
        ->get(route('orders.complete', ['order' => $order, 'session_id' => $order->stripe_checkout_session_id]))
        ->assertOk()
        ->assertSee('この注文は購入できていません')
        ->assertSee('返金済み')
        ->assertDontSee('購入が完了しました')
        ->assertDontSeeHtml('wire:poll');

    expect($order->fresh()->status)->toBe(OrderStatus::Refunded)
        ->and(Ticket::count())->toBe(0);
});

test('does not call Stripe for a cancelled order even with its session_id', function () {
    $buyer = User::factory()->create();
    $order = Order::factory()->for($buyer)->cancelled()->create(['stripe_checkout_session_id' => 'cs_test_123']);

    $this->mock(CheckoutService::class)->shouldNotReceive('retrieveSession');

    $this->actingAs($buyer)
        ->get(route('orders.complete', ['order' => $order, 'session_id' => $order->stripe_checkout_session_id]))
        ->assertOk()
        ->assertSee('この注文は購入できていません');

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});

test('returns 404 to an organizer of another event who opens the page with the session_id, without calling Stripe', function () {
    $order = pendingOrder([2], User::factory()->create());

    $this->mock(CheckoutService::class)->shouldNotReceive('retrieveSession');

    $this->actingAs(User::factory()->organizer()->create())
        ->get(route('orders.complete', ['order' => $order, 'session_id' => $order->stripe_checkout_session_id]))
        ->assertNotFound();

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
});
