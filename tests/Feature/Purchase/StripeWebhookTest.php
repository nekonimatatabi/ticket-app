<?php

use App\Enums\OrderStatus;
use App\Mail\OrderPaidMail;
use App\Models\Ticket;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

test('marks the order as paid and issues one ticket per item quantity for a correctly signed event', function () {
    $order = pendingOrder([2, 3]);
    $payload = sessionCompletedPayload($order);

    postWebhook($payload, stripeSignature($payload))->assertOk();

    expect($order->fresh())
        ->status->toBe(OrderStatus::Paid)
        ->stripe_payment_intent_id->toBe('pi_test_123')
        ->paid_at->not->toBeNull()
        ->and(Ticket::count())->toBe(5)
        ->and(Ticket::query()->distinct()->count('code'))->toBe(5)
        ->and(Ticket::query()->whereHas('orderItem', fn ($items) => $items->where('quantity', 3))->count())->toBe(3);
});

test('issues ULID codes for the tickets', function () {
    $order = pendingOrder([1]);
    $payload = sessionCompletedPayload($order);

    postWebhook($payload, stripeSignature($payload))->assertOk();

    expect(Ticket::sole()->code)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
});

test('does not need a CSRF token', function () {
    $order = pendingOrder([1]);
    $payload = sessionCompletedPayload($order);

    postWebhook($payload, stripeSignature($payload))->assertSuccessful();
});

