<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\CheckoutService;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Stripe\Exception\SignatureVerificationException;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    /**
     * 処理する Webhook のイベント。これ以外は 200 を返して何もしない。
     *
     * @var list<string>
     */
    private const HANDLED_EVENTS = [
        'checkout.session.completed',
        'checkout.session.async_payment_succeeded',
        'checkout.session.async_payment_failed',
        'checkout.session.expired',
    ];

    /**
     * Stripe からの Webhook を受け取る。署名が正しくないものは 400 を返し、何も変えない。
     *
     * 扱うイベントは次の4つ。注文は Session の ID で探す。
     * - checkout.session.completed: 金額なども突き合わせたうえで、支払い済みなら paid にしてチケットを発行する
     *   （OrderService::confirmStripePayment）。コンビニ払いなどの後払い型は、この時点では未入金なので何もしない
     * - checkout.session.async_payment_succeeded: 後払い型の入金。上と同じ確定の処理をする
     * - checkout.session.async_payment_failed: 後払い型の支払い失敗。注文を取り消して枠を戻す
     * - checkout.session.expired: 決済ページが支払われないまま期限切れになった。注文を取り消して枠を戻す
     *   （タブを閉じて放置された注文を、自然に片付ける）
     * 支払い方法はダッシュボードの設定で決まり、API では「カードだけ」に絞れない。後から後払い型が有効になっても、
     * お金が入ったのにチケットが出ない、ということが起きないようにするため。
     * Stripe は 2xx 以外だと再送するので、対象外のイベントや見つからない注文、処理済みの注文にも 200 を返す。
     */
    public function __invoke(Request $request, CheckoutService $checkout, OrderService $orders): Response
    {
        try {
            $event = $checkout->constructWebhookEvent($request->getContent(), $request->header('Stripe-Signature'));
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return response('', 400);
        }

        if (! in_array($event->type, self::HANDLED_EVENTS, true)) {
            return response('', 200);
        }

        $session = $event->data->object;

        if (! $session instanceof Session) {
            return response('', 200);
        }

        $order = Order::query()->where('stripe_checkout_session_id', $session->id)->first();

        if ($order === null) {
            Log::warning('Webhook の Session に対応する注文がありません。', ['session_id' => $session->id]);

            return response('', 200);
        }

        if (in_array($event->type, ['checkout.session.async_payment_failed', 'checkout.session.expired'], true)) {
            $orders->cancel($order);

            return response('', 200);
        }

        if ($session->payment_status !== 'paid') {
            Log::info('入金待ちの決済方法です。入金の通知（async_payment_succeeded）を待ちます。', [
                'order_id' => $order->id,
                'session_id' => $session->id,
            ]);
        }

        $orders->confirmStripePayment($order, $session);

        return response('', 200);
    }
}
