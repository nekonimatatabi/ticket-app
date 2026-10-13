<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\CheckoutService;
use App\Services\OrderService;
use Livewire\Attributes\Title;
use Livewire\Component;
use Stripe\Exception\ApiErrorException;

new #[Title('お支払いを中断しました')] class extends Component {
    public Order $order;

    /**
     * 決済ページを閉じられず、注文を取り消せなかったか。
     */
    public bool $couldNotCancel = false;

    /**
     * Stripe の決済ページで「戻る」を押すと、ここへ戻ってくる（cancel_url）。
     * 支払い待ちの注文は、決済ページを閉じてから取り消して枠を戻す（閉じないと、戻る操作で支払えてしまう）。
     * 決済ページを閉じられなかった場合（支払いが済んだ直後など）は、注文を取り消さない。
     * 支払い済みの注文は変えない。開けるのは注文した本人だけ。
     */
    public function mount(Order $order): void
    {
        $this->authorize('cancel', $order);

        if ($order->status === OrderStatus::Pending && $order->stripe_checkout_session_id !== null) {
            try {
                app(CheckoutService::class)->expireSession($order->stripe_checkout_session_id);
            } catch (ApiErrorException) {
                $this->couldNotCancel = true;
            }
        }

        if (! $this->couldNotCancel) {
            app(OrderService::class)->cancel($order);
        }

        $this->order = $order->load('event');
    }
}; ?>

<section class="w-full max-w-2xl">
    @if ($couldNotCancel)
        <flux:heading size="xl" level="1">注文を取り消せませんでした</flux:heading>

        <flux:text class="mt-4" data-test="could-not-cancel">お支払いの状況を確認できませんでした。しばらくしてから、注文の状態をご確認ください。</flux:text>
    @elseif ($order->status === OrderStatus::Paid)
        <flux:heading size="xl" level="1">この注文は支払い済みです</flux:heading>

        <flux:text class="mt-4">お支払いは完了しているため、注文は取り消していません。</flux:text>
    @elseif ($order->status === OrderStatus::Refunded)
        <flux:heading size="xl" level="1">この注文は返金済みです</flux:heading>

        <flux:text class="mt-4">返金の手続きが完了しているため、この画面での取り消しは行っていません。</flux:text>
    @else
        <flux:heading size="xl" level="1">お支払いを中断しました</flux:heading>

        <flux:text class="mt-4">注文は取り消しました。購入する場合は、イベントの画面からもう一度お手続きください。</flux:text>
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
            <dt class="w-24 shrink-0 text-zinc-500">状態</dt>
            <dd data-test="order-status">{{ $order->status->label() }}</dd>
        </div>
    </dl>

    <flux:button class="mt-8" icon="arrow-left" :href="route('events.show', $order->event)" wire:navigate>
        イベント詳細へ戻る
    </flux:button>
</section>