test('returns 400 and changes nothing when the signature is not valid', function (string $kind) {
    $order = pendingOrder([2]);
    $payload = sessionCompletedPayload($order);

    $signature = match ($kind) {
        'signed with another secret' => stripeSignature($payload, 'whsec_other'),
        'payload was changed after signing' => stripeSignature(sessionCompletedPayload($order, ['payment_intent' => 'pi_other'])),
        'older than the tolerance' => stripeSignature($payload, timestamp: time() - 3600),
        'garbage' => 'not-a-signature',
        'missing header' => null,
    };

    postWebhook($payload, $signature)->assertStatus(400);

    expect($order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and(Ticket::count())->toBe(0);
})->with([
    'signed with another secret',
    'payload was changed after signing',
    'older than the tolerance',
    'garbage',
    'missing header',
]);

test('returns 400 when the body is not JSON even if it is signed', function () {
    $payload = 'this is not json';

    postWebhook($payload, stripeSignature($payload))->assertStatus(400);
});

test('does not verify anything and fails loudly when the webhook secret is not set', function () {
    config(['services.stripe.webhook_secret' => '']);

    $order = pendingOrder([1]);
    $payload = sessionCompletedPayload($order);

    postWebhook($payload, stripeSignature($payload, ''))->assertStatus(500);

    expect($order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and(Ticket::count())->toBe(0);
});

test('does not issue the tickets twice when the same event arrives twice', function () {
    $order = pendingOrder([2]);
    $payload = sessionCompletedPayload($order);

    postWebhook($payload, stripeSignature($payload))->assertOk();
    $paidAt = $order->fresh()->paid_at;

    $this->travel(5)->minutes();
    postWebhook($payload, stripeSignature($payload))->assertOk();

    expect(Ticket::count())->toBe(2)
        ->and($order->fresh()->paid_at->equalTo($paidAt))->toBeTrue();
});

test('does not mark the order as paid when the session is not paid', function () {
    $order = pendingOrder([2]);
    $payload = sessionCompletedPayload($order, ['payment_status' => 'unpaid']);

    postWebhook($payload, stripeSignature($payload))->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and(Ticket::count())->toBe(0);
});

test('returns 200 and does nothing for an order that does not exist', function () {
    $order = pendingOrder([1]);
    $payload = sessionCompletedPayload($order, ['metadata' => ['order_id' => '999999']]);

    postWebhook($payload, stripeSignature($payload))->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
});

test('returns 200 and does nothing for an event type that is not handled', function () {
    $order = pendingOrder([1]);
    $payload = sessionCompletedPayload($order, type: 'charge.refunded');

    postWebhook($payload, stripeSignature($payload))->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and(Ticket::count())->toBe(0);
});

test('leaves a cancelled order cancelled and logs that a payment came in', function () {
    Log::spy();

    $order = pendingOrder([2]);
    $order->forceFill(['status' => OrderStatus::Cancelled])->save();
    $payload = sessionCompletedPayload($order);

    postWebhook($payload, stripeSignature($payload))->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and(Ticket::count())->toBe(0);

    Log::shouldHaveReceived('warning')->once();
});

test('marks the order as paid and leaves the payment intent empty when the event has no payment intent id', function (mixed $paymentIntent) {
    $order = pendingOrder([2]);
    $payload = sessionCompletedPayload($order, ['payment_intent' => $paymentIntent]);

    postWebhook($payload, stripeSignature($payload))->assertOk();

    expect($order->fresh())
        ->status->toBe(OrderStatus::Paid)
        ->stripe_payment_intent_id->toBeNull()
        ->and(Ticket::count())->toBe(2);
})->with([
    'null' => null,
    'an expanded object' => [['id' => 'pi_test_123']],
]);

test('only touches the order named in the event and leaves other pending orders alone', function () {
    $target = pendingOrder([2]);
    $other = pendingOrder([3]);
    $payload = sessionCompletedPayload($target);

    postWebhook($payload, stripeSignature($payload))->assertOk();

    expect($target->fresh()->status)->toBe(OrderStatus::Paid)
        ->and($other->fresh())
        ->status->toBe(OrderStatus::Pending)
        ->stripe_payment_intent_id->toBeNull()
        ->and(Ticket::count())->toBe(2)
        ->and(Ticket::query()->whereIn('order_item_id', $other->items()->pluck('id'))->count())->toBe(0);
});

test('returns 400 when the signature timestamp is too far in the future', function () {
    $order = pendingOrder([1]);
    $payload = sessionCompletedPayload($order);

    postWebhook($payload, stripeSignature($payload, timestamp: time() + 3600))->assertStatus(400);

    expect($order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and(Ticket::count())->toBe(0);
});

test('accepts a header with several v1 signatures as long as one of them is valid', function () {
    $order = pendingOrder([1]);
    $payload = sessionCompletedPayload($order);
    $timestamp = time();
    $old = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_old');
    $header = "t={$timestamp},v1={$old},v1=".hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_dummy');

    postWebhook($payload, $header)->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::Paid)
        ->and(Ticket::count())->toBe(1);
});

test('returns 400 when none of the v1 signatures in the header is valid', function () {
    $order = pendingOrder([1]);
    $payload = sessionCompletedPayload($order);
    $timestamp = time();
    $header = "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_old').',v1='.hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_other');

    postWebhook($payload, $header)->assertStatus(400);

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
});

test('leaves a refunded order as it is without logging a warning', function () {
    Log::spy();

    $order = pendingOrder([2]);
    $order->forceFill(['status' => OrderStatus::Refunded])->save();
    $payload = sessionCompletedPayload($order);

    postWebhook($payload, stripeSignature($payload))->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::Refunded)
        ->and(Ticket::count())->toBe(0);

    Log::shouldNotHaveReceived('warning');
});

test('does not mark the order as paid when the session does not belong to it', function (string $kind) {
    Log::spy();

    $order = pendingOrder([2]);
    $other = pendingOrder([1]);

    $overrides = match ($kind) {
        'a session id nobody has' => ['id' => 'cs_test_from_another_environment'],
        'the session of another order' => ['id' => $other->stripe_checkout_session_id],
        'an order id that does not match the session' => ['metadata' => ['order_id' => (string) $other->id]],
        'another amount' => ['amount_total' => $order->total_amount + 1],
        'another currency' => ['currency' => 'usd'],
        'no amount' => ['amount_total' => null],
    };
    $payload = sessionCompletedPayload($order, $overrides);

    postWebhook($payload, stripeSignature($payload))->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and($other->fresh()->status)->toBe(OrderStatus::Pending)
        ->and(Ticket::count())->toBe(0);

    Log::shouldHaveReceived('warning')->once();
})->with([
    'a session id nobody has',
    'the session of another order',
    'an order id that does not match the session',
    'another amount',
    'another currency',
    'no amount',
]);

test('finds the order by the session id and not by the order id in the metadata alone', function () {
    $order = pendingOrder([2]);
    $payload = sessionCompletedPayload($order, ['metadata' => ['order_id' => (string) $order->id]]);

    postWebhook($payload, stripeSignature($payload))->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::Paid);
});

