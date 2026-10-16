<?php

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\OrderService;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\InvalidRequestException;

function pressProceedToPayment(User $user, Event $event, array $quantities): Testable
{
    session()->put("purchase.{$event->id}", $quantities);

    return Livewire::actingAs($user)
        ->test('pages::events.purchase', ['event' => $event])
        ->call('proceedToPayment');
}

function eventWithTicketType(array $attributes = []): array
{
    $event = Event::factory()->published()->create();

    return [$event, TicketType::factory()->for($event)->create(['price' => 3000, 'capacity' => 50, ...$attributes])];
}

describe('an old pending order of the same person', function () {
    test('is cancelled and its checkout session expired before the new order is made', function () {
        $user = User::factory()->create();
        [$event, $ticketType] = eventWithTicketType();
        $old = Order::factory()->for($user)->for($event)->create(['stripe_checkout_session_id' => 'cs_test_old']);
        OrderItem::factory()->for($old)->for($ticketType)->create(['quantity' => 2]);

        $this->mock(CheckoutService::class, function ($mock) {
            $mock->expects('expireSession')->with('cs_test_old');
            $mock->expects('createSession')->andReturn('https://checkout.stripe.com/c/pay/cs_test_new');
        });

        pressProceedToPayment($user, $event, [$ticketType->id => 3])
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_new');

        expect($old->fresh()->status)->toBe(OrderStatus::Cancelled)
            ->and(Order::where('status', OrderStatus::Pending)->count())->toBe(1)
            ->and($ticketType->fresh()->remaining())->toBe(47);
    });

    test('stops the purchase with a message when it cannot be cancelled, and creates nothing new', function () {
        $user = User::factory()->create();
        [$event, $ticketType] = eventWithTicketType();
        $old = Order::factory()->for($user)->for($event)->create(['stripe_checkout_session_id' => 'cs_test_old']);
        OrderItem::factory()->for($old)->for($ticketType)->create(['quantity' => 2]);

        $this->mock(CheckoutService::class, function ($mock) {
            $mock->expects('expireSession')->andThrow(new ApiConnectionException('down'));
            $mock->expects('retrieveSession')->andThrow(new ApiConnectionException('down'));
            $mock->shouldNotReceive('createSession');
        });

        pressProceedToPayment($user, $event, [$ticketType->id => 3])
            ->assertSee('支払い手続き中の注文があります')
            ->assertNoRedirect();

        expect($old->fresh()->status)->toBe(OrderStatus::Pending)
            ->and(Order::count())->toBe(1);
    });

    test('does not block a new purchase when the old checkout page has expired by itself', function () {
        $user = User::factory()->create();
        [$event, $ticketType] = eventWithTicketType();
        $old = Order::factory()->for($user)->for($event)->create([
            'stripe_checkout_session_id' => 'cs_test_old',
            'created_at' => now()->subMinutes(OrderService::PENDING_MINUTES + 5),
        ]);
        OrderItem::factory()->for($old)->for($ticketType)->create(['quantity' => 1]);
        $expired = Session::constructFrom(['id' => 'cs_test_old', 'object' => 'checkout.session', 'status' => 'expired', 'payment_status' => 'unpaid']);

        $this->mock(CheckoutService::class, function ($mock) use ($expired) {
            $mock->expects('expireSession')->andThrow(new InvalidRequestException('Only Checkout Sessions with a status of "open" can be expired.'));
            $mock->expects('retrieveSession')->with('cs_test_old')->andReturn($expired);
            $mock->expects('createSession')->andReturn('https://checkout.stripe.com/c/pay/cs_test_new');
        });

        pressProceedToPayment($user, $event, [$ticketType->id => 2])
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_new');

        expect($old->fresh()->status)->toBe(OrderStatus::Cancelled)
            ->and(Order::where('status', OrderStatus::Pending)->count())->toBe(1);
    });

    test('stops the purchase and confirms the old order when it turns out to have been paid', function () {
        $user = User::factory()->create();
        [$event, $ticketType] = eventWithTicketType();
        $old = Order::factory()->for($user)->for($event)->create(['stripe_checkout_session_id' => 'cs_test_old', 'total_amount' => 3000]);
        OrderItem::factory()->for($old)->for($ticketType)->create(['quantity' => 1, 'unit_price' => 3000]);

        $this->mock(CheckoutService::class, function ($mock) use ($old) {
            $mock->expects('expireSession')->andThrow(new InvalidRequestException('Only Checkout Sessions with a status of "open" can be expired.'));
            $mock->expects('retrieveSession')->andReturn(stripeSession($old, ['status' => 'complete']));
            $mock->shouldNotReceive('createSession');
        });

        pressProceedToPayment($user, $event, [$ticketType->id => 2])
            ->assertSee('支払い手続き中の注文があります')
            ->assertNoRedirect();

        expect($old->fresh()->status)->toBe(OrderStatus::Paid)
            ->and(Order::count())->toBe(1);
    });

    test('does not touch the pending orders of other people', function () {
        $user = User::factory()->create();
        [$event, $ticketType] = eventWithTicketType();
        $other = Order::factory()->for($event)->create(['stripe_checkout_session_id' => 'cs_test_other']);
        OrderItem::factory()->for($other)->for($ticketType)->create(['quantity' => 2]);

        $this->mock(CheckoutService::class, function ($mock) {
            $mock->shouldNotReceive('expireSession');
            $mock->expects('createSession')->andReturn('https://checkout.stripe.com/c/pay/cs_test_new');
        });

        pressProceedToPayment($user, $event, [$ticketType->id => 3])->assertRedirect();

        expect($other->fresh()->status)->toBe(OrderStatus::Pending);
    });
});

