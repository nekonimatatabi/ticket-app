<?php

use App\Enums\OrderStatus;
use App\Mail\OrderPaidMail;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\CheckoutService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

function sendWebhookFor(Order $order, array $sessionOverrides = []): void
{
    $payload = sessionCompletedPayload($order, $sessionOverrides);

    postWebhook($payload, stripeSignature($payload))->assertOk();
}

describe('sending the purchase mail', function () {
    test('sends one mail to the buyer when the webhook marks the order as paid', function () {
        Mail::fake();
        $order = pendingOrder([2]);

        sendWebhookFor($order);

        Mail::assertSent(OrderPaidMail::class, 1);
        Mail::assertSent(OrderPaidMail::class, fn (OrderPaidMail $mail) => $mail->hasTo($order->user->email) && $mail->order->is($order));
    });

    test('does not send the mail again when the same webhook arrives twice', function () {
        Mail::fake();
        $order = pendingOrder([2]);

        sendWebhookFor($order);
        sendWebhookFor($order);

        Mail::assertSent(OrderPaidMail::class, 1);
    });

    test('sends one mail when the completion page confirms the payment', function () {
        Mail::fake();
        $buyer = User::factory()->create();
        $order = pendingOrder([1], $buyer);

        $this->mock(CheckoutService::class)->expects('retrieveSession')->andReturn(stripeSession($order));

        $this->actingAs($buyer)
            ->get(route('orders.complete', ['order' => $order, 'session_id' => $order->stripe_checkout_session_id]))
            ->assertSee('購入が完了しました');

        Mail::assertSent(OrderPaidMail::class, 1);
    });

    test('sends only one mail in total when the completion page and the webhook both confirm the order', function () {
        Mail::fake();
        $buyer = User::factory()->create();
        $order = pendingOrder([1], $buyer);

        $this->partialMock(CheckoutService::class)->expects('retrieveSession')->once()->andReturn(stripeSession($order));

        $this->actingAs($buyer)
            ->get(route('orders.complete', ['order' => $order, 'session_id' => $order->stripe_checkout_session_id]))
            ->assertOk();
        sendWebhookFor($order);

        Mail::assertSent(OrderPaidMail::class, 1);
    });

    test('sends one mail for a free order that is marked as paid without Stripe', function () {
        Mail::fake();
        $buyer = User::factory()->create();
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create(['price' => 0]);
        session()->put("purchase.{$event->id}", [$ticketType->id => 2]);

        Livewire::actingAs($buyer)
            ->test('pages::events.purchase', ['event' => $event])
            ->call('proceedToPayment');

        Mail::assertSent(OrderPaidMail::class, 1);
        Mail::assertSent(OrderPaidMail::class, fn (OrderPaidMail $mail) => $mail->hasTo($buyer->email));
    });

    test('does not send a mail when the order is not confirmed', function (string $case) {
        Mail::fake();
        $order = pendingOrder([2]);

        match ($case) {
            'unpaid session' => sendWebhookFor($order, ['payment_status' => 'unpaid']),
            'another amount' => sendWebhookFor($order, ['amount_total' => $order->total_amount + 1]),
            'cancelled order' => (function () use ($order) {
                $order->forceFill(['status' => OrderStatus::Cancelled])->save();
                sendWebhookFor($order);
            })(),
            'invalid signature' => (function () use ($order) {
                $payload = sessionCompletedPayload($order);
                postWebhook($payload, stripeSignature($payload, 'whsec_other'))->assertStatus(400);
            })(),
        };

        Mail::assertNothingSent();
    })->with(['unpaid session', 'another amount', 'cancelled order', 'invalid signature']);

    test('keeps the order paid and answers 200 even when the mail cannot be sent', function () {
        Log::spy();
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('mail server is down'));
        $order = pendingOrder([2]);

        sendWebhookFor($order);

        expect($order->fresh()->status)->toBe(OrderStatus::Paid)
            ->and(Ticket::count())->toBe(2);

        Log::shouldHaveReceived('error')->once();
    });
});

describe('the contents of the purchase mail', function () {
    function paidOrderForMail(): Order
    {
        $buyer = User::factory()->create(['name' => '山田 花子']);
        $event = Event::factory()->published()->create([
            'title' => '春のマルシェ',
            'venue' => '八幡平市民ホール',
            'starts_at' => '2030-05-03 10:00:00',
        ]);
        $order = Order::factory()->for($buyer)->for($event)->paid()->create(['total_amount' => 7500]);
        OrderItem::factory()->for($order)->for(TicketType::factory()->for($event)->create(['name' => '一般']))
            ->create(['quantity' => 2, 'unit_price' => 3000]);
        OrderItem::factory()->for($order)->for(TicketType::factory()->for($event)->create(['name' => '学生']))
            ->create(['quantity' => 1, 'unit_price' => 1500]);

        return $order;
    }

    test('has the subject with the event name', function () {
        $mail = new OrderPaidMail(paidOrderForMail());

        $mail->assertHasSubject('【まちチケ】チケットの購入が完了しました（春のマルシェ）');
    });

    test('shows the event, the items with their subtotals, the total, and the link to the order', function () {
        $order = paidOrderForMail();
        $mail = new OrderPaidMail($order);

        $mail->assertSeeInHtml('山田 花子');
        $mail->assertSeeInHtml('春のマルシェ');
        $mail->assertSeeInHtml($order->event->starts_at->isoFormat('M月D日(ddd) HH:mm'));
        $mail->assertSeeInHtml('八幡平市民ホール');
        $mail->assertSeeInHtml('一般');
        $mail->assertSeeInHtml('2枚');
        $mail->assertSeeInHtml('6,000円');
        $mail->assertSeeInHtml('学生');
        $mail->assertSeeInHtml('1,500円');
        $mail->assertSeeInHtml('7,500円');
        $mail->assertSeeInHtml(route('orders.show', $order));
    });
});

