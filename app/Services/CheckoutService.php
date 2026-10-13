<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use RuntimeException;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

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
     * Session の有効期限（CHECKOUT_MINUTES）は、注文が枚数を押さえる時間（PENDING_MINUTES）より短くする。
     * 決済ページで払える間は、必ず枠が残っているようにするため。
     *
     * @throws ApiErrorException
     */
    public function createSession(Order $order): string
    {
        $order->loadMissing('event', 'items.ticketType');

        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'payment',
            // アカウントで既定で有効な Managed Payments は、商品ごとの税コード（tax_code）が無いと Session を作れない。
            // 商品を Stripe に登録しない（price_data を使う）この方式では、Session ごとに無効にする。
            'managed_payments' => ['enabled' => false],
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
            'expires_at' => now()->addMinutes(OrderService::CHECKOUT_MINUTES)->getTimestamp(),
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

    /**
     * Webhook のリクエストを、Stripe-Signature ヘッダーと署名シークレットで検証して、イベントを取り出す。
     *
     * 署名シークレットが未設定のまま検証すると、誰でも署名を偽造できてしまうので、検証せずに例外にする。
     *
     * @throws SignatureVerificationException 署名が合わない、またはヘッダーが無い
     * @throws UnexpectedValueException 本文が正しい JSON ではない
     * @throws RuntimeException 署名シークレットが未設定
     */
    public function constructWebhookEvent(string $payload, ?string $signature): Event
    {
        $secret = config('services.stripe.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('STRIPE_WEBHOOK_SECRET が設定されていません。');
        }

        return Webhook::constructEvent($payload, $signature ?? '', $secret);
    }
}
