<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\PurchaseException;
use App\Mail\OrderPaidMail;
use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Stripe\Checkout\Session;
use Throwable;

class OrderService
{
    /**
     * 支払い待ちの注文が枚数を押さえておく時間（分）。Stripe Checkout Session の有効期限と同じ値にする。
     * Stripe の expires_at は作成から30分以上先でなければ指定できないため、30分より短くはできない。
     */
    public const PENDING_MINUTES = 30;

    /**
     * 1つの券種で、1回の注文に入れられる最大枚数。
     */
    public const MAX_QUANTITY = 10;

    /**
     * 支払い待ち（pending）の注文と明細を作る。
     *
     * 画面から渡る値は書き換えられるので、枚数・券種・販売期間・残り枚数は、ここで必ず確認する。
     * 金額は受け取らず、DB の券種の価格から計算する。確認で1つでも駄目なら、何も作らない。
     *
     * @param  array<int|string, mixed>  $quantities  [券種ID => 枚数]。枚数が 0 の券種は「買わない」として無視する
     *
     * @throws PurchaseException
     */
    public function createOrder(User $user, Event $event, array $quantities): Order
    {
        return DB::transaction(function () use ($user, $event, $quantities): Order {
            if (! $event->isPublished()) {
                throw PurchaseException::eventNotOnSale();
            }

            $selected = $this->selectedQuantities($quantities);
            $ticketTypes = $this->findTicketTypes($event, array_keys($selected));

            $totalAmount = 0;

            foreach ($selected as $ticketTypeId => $quantity) {
                $ticketType = $ticketTypes[$ticketTypeId];

                if (! $ticketType->isOnSale()) {
                    throw PurchaseException::notOnSale($ticketType);
                }

                if ($ticketType->remaining() < $quantity) {
                    throw PurchaseException::notEnoughRemaining($ticketType);
                }

                $totalAmount += $ticketType->price * $quantity;
            }

            $order = new Order;
            $order->user_id = $user->id;
            $order->event_id = $event->id;
            $order->status = OrderStatus::Pending;
            $order->total_amount = $totalAmount;
            $order->save();

            foreach ($selected as $ticketTypeId => $quantity) {
                $order->items()->create([
                    'ticket_type_id' => $ticketTypeId,
                    'quantity' => $quantity,
                    'unit_price' => $ticketTypes[$ticketTypeId]->price,
                ]);
            }

            return $order->load('items');
        });
    }

    /**
     * Stripe の Checkout Session が、この注文の支払い済みの Session であることを確かめて、注文を確定する。
     *
     * 署名が正しくても、それは「Stripe から届いた」ことしか保証しない。同じ Stripe アカウントを使う別の環境の
     * Session が混ざっても確定しないよう、Session の ID・metadata の注文 ID・金額・通貨を注文と突き合わせる。
     * 合わないときは何もせず、ログに残す。
     *
     * @return bool 今回の呼び出しで paid にできたら true。支払い前・不一致・処理済みなら false
     */
    public function confirmStripePayment(Order $order, Session $session): bool
    {
        if ($session->payment_status !== 'paid') {
            return false;
        }

        $matches = $order->stripe_checkout_session_id !== null
            && $session->id === $order->stripe_checkout_session_id
            && (string) ($session->metadata->order_id ?? '') === (string) $order->id
            && $session->amount_total === $order->total_amount
            && $session->currency === 'jpy';

        if (! $matches) {
            Log::warning('Stripe の Session が注文と一致しないため、確定しませんでした。', [
                'order_id' => $order->id,
                'session_id' => $session->id,
                'amount_total' => $session->amount_total,
                'currency' => $session->currency,
            ]);

            return false;
        }

        $paymentIntentId = is_string($session->payment_intent) ? $session->payment_intent : null;

        return $this->markAsPaid($order, $paymentIntentId);
    }

