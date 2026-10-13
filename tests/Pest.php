<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Stripe\Checkout\Session;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * 支払い待ちの注文を、明細つきで作る。Stripe の Checkout Session の ID も持たせる（注文ごとに別の値）。
 *
 * @param  array<int, int>  $quantities  明細ごとの枚数。[2, 3] なら2明細
 */
function pendingOrder(array $quantities = [1], ?User $buyer = null): Order
{
    $order = Order::factory()->create([
        'user_id' => $buyer?->id ?? User::factory(),
        'stripe_checkout_session_id' => 'cs_test_'.Str::lower(Str::random(12)),
    ]);

    foreach ($quantities as $quantity) {
        OrderItem::factory()->for($order)->create(['quantity' => $quantity]);
    }

    return $order;
}

/**
 * 注文に対応する、支払い済みの Checkout Session の中身。ID・金額・通貨・metadata は注文から作るので、そのまま照合を通る。
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function stripeSessionData(Order $order, array $overrides = []): array
{
    return array_merge([
        'id' => $order->stripe_checkout_session_id,
        'object' => 'checkout.session',
        'payment_status' => 'paid',
        'payment_intent' => 'pi_test_123',
        'amount_total' => $order->total_amount,
        'currency' => 'jpy',
        'metadata' => ['order_id' => (string) $order->id],
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function stripeSession(Order $order, array $overrides = []): Session
{
    return Session::constructFrom(stripeSessionData($order, $overrides));
}

/**
 * phpunit.xml のダミーの署名シークレットで、Stripe と同じ形式の Stripe-Signature ヘッダー（HMAC-SHA256）を作る。
 */
function stripeSignature(string $payload, string $secret = 'whsec_dummy', ?int $timestamp = null): string
{
    $timestamp ??= time();

    return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
}

/**
 * @param  array<string, mixed>  $sessionOverrides
 */
function sessionCompletedPayload(Order $order, array $sessionOverrides = [], string $type = 'checkout.session.completed'): string
{
    return json_encode([
        'id' => 'evt_test_123',
        'object' => 'event',
        'type' => $type,
        'data' => ['object' => stripeSessionData($order, $sessionOverrides)],
    ], JSON_THROW_ON_ERROR);
}

function postWebhook(string $payload, ?string $signature): TestResponse
{
    $headers = ['Content-Type' => 'application/json'];

    if ($signature !== null) {
        $headers['Stripe-Signature'] = $signature;
    }

    return test()->call('POST', route('stripe.webhook'), [], [], [], test()->transformHeadersToServerVars($headers), $payload);
}
