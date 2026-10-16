<?php

use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Services\CheckoutService;
use App\Services\OrderService;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * Stripe に通信せず、送るはずだったリクエストを記録して、決まった応答を返す HTTP クライアント。
 */
function fakeStripeHttpClient(array $response): ClientInterface
{
    $client = new class implements ClientInterface
    {
        /** @var array<int, array{method: string, url: string, params: mixed}> */
        public array $requests = [];

        public array $response = [];

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            $this->requests[] = ['method' => $method, 'url' => $absUrl, 'params' => $params];

            return [json_encode($this->response), 200, []];
        }
    };

    $client->response = $response;
    ApiRequestor::setHttpClient($client);

    return $client;
}

afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

test('creates a JPY checkout session from the order items and saves the session id', function () {
    $this->freezeTime();

    $event = Event::factory()->published()->create(['title' => '春のマルシェ']);
    $order = Order::factory()->for($event)->create(['total_amount' => 7500]);
    OrderItem::factory()->for($order)->for(TicketType::factory()->for($event)->create(['name' => '一般']))
        ->create(['quantity' => 2, 'unit_price' => 3000]);
    OrderItem::factory()->for($order)->for(TicketType::factory()->for($event)->create(['name' => '学生']))
        ->create(['quantity' => 1, 'unit_price' => 1500]);

    $client = fakeStripeHttpClient([
        'id' => 'cs_test_123',
        'object' => 'checkout.session',
        'url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
    ]);

    $url = app(CheckoutService::class)->createSession($order);

    expect($url)->toBe('https://checkout.stripe.com/c/pay/cs_test_123')
        ->and($order->fresh()->stripe_checkout_session_id)->toBe('cs_test_123')
        ->and($client->requests)->toHaveCount(1);

    $request = $client->requests[0];
    $params = $request['params'];

    expect($request['method'])->toBe('post')
        ->and($request['url'])->toEndWith('/v1/checkout/sessions')
        ->and($params['mode'])->toBe('payment')
        ->and($params['managed_payments'])->toBe(['enabled' => 'false'])
        ->and($params['expires_at'])->toBe(now()->addMinutes(OrderService::CHECKOUT_MINUTES)->getTimestamp())
        ->and($params['metadata']['order_id'])->toBe((string) $order->id)
        ->and($params['success_url'])->toBe(route('orders.complete', $order).'?session_id={CHECKOUT_SESSION_ID}')
        ->and($params['cancel_url'])->toBe(route('orders.cancel', $order))
        ->and($params['line_items'])->toBe([
            [
                'price_data' => ['currency' => 'jpy', 'unit_amount' => 3000, 'product_data' => ['name' => '春のマルシェ 一般']],
                'quantity' => 2,
            ],
            [
                'price_data' => ['currency' => 'jpy', 'unit_amount' => 1500, 'product_data' => ['name' => '春のマルシェ 学生']],
                'quantity' => 1,
            ],
        ]);
});