    /**
     * 支払い待ち（pending）の注文を支払い済み（paid）にして、明細の枚数分のチケットを発行する。
     *
     * Webhook と完了ページのどちらからも呼ばれ、同時に来ることもある。そのため「status が pending の行だけ更新する」
     * 条件付き UPDATE で排他にし、更新できた1回だけがチケットを作る。更新とチケットの発行は1つのトランザクションにする。
     * 確定できた1回だけ、購入完了メールを送る（処理済みでは送らない）。
     * 取り消し済み（cancelled）の注文には何もしない。支払いが入っていた場合は、返金の判断ができるようログに残す。
     *
     * @return bool 今回の呼び出しで paid にできたら true。既に処理済み（paid など）なら false
     */
    public function markAsPaid(Order $order, ?string $paymentIntentId): bool
    {
        $paid = DB::transaction(function () use ($order, $paymentIntentId): bool {
            $updated = Order::query()
                ->whereKey($order->id)
                ->where('status', OrderStatus::Pending)
                ->update([
                    'status' => OrderStatus::Paid,
                    'paid_at' => now(),
                    'stripe_payment_intent_id' => $paymentIntentId,
                ]);

            if ($updated === 0) {
                return false;
            }

            foreach ($order->items()->get() as $item) {
                for ($number = 0; $number < $item->quantity; $number++) {
                    $item->tickets()->create(['code' => (string) Str::ulid()]);
                }
            }

            return true;
        });

        $order->refresh();

        if ($paid) {
            $this->sendPaidMail($order);
        }

        if (! $paid && $order->status === OrderStatus::Cancelled) {
            Log::warning('取り消し済みの注文に支払いが入りました。返金が必要です。', [
                'order_id' => $order->id,
                'payment_intent_id' => $paymentIntentId,
            ]);
        }

        return $paid;
    }

    /**
     * 購入完了メールを注文者へ送る。キューは使わず、そのまま送る。
     *
     * 支払いは確定済みなので、送信に失敗しても注文は戻さない。例外を投げると Webhook が再送されるが、
     * 再送では「処理済み」になってメールが送られないため、ここで握りつぶしてログに残す。
     */
    private function sendPaidMail(Order $order): void
    {
        try {
            Mail::to($order->user)->send(new OrderPaidMail($order));
        } catch (Throwable $exception) {
            Log::error('購入完了メールを送れませんでした。', [
                'order_id' => $order->id,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * 支払い待ちの注文を取り消して、押さえていた枠を戻す。
     *
     * 残り枚数は pending / paid の明細だけを数えるので、cancelled にすれば枠が戻る。
     * 支払い済みや取り消し済みの注文は変えない（pending のときだけの条件付き更新）。
     */
    public function cancel(Order $order): void
    {
        Order::query()
            ->whereKey($order->id)
            ->where('status', OrderStatus::Pending)
            ->update(['status' => OrderStatus::Cancelled]);

        $order->refresh();
    }

    /**
     * 渡された枚数を整数にそろえ、買う券種だけを残す。
     *
     * @param  array<int|string, mixed>  $quantities
     * @return array<int, int> [券種ID => 1〜MAX_QUANTITY の枚数]
     *
     * @throws PurchaseException
     */
    private function selectedQuantities(array $quantities): array
    {
        $selected = [];

        foreach ($quantities as $ticketTypeId => $value) {
            $quantity = $this->toInteger($value);

            if ($quantity === null || $quantity < 0 || $quantity > self::MAX_QUANTITY) {
                throw PurchaseException::invalidQuantity();
            }

            if (! is_int($ticketTypeId)) {
                throw PurchaseException::unknownTicketType();
            }

            if ($quantity > 0) {
                $selected[$ticketTypeId] = $quantity;
            }
        }

        if ($selected === []) {
            throw PurchaseException::noTicketSelected();
        }

        return $selected;
    }

    /**
     * int か、整数を表す文字列（"3"）だけを受け付ける。小数・数字以外・null は null を返す。
     */
    private function toInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);

            return $integer === false ? null : $integer;
        }

        return null;
    }

    /**
     * このイベントの券種だけを、残り枚数の集計つきで取得する。別のイベントの券種や存在しない ID はエラー。
     *
     * @param  array<int, int>  $ticketTypeIds
     * @return Collection<int, TicketType>
     *
     * @throws PurchaseException
     */
    private function findTicketTypes(Event $event, array $ticketTypeIds): Collection
    {
        $ticketTypes = $event->ticketTypes()
            ->whereKey($ticketTypeIds)
            ->withReservedQuantity()
            ->get()
            ->keyBy('id');

        if ($ticketTypes->count() !== count($ticketTypeIds)) {
            throw PurchaseException::unknownTicketType();
        }

        return $ticketTypes;
    }
}