describe('payment methods that are paid later (convenience store payment and the like)', function () {
    test('keeps the order pending when the session is completed but not paid yet, and confirms it when the money arrives', function () {
        Mail::fake();
        $order = pendingOrder([2]);

        $unpaid = sessionCompletedPayload($order, ['payment_status' => 'unpaid']);
        postWebhook($unpaid, stripeSignature($unpaid))->assertOk();

        expect($order->fresh()->status)->toBe(OrderStatus::Pending)
            ->and(Ticket::count())->toBe(0);
        Mail::assertNothingSent();

        $paid = sessionCompletedPayload($order, [], 'checkout.session.async_payment_succeeded');
        postWebhook($paid, stripeSignature($paid))->assertOk();

        expect($order->fresh())
            ->status->toBe(OrderStatus::Paid)
            ->stripe_payment_intent_id->toBe('pi_test_123')
            ->and(Ticket::count())->toBe(2);
        Mail::assertSent(OrderPaidMail::class, 1);
    });

    test('does not issue the tickets twice when the money notification arrives twice', function () {
        $order = pendingOrder([2]);
        $payload = sessionCompletedPayload($order, [], 'checkout.session.async_payment_succeeded');

        postWebhook($payload, stripeSignature($payload))->assertOk();
        postWebhook($payload, stripeSignature($payload))->assertOk();

        expect(Ticket::count())->toBe(2);
    });

    test('does not confirm on a money notification that does not match the order', function (array $overrides) {
        $order = pendingOrder([2]);
        $payload = sessionCompletedPayload($order, $overrides, 'checkout.session.async_payment_succeeded');

        postWebhook($payload, stripeSignature($payload))->assertOk();

        expect($order->fresh()->status)->toBe(OrderStatus::Pending)
            ->and(Ticket::count())->toBe(0);
    })->with([
        'still unpaid' => [['payment_status' => 'unpaid']],
        'another amount' => [['amount_total' => 1]],
        'another currency' => [['currency' => 'usd']],
        'a session id nobody has' => [['id' => 'cs_test_from_another_environment']],
    ]);

    test('cancels the order and gives the tickets back when the payment fails', function () {
        $order = pendingOrder([3]);
        $ticketType = $order->items()->sole()->ticketType;
        $ticketType->forceFill(['capacity' => 5])->save();

        expect($ticketType->fresh()->remaining())->toBe(2);

        $payload = sessionCompletedPayload($order, ['payment_status' => 'unpaid'], 'checkout.session.async_payment_failed');
        postWebhook($payload, stripeSignature($payload))->assertOk();

        expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
            ->and(Ticket::count())->toBe(0)
            ->and($ticketType->fresh()->remaining())->toBe(5);
    });

    test('does not cancel an order that is already paid when a failure notification arrives', function () {
        $order = pendingOrder([2]);
        $paid = sessionCompletedPayload($order);
        postWebhook($paid, stripeSignature($paid))->assertOk();

        $failed = sessionCompletedPayload($order, ['payment_status' => 'unpaid'], 'checkout.session.async_payment_failed');
        postWebhook($failed, stripeSignature($failed))->assertOk();

        expect($order->fresh()->status)->toBe(OrderStatus::Paid)
            ->and(Ticket::count())->toBe(2);
    });

    test('ignores a failure notification for a session nobody has', function () {
        $order = pendingOrder([2]);
        $payload = sessionCompletedPayload($order, ['id' => 'cs_test_from_another_environment'], 'checkout.session.async_payment_failed');

        postWebhook($payload, stripeSignature($payload))->assertOk();

        expect($order->fresh()->status)->toBe(OrderStatus::Pending);
    });

    test('rejects an unsigned money notification', function () {
        $order = pendingOrder([2]);
        $payload = sessionCompletedPayload($order, [], 'checkout.session.async_payment_succeeded');

        postWebhook($payload, stripeSignature($payload, 'whsec_other'))->assertStatus(400);

        expect($order->fresh()->status)->toBe(OrderStatus::Pending)
            ->and(Ticket::count())->toBe(0);
    });
});

describe('a checkout page that expired without being paid', function () {
    test('cancels the pending order and gives the tickets back', function () {
        $order = pendingOrder([3]);
        $ticketType = $order->items()->sole()->ticketType;
        $ticketType->forceFill(['capacity' => 5])->save();

        $payload = sessionCompletedPayload($order, ['payment_status' => 'unpaid', 'status' => 'expired'], 'checkout.session.expired');
        postWebhook($payload, stripeSignature($payload))->assertOk();

        expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
            ->and($ticketType->fresh()->remaining())->toBe(5)
            ->and(Ticket::count())->toBe(0);
    });

    test('does not cancel an order that is already paid', function () {
        $order = pendingOrder([2]);
        $paid = sessionCompletedPayload($order);
        postWebhook($paid, stripeSignature($paid))->assertOk();

        $expired = sessionCompletedPayload($order, ['payment_status' => 'unpaid'], 'checkout.session.expired');
        postWebhook($expired, stripeSignature($expired))->assertOk();

        expect($order->fresh()->status)->toBe(OrderStatus::Paid)
            ->and(Ticket::count())->toBe(2);
    });

    test('ignores a session that belongs to no order and an unsigned notification', function () {
        $order = pendingOrder([2]);

        $unknown = sessionCompletedPayload($order, ['id' => 'cs_test_from_another_environment'], 'checkout.session.expired');
        postWebhook($unknown, stripeSignature($unknown))->assertOk();

        $unsigned = sessionCompletedPayload($order, [], 'checkout.session.expired');
        postWebhook($unsigned, stripeSignature($unsigned, 'whsec_other'))->assertStatus(400);

        expect($order->fresh()->status)->toBe(OrderStatus::Pending);
    });
});
