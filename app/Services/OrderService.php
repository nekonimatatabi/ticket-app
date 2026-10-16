<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\PurchaseException;
use App\Mail\OrderPaidMail;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Throwable;

class OrderService
{
    /**
     * 支払い待ちの注文が枚数を押さえておく時間（分）。
     * Stripe Checkout Session の有効期限（CHECKOUT_MINUTES）より長くして、決済ページで払える間は必ず枠が残るようにする。
     */
    public const PENDING_MINUTES = 35;

    /**
     * Stripe Checkout Session の有効期限（分）。Stripe の expires_at は作成から30分以上先でなければ指定できない。
     */
    public const CHECKOUT_MINUTES = 30;

    /**
     * 1つの券種で、1回の注文に入れられる最大枚数。
     */
    public const MAX_QUANTITY = 10;

    /**
     * 1人が1つのイベントで持てる最大枚数（支払い済み＋枠を押さえている支払い待ちの合計）。無料券にも適用する。
     */
    public const MAX_TICKETS_PER_EVENT = 10;

    public function __construct(private CheckoutService $checkout) {}

    /**
     * 支払い待ち（pending）の注文と明細を作る。
     *
     * 画面から渡る値は書き換えられるので、枚数・券種・販売期間・残り枚数・1人あたりの上限は、ここで必ず確認する。
     * 金額は受け取らず、DB の券種の価格から計算する。確認で1つでも駄目なら、何も作らない。
     *
     * 同時に買われても売り越さないよう、トランザクションの最初に券種の行をロックする（findTicketTypes）。
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

            $held = $this->ticketsHeldBy($user, $event);

            if ($held + array_sum($selected) > self::MAX_TICKETS_PER_EVENT) {
                throw PurchaseException::tooManyTickets(self::MAX_TICKETS_PER_EVENT, $held);
            }

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
     * 枠の押さえ（PENDING_MINUTES）が切れた後の確定で、すでに定員を超えるときは、チケットを発行せず、注文を cancelled にして
     * 決済 ID（stripe_payment_intent_id）を残し、返金が必要なことをログに出す（返金は運用で行う）。pending のまま残すと、
     * 完了ページが Stripe に問い合わせ続け、購入者は案内もなく待たされ、その人は同じイベントを買えなくなるため。
     *
     * @return bool 今回の呼び出しで paid にできたら true。既に処理済み（paid など）や、定員超過で確定できなければ false
     */
    public function markAsPaid(Order $order, ?string $paymentIntentId): bool
    {
        $refundLogged = false;

        $paid = DB::transaction(function () use ($order, $paymentIntentId, &$refundLogged): bool {
            if ($this->holdHasExpired($order) && $this->isPending($order) && ! $this->hasStockForLateOrder($order)) {
                $cancelled = Order::query()
                    ->whereKey($order->id)
                    ->where('status', OrderStatus::Pending)
                    ->update([
                        'status' => OrderStatus::Cancelled,
                        'stripe_payment_intent_id' => $paymentIntentId,
                    ]);

                if ($cancelled > 0) {
                    $refundLogged = true;

                    Log::error('枠の押さえが切れた注文の支払いで、定員を超えるため確定できず、注文を取り消しました。返金が必要です。', [
                        'order_id' => $order->id,
                        'payment_intent_id' => $paymentIntentId,
                    ]);
                }

                return false;
            }

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

        if (! $paid && ! $refundLogged && $order->status === OrderStatus::Cancelled) {
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
     * 支払い待ちの注文を、Stripe の決済ページごと取り消す。
     *
     * 決済ページ（Checkout Session）を先に失効させ、成功したときだけ注文を取り消す。失効させないと、取り消した後でも
     * 決済ページで支払えてしまう。失効できないとき（支払いが済んだ直後など）は、注文を変えない。
     *
     * @return bool 取り消せたら true。支払い済み・失効できなかった注文は false
     */
    public function abandon(Order $order): bool
    {
        $order->refresh();

        if ($order->status === OrderStatus::Pending && $order->stripe_checkout_session_id !== null) {
            try {
                $this->checkout->expireSession($order->stripe_checkout_session_id);
            } catch (ApiErrorException $exception) {
                return $this->settleAfterFailedExpire($order, $exception);
            }
        }

        $this->cancel($order);

        return $order->status === OrderStatus::Cancelled;
    }

    /**
     * 決済ページを失効できなかった注文を、Session の状態を見て片付ける。
     *
     * Stripe の失効は、開いている（open）Session にしか使えない。タブを閉じて期限が過ぎた Session は自然に失効済み（expired）で、
     * 失効の API はエラーになる。これを「取り消せない」と扱うと、その人は同じイベントを二度と買えなくなる。
     * - 失効済み: 支払いは入らないので、注文を取り消す
     * - 支払い済み: 注文を確定する（取り消さない）
     * - それ以外（後払い型で入金待ち、確認できない）: 注文を変えない
     *
     * @return bool 取り消せたら true
     */
    private function settleAfterFailedExpire(Order $order, ApiErrorException $exception): bool
    {
        try {
            $session = $this->checkout->retrieveSession((string) $order->stripe_checkout_session_id);
        } catch (ApiErrorException) {
            Log::warning('Stripe の決済ページを失効できず、状態も確認できなかったため、注文を取り消しませんでした。', [
                'order_id' => $order->id,
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }

        if ($session->status === 'expired') {
            $this->cancel($order);

            return $order->status === OrderStatus::Cancelled;
        }

        if ($session->payment_status === 'paid') {
            $this->confirmStripePayment($order, $session);

            return false;
        }

        Log::warning('Stripe の決済ページを失効できなかったため、注文を取り消しませんでした。', [
            'order_id' => $order->id,
            'session_status' => $session->status,
            'exception' => $exception->getMessage(),
        ]);

        return false;
    }

    /**
     * 同じ人の、このイベントの支払い待ちの注文を、すべて取り消す。戻る操作やタブを閉じて残った注文を片付けて、
     * 新しい注文が自分の古い注文に邪魔されないようにする。
     *
     * @throws PurchaseException 取り消せない注文（支払いが済んだ直後など）が残ったとき
     */
    public function abandonPendingOrders(User $user, Event $event): void
    {
        $pending = Order::query()
            ->where('user_id', $user->id)
            ->where('event_id', $event->id)
            ->where('status', OrderStatus::Pending)
            ->get();

        foreach ($pending as $order) {
            if (! $this->abandon($order)) {
                throw PurchaseException::pendingOrderExists();
            }
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
     * このイベントの券種の行をロックしてから、残り枚数の集計つきで取得する。別のイベントの券種や存在しない ID はエラー。
     *
     * @param  array<int, int>  $ticketTypeIds
     * @return Collection<int, TicketType>
     *
     * @throws PurchaseException
     */
    private function findTicketTypes(Event $event, array $ticketTypeIds): Collection
    {
        // 先に行をロックする。同時に買う人は、ロックが外れるまで待ち、その後で残り枚数を数え直す。
        // ロックより前に通常の SELECT を流さないこと（読み取りの時点がロックより前に固定され、古い枚数を読むため）。
        // 複数の券種を同じ順（id 順）でロックして、デッドロックを避ける。
        $event->ticketTypes()->whereKey($ticketTypeIds)->orderBy('id')->lockForUpdate()->get();

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

    /**
     * この人が、このイベントで持っている枚数。支払い済みと、枠を押さえている支払い待ちの明細の合計。
     */
    private function ticketsHeldBy(User $user, Event $event): int
    {
        return (int) OrderItem::query()
            ->whereHas('order', function (Builder $orders) use ($user, $event): void {
                $orders->where('user_id', $user->id)->where('event_id', $event->id);

                TicketType::onlyOrdersReservingStock($orders);
            })
            ->sum('quantity');
    }

    /**
     * 注文の作成から、枠を押さえる時間（PENDING_MINUTES）が過ぎているか。
     */
    private function holdHasExpired(Order $order): bool
    {
        return $order->created_at->lt(now()->subMinutes(self::PENDING_MINUTES));
    }

    private function isPending(Order $order): bool
    {
        return Order::query()->whereKey($order->id)->where('status', OrderStatus::Pending)->exists();
    }

    /**
     * 枠の押さえが切れた注文でも、定員を超えずに確定できるか。券種の行をロックして、ほかの注文が押さえている枚数と合わせて数える。
     */
    private function hasStockForLateOrder(Order $order): bool
    {
        $items = $order->items()->get();
        $ticketTypeIds = $items->pluck('ticket_type_id')->all();

        TicketType::query()->whereKey($ticketTypeIds)->orderBy('id')->lockForUpdate()->get();
        $ticketTypes = TicketType::query()->whereKey($ticketTypeIds)->get()->keyBy('id');

        foreach ($items as $item) {
            $reservedByOthers = (int) OrderItem::query()
                ->where('ticket_type_id', $item->ticket_type_id)
                ->where('order_id', '!=', $order->id)
                ->whereHas('order', function (Builder $orders): void {
                    TicketType::onlyOrdersReservingStock($orders);
                })
                ->sum('quantity');

            if ($reservedByOthers + $item->quantity > $ticketTypes[$item->ticket_type_id]->capacity) {
                return false;
            }
        }

        return true;
    }
}
