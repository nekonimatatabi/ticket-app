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
     * Stripe からの Webhook を受け取る。署名が正しくないものは 400 を返し、何も変えない。
     *
     * 扱うイベントは checkout.session.completed だけ。注文は Session の ID で探し、金額なども突き合わせたうえで、
     * 支払い済みなら paid にしてチケットを発行する（OrderService::confirmStripePayment）。
     * Stripe は 2xx 以外だと再送するので、対象外のイベントや見つからない注文、処理済みの注文にも 200 を返す。
     */
    public function __invoke(Request $request, CheckoutService $checkout, OrderService $orders): Response
    {
        try {
            $event = $checkout->constructWebhookEvent($request->getContent(), $request->header('Stripe-Signature'));
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return response('', 400);
        }

        if ($event->type !== 'checkout.session.completed') {
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

        $orders->confirmStripePayment($order, $session);

        return response('', 200);
    }
}