describe('edge cases of the purchase mail', function () {
    test('is sent right away, not queued', function () {
        Mail::fake();
        $order = pendingOrder([1]);

        sendWebhookFor($order);

        Mail::assertNotQueued(OrderPaidMail::class);
        Mail::assertSent(OrderPaidMail::class, 1);
        expect(new OrderPaidMail($order))->not->toBeInstanceOf(ShouldQueue::class);
    });

    test('escapes symbols in the event name', function () {
        $buyer = User::factory()->create();
        $event = Event::factory()->published()->create(['title' => 'A & B <script>alert(1)</script>']);
        $order = Order::factory()->for($buyer)->for($event)->paid()->create(['total_amount' => 0]);
        OrderItem::factory()->for($order)->for(TicketType::factory()->for($event)->create())->create(['quantity' => 1, 'unit_price' => 0]);

        $mail = new OrderPaidMail($order);

        $mail->assertSeeInHtml('A &amp; B &lt;script&gt;', false);
        $mail->assertDontSeeInHtml('<script>', false);
    });

    test('shows a free ticket as 0 yen', function () {
        $buyer = User::factory()->create();
        $event = Event::factory()->published()->create();
        $order = Order::factory()->for($buyer)->for($event)->paid()->create(['total_amount' => 0]);
        OrderItem::factory()->for($order)->for(TicketType::factory()->for($event)->create(['name' => '無料招待']))
            ->create(['quantity' => 3, 'unit_price' => 0]);

        $mail = new OrderPaidMail($order);

        $mail->assertSeeInHtml('無料招待');
        $mail->assertSeeInHtml('3枚');
        $mail->assertSeeInHtml('0円');
    });

    test('is not affected by another pending order of the same buyer', function () {
        Mail::fake();
        $buyer = User::factory()->create();
        $order = pendingOrder([1], $buyer);
        $other = pendingOrder([2], $buyer);

        sendWebhookFor($order);

        Mail::assertSent(OrderPaidMail::class, 1);
        Mail::assertSent(OrderPaidMail::class, fn (OrderPaidMail $mail) => $mail->order->is($order));
        expect($other->fresh()->status)->toBe(OrderStatus::Pending);
    });
});

describe('text typed by users in the purchase mail', function () {
    function renderedMailWith(array $event = [], array $ticketType = [], array $user = []): string
    {
        $buyer = User::factory()->create($user);
        $event = Event::factory()->published()->create($event);
        $order = Order::factory()->for($buyer)->for($event)->paid()->create(['total_amount' => 3000]);
        OrderItem::factory()->for($order)->for(TicketType::factory()->for($event)->create($ticketType))
            ->create(['quantity' => 1, 'unit_price' => 3000]);

        return (new OrderPaidMail($order))->render();
    }

    test('does not turn markdown links in the text into links', function (string $field) {
        $link = '[公式サイト](https://evil.example/login)';

        $html = match ($field) {
            'event title' => renderedMailWith(event: ['title' => $link]),
            'venue' => renderedMailWith(event: ['venue' => $link]),
            'ticket type name' => renderedMailWith(ticketType: ['name' => $link]),
            'buyer name' => renderedMailWith(user: ['name' => $link]),
        };

        expect($html)->not->toContain('href="https://evil.example')
            ->and($html)->toContain('公式サイト');
    })->with(['event title', 'venue', 'ticket type name', 'buyer name']);

    test('keeps the columns of the items table when a ticket type name has a pipe or a line break', function (string $name) {
        $html = renderedMailWith(ticketType: ['name' => $name]);

        preg_match('/<tr>(?:(?!<\/tr>).)*一般(?:(?!<\/tr>).)*<\/tr>/s', $html, $row);

        expect($row)->not->toBeEmpty()
            ->and($row[0])->toContain('999')
            ->and($row[0])->toContain('1枚')
            ->and($row[0])->toContain('3,000円')
            ->and(substr_count($row[0], '<td'))->toBe(3);
    })->with([
        'a pipe' => '一般 | 999',
        'a line break' => "一般\n| 999",
    ]);

    test('shows a raw URL in the event title as plain text and not as a link', function () {
        $html = renderedMailWith(event: ['title' => 'https://evil.example/login']);

        expect($html)->not->toContain('href="https://evil.example');
    });

    test('still shows normal link to the order', function () {
        $buyer = User::factory()->create();
        $order = Order::factory()->for($buyer)->paid()->create();
        OrderItem::factory()->for($order)->create();

        expect((new OrderPaidMail($order))->render())->toContain('href="'.route('orders.show', $order).'"');
    });
});
