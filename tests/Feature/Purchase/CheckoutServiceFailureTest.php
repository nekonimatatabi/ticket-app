<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\CheckoutService;
use Stripe\ApiRequestor;
use Stripe\Exception\InvalidRequestException;
use Stripe\HttpClient\ClientInterface;

afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

test('throws the Stripe error and does not save a session id when Stripe rejects the request', function () {
    ApiRequestor::setHttpClient(new class implements ClientInterface
    {
        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            return [json_encode(['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid request.']]), 400, []];
        }
    });

    $order = Order::factory()->create(['total_amount' => 3000]);
    OrderItem::factory()->for($order)->create(['quantity' => 1, 'unit_price' => 3000]);

    expect(fn () => app(CheckoutService::class)->createSession($order))->toThrow(InvalidRequestException::class);

    expect($order->fresh()->stripe_checkout_session_id)->toBeNull();
});
