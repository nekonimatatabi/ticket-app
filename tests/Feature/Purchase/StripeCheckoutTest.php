<?php

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\CheckoutService;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;
use Stripe\StripeClient;

function proceedToStripeCheckout(User $user, Event $event, array $quantities): Testable
{
    session()->put("purchase.{$event->id}", $quantities);

    return Livewire::actingAs($user)
        ->test('pages::events.purchase', ['event' => $event])
        ->call('proceedToPayment');
}

test('creates a checkout session for the pending order and redirects to the Stripe checkout page', function () {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create(['price' => 3000]);

    $this->mock(CheckoutService::class)
        ->expects('createSession')
        ->withArgs(fn (Order $order) => $order->status === OrderStatus::Pending && $order->total_amount === 6000)
        ->andReturn('https://checkout.stripe.com/c/pay/cs_test_123');

    proceedToStripeCheckout(User::factory()->create(), $event, [$ticketType->id => 2])
        ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_123');

    expect(Order::sole()->status)->toBe(OrderStatus::Pending);
});

test('skips Stripe for a free order, marks it as paid, and goes to the completion page', function () {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create(['price' => 0]);

    $this->mock(CheckoutService::class)->shouldNotReceive('createSession');

    proceedToStripeCheckout(User::factory()->create(), $event, [$ticketType->id => 2])
        ->assertRedirect(route('orders.complete', Order::sole()));

    expect(Order::sole())
        ->status->toBe(OrderStatus::Paid)
        ->total_amount->toBe(0)
        ->stripe_payment_intent_id->toBeNull()
        ->paid_at->not->toBeNull()
        ->and(Ticket::count())->toBe(2);
});

test('cancels the order and shows a message when the Stripe checkout page cannot be opened', function () {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create(['price' => 3000, 'capacity' => 5]);

    $this->mock(CheckoutService::class)
        ->expects('createSession')
        ->andThrow(new ApiConnectionException('Could not connect to Stripe.'));

    proceedToStripeCheckout(User::factory()->create(), $event, [$ticketType->id => 5])
        ->assertSee('決済ページを開けませんでした。時間をおいてお試しください。')
        ->assertNoRedirect();

    expect(Order::sole()->status)->toBe(OrderStatus::Cancelled)
        ->and($ticketType->fresh()->remaining())->toBe(5);
});

test('passes the buyer, the event, and every item at the price of the order to createSession', function () {
    $buyer = User::factory()->create();
    $event = Event::factory()->published()->create();
    $general = TicketType::factory()->for($event)->create(['price' => 3000]);
    $student = TicketType::factory()->for($event)->create(['price' => 1500]);

    $this->mock(CheckoutService::class)
        ->expects('createSession')
        ->withArgs(function (Order $order) use ($buyer, $event, $general, $student) {
            $items = $order->items->mapWithKeys(fn ($item) => [$item->ticket_type_id => [$item->quantity, $item->unit_price]]);

            return $order->user_id === $buyer->id
                && $order->event_id === $event->id
                && $order->total_amount === 7500
                && $items->all() === [$general->id => [2, 3000], $student->id => [1, 1500]];
        })
        ->andReturn('https://checkout.stripe.com/c/pay/cs_test_123');

    proceedToStripeCheckout($buyer, $event, [$general->id => 2, $student->id => 1])
        ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_123');
});

test('cancels the order and shows the message for any Stripe API error', function (Throwable $exception) {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create(['price' => 3000, 'capacity' => 5]);

    $this->mock(CheckoutService::class)->expects('createSession')->andThrow($exception);

    proceedToStripeCheckout(User::factory()->create(), $event, [$ticketType->id => 2])
        ->assertSee('決済ページを開けませんでした。時間をおいてお試しください。')
        ->assertNoRedirect();

    expect(Order::sole())
        ->status->toBe(OrderStatus::Cancelled)
        ->stripe_checkout_session_id->toBeNull()
        ->and($ticketType->remaining())->toBe(5);
})->with([
    'invalid request' => fn () => new InvalidRequestException('Invalid request.'),
    'authentication' => fn () => new AuthenticationException('Invalid API key.'),
    'rate limit' => fn () => new RateLimitException('Too many requests.'),
]);

test('lets the buyer buy the same tickets again after the Stripe checkout page failed', function () {
    $buyer = User::factory()->create();
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create(['price' => 3000, 'capacity' => 5]);

    $this->mock(CheckoutService::class, function ($mock) {
        $mock->expects('createSession')->andThrow(new ApiConnectionException('Could not connect to Stripe.'));
        $mock->expects('createSession')->andReturn('https://checkout.stripe.com/c/pay/cs_test_retry');
    });

    proceedToStripeCheckout($buyer, $event, [$ticketType->id => 5])->assertNoRedirect();

    proceedToStripeCheckout($buyer, $event, [$ticketType->id => 5])
        ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_retry');

    expect(Order::query()->where('status', OrderStatus::Cancelled)->count())->toBe(1)
        ->and(Order::query()->where('status', OrderStatus::Pending)->count())->toBe(1)
        ->and($ticketType->remaining())->toBe(0);
});

test('keeps the chosen tickets after the Stripe checkout page failed so that the buyer can press the button again', function () {
    $buyer = User::factory()->create();
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create(['price' => 3000, 'capacity' => 5]);

    $this->mock(CheckoutService::class, function ($mock) {
        $mock->expects('createSession')->andThrow(new ApiConnectionException('Could not connect to Stripe.'));
        $mock->expects('createSession')->andReturn('https://checkout.stripe.com/c/pay/cs_test_retry');
    });

    $page = proceedToStripeCheckout($buyer, $event, [$ticketType->id => 2])->assertNoRedirect();

    expect(session("purchase.{$event->id}"))->toBe([$ticketType->id => 2]);

    $page->call('proceedToPayment')->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_retry');

    expect(session("purchase.{$event->id}"))->toBeNull();
});

test('does not call Stripe when the order cannot be created', function () {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create(['price' => 3000, 'capacity' => 1]);

    $this->mock(CheckoutService::class)->shouldNotReceive('createSession');

    proceedToStripeCheckout(User::factory()->create(), $event, [$ticketType->id => 2])
        ->assertNoRedirect();

    expect(Order::count())->toBe(0);
});

test('cancels the order and shows the message when the Stripe secret key is not set', function () {
    config(['services.stripe.secret' => '']);
    app()->forgetInstance(StripeClient::class);

    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create(['price' => 3000, 'capacity' => 5]);

    proceedToStripeCheckout(User::factory()->create(), $event, [$ticketType->id => 2])
        ->assertSee('決済ページを開けませんでした。時間をおいてお試しください。')
        ->assertNoRedirect();

    expect(Order::sole()->status)->toBe(OrderStatus::Cancelled)
        ->and($ticketType->fresh()->remaining())->toBe(5);
});