describe('the limit per person and event on the purchase page', function () {
    test('shows the reason and creates no order when the person would go over the limit', function () {
        $user = User::factory()->create();
        [$event, $ticketType] = eventWithTicketType();
        $paid = Order::factory()->for($user)->for($event)->paid()->create();
        OrderItem::factory()->for($paid)->for($ticketType)->create(['quantity' => 8]);

        $this->mock(CheckoutService::class)->shouldNotReceive('createSession');

        pressProceedToPayment($user, $event, [$ticketType->id => 3])
            ->assertSee('1人あたり合計10枚まで')
            ->assertSeeHtml('data-test="purchase-error"')
            ->assertNoRedirect();

        expect(Order::count())->toBe(1);
    });

    test('limits free tickets too', function () {
        $user = User::factory()->create();
        [$event, $free] = eventWithTicketType(['price' => 0]);
        $paid = Order::factory()->for($user)->for($event)->paid()->create();
        OrderItem::factory()->for($paid)->for($free)->create(['quantity' => 10]);

        pressProceedToPayment($user, $event, [$free->id => 1])->assertSee('1人あたり合計10枚まで');

        expect(Order::count())->toBe(1);
    });
});

describe('pressing the button many times', function () {
    test('stops after five presses in a minute and creates no more orders', function () {
        $user = User::factory()->create();
        [$event, $ticketType] = eventWithTicketType();
        RateLimiter::clear("purchase:{$user->id}");

        $this->mock(CheckoutService::class, function ($mock) {
            $mock->allows('expireSession');
            $mock->allows('createSession')->andReturn('https://checkout.stripe.com/c/pay/cs_test_new');
        });

        foreach (range(1, 5) as $press) {
            pressProceedToPayment($user, $event, [$ticketType->id => 1])->assertRedirect();
        }

        pressProceedToPayment($user, $event, [$ticketType->id => 1])
            ->assertSee('操作が多すぎます')
            ->assertNoRedirect();

        expect(Order::count())->toBe(5);
    });

    test('allows the button again after a minute', function () {
        $user = User::factory()->create();
        [$event, $ticketType] = eventWithTicketType();

        $this->mock(CheckoutService::class, function ($mock) {
            $mock->allows('expireSession');
            $mock->allows('createSession')->andReturn('https://checkout.stripe.com/c/pay/cs_test_new');
        });

        foreach (range(1, 5) as $press) {
            pressProceedToPayment($user, $event, [$ticketType->id => 1]);
        }

        $this->travel(61)->seconds();

        pressProceedToPayment($user, $event, [$ticketType->id => 1])->assertRedirect();
    });

    test('counts the presses of each person separately', function () {
        [$event, $ticketType] = eventWithTicketType();
        $busy = User::factory()->create();
        $other = User::factory()->create();

        $this->mock(CheckoutService::class, function ($mock) {
            $mock->allows('expireSession');
            $mock->allows('createSession')->andReturn('https://checkout.stripe.com/c/pay/cs_test_new');
        });

        foreach (range(1, 6) as $press) {
            pressProceedToPayment($busy, $event, [$ticketType->id => 1]);
        }

        pressProceedToPayment($other, $event, [$ticketType->id => 1])->assertRedirect();
    });
});
