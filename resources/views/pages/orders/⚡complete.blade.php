<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\CheckoutService;
use App\Services\OrderService;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Stripe\Exception\ApiErrorException;

new #[Title('購入の確認')] class extends Component {
    public Order $order;

    /**
     * Stripe が success_url に付けて戻す Checkout Session の ID。注文の stripe_checkout_session_id と一致したときだけ確認に使う。
     */
    #[Url(as: 'session_id')]
    public ?string $sessionId = null;

    /**
     * 注文を見られるのは、注文した本人、そのイベントの主催者、管理者だけ。ほかの人には 404 を返す。
     */
    public function mount(Order $order): void
    {
        $this->order = $order;

        $this->confirmPayment();

        $this->order->loadMissing('event');
    }

    /**
     * 支払い待ちの注文について、Stripe の Session を取得して支払い済みなら確定する。数秒ごとにも呼ばれる。
     *
     * Webhook が先に確定していれば、注文は paid になっていて Stripe を呼ばない。後から Webhook が来ても、
     * markAsPaid() が pending のときだけ更新するので二重にならない。Stripe に繋がらないときは「確認中」のままにする。
     */
    public function confirmPayment(): void
    {
        $this->authorize('view', $this->order);

        $this->order->refresh();

        if ($this->order->status !== OrderStatus::Pending
            || $this->sessionId === null
            || $this->sessionId !== $this->order->stripe_checkout_session_id) {
            return;
        }

        try {
            $session = app(CheckoutService::class)->retrieveSession($this->sessionId);
        } catch (ApiErrorException) {
            return;
        }

        app(OrderService::class)->confirmStripePayment($this->order, $session);
    }
}; ?>

<section class="w-full max-w-2xl" @if ($order->status === OrderStatus::Pending) wire:poll.3s="confirmPayment" @endif>
    @if ($order->status === OrderStatus::Paid)
        <flux:heading size="xl" level="1" data-test="payment-paid">購入が完了しました</flux:heading>

        <flux:text class="mt-4">お支払いを確認しました。チケット {{ $order->tickets()->count() }} 枚を発行しました。</flux:text>

        <flux:button class="mt-6" variant="primary" :href="route('orders.show', $order)" wire:navigate data-test="order-detail-button">
            注文の詳細とチケットを見る
        </flux:button>
    @elseif ($order->status === OrderStatus::Pending)
        <flux:heading size="xl" level="1" data-test="payment-pending">お支払いを確認しています</flux:heading>

        <flux:text class="mt-4">確認ができ次第、この画面が切り替わります。このままお待ちください。</flux:text>
    @else
        <flux:heading size="xl" level="1">この注文は購入できていません</flux:heading>

        <flux:text class="mt-4">注文の状態は「{{ $order->status->label() }}」です。</flux:text>

        @if ($order->status === OrderStatus::Cancelled)
            <flux:text class="mt-2" data-test="cancelled-note">すでにお支払いがお済みの場合は、返金の手続きをいたします。お手数ですが、主催者までお問い合わせください。</flux:text>
        @endif
    @endif

    <dl class="mt-8 space-y-3">
        <div class="flex gap-3">
            <dt class="w-24 shrink-0 text-zinc-500">注文番号</dt>
            <dd data-test="order-number">{{ $order->id }}</dd>
        </div>
        <div class="flex gap-3">
            <dt class="w-24 shrink-0 text-zinc-500">イベント</dt>
            <dd>{{ $order->event->title }}</dd>
        </div>
        <div class="flex gap-3">
            <dt class="w-24 shrink-0 text-zinc-500">合計金額</dt>
            <dd data-test="order-total">{{ number_format($order->total_amount) }}円</dd>
        </div>
        <div class="flex gap-3">
            <dt class="w-24 shrink-0 text-zinc-500">状態</dt>
            <dd data-test="order-status">{{ $order->status->label() }}</dd>
        </div>
    </dl>

    <flux:button class="mt-8" :href="route('home')" wire:navigate>イベント一覧へ</flux:button>
</section>
