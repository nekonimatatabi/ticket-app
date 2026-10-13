<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Stripe Checkout とのやり取りをまとめる。テストではこのクラスをモックする。
 */
class CheckoutService
{
    public function __construct(private StripeClient $stripe) {}

    /**
     * 注文の Checkout Session を作り、決済ページの URL を返す。作った Session の ID は注文に保存する。
     *
     * 金額は注文時点の単価（order_items.unit_price）を使い、Stripe 側に商品は登録しない。
     * Session の有効期限は、注文が枚数を押さえる時間（PENDING_MINUTES）と同じにする。
     *
     * @throws ApiErrorException
     */
    public function createSession(Order $order): string
    {
        $order->loadMissing('event', 'items.ticketType');

        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => $order->items->map(fn (OrderItem $item): array => [
                'price_data' => [
                    'currency' => 'jpy',
                    'unit_amount' => $item->unit_price,
                    'product_data' => [
                        'name' => "{$order->event->title} {$item->ticketType->name}",
                    ],
                ],
                'quantity' => $item->quantity,
            ])->all(),
            'expires_at' => now()->addMinutes(OrderService::PENDING_MINUTES)->getTimestamp(),
            'metadata' => [
                'order_id' => (string) $order->id,
            ],
            // route() の query だと {CHECKOUT_SESSION_ID} の波括弧がエンコードされるため、文字列でつなぐ
            'success_url' => route('orders.complete', $order).'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('orders.cancel', $order),
        ]);

        $order->stripe_checkout_session_id = $session->id;
        $order->save();

        return (string) $session->url;
    }

    /**
     * Checkout Session を失効させ、決済ページで支払えないようにする。
     *
     * すでに支払い済み・失効済みの Session は失効できず、例外になる。
     *
     * @throws ApiErrorException
     */
    public function expireSession(string $id): void
    {
        $this->stripe->checkout->sessions->expire($id);
    }

    /**
     * Checkout Session を取得する。支払いが済んだかの確認に使う。
     *
     * @throws ApiErrorException
     */
    public function retrieveSession(string $id): Session
    {
        return $this->stripe->checkout->sessions->retrieve($id);
    }
}
