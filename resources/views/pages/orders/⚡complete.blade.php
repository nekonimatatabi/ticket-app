<?php

use App\Models\Order;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('注文を受け付けました')] class extends Component {
    public Order $order;

    /**
     * 注文を見られるのは、注文した本人、そのイベントの主催者、管理者だけ。ほかの人には 404 を返す。
     */
    public function mount(Order $order): void
    {
        $this->authorize('view', $order);

        $this->order = $order->load('event');
    }
}; ?>

<section class="w-full max-w-2xl">
    <flux:heading size="xl" level="1">注文を受け付けました（支払いは平日課題で実装）</flux:heading>

    <flux:text class="mt-4">この画面は仮のものです。支払いの処理はまだ行われていません。</flux:text>

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
            <dd>{{ $order->status->label() }}</dd>
        </div>
    </dl>

    <flux:button class="mt-8" :href="route('home')" wire:navigate>イベント一覧へ</flux:button>
</section>
