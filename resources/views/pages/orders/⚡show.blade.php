<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('注文の詳細')] class extends Component {
    public Order $order;

    /**
     * 注文を見られるのは、注文した本人、そのイベントの主催者、管理者だけ。ほかの人には 404 を返す。
     */
    public function mount(Order $order): void
    {
        $this->authorize('view', $order);

        $this->order = $order->load('event', 'items.ticketType', 'items.tickets');
    }
}; ?>

<section class="w-full max-w-3xl">
    <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('orders.index')" wire:navigate>
        マイチケットに戻る
    </flux:button>

    <div class="mt-4 flex items-center gap-3">
        <flux:heading size="xl" level="1">注文の詳細</flux:heading>
        <flux:badge :color="$order->status->color()" data-test="order-status">{{ $order->status->label() }}</flux:badge>
    </div>

    @if ($order->status === OrderStatus::Pending)
        <flux:callout variant="warning" icon="exclamation-triangle" class="mt-6" data-test="order-pending">
            <flux:callout.heading>支払いが完了していません</flux:callout.heading>
            <flux:callout.text>お支払いが確認できると、チケットが発行されます。</flux:callout.text>
        </flux:callout>
    @endif

    <dl class="mt-8 space-y-3">
        <div class="flex gap-3">
            <dt class="w-24 shrink-0 text-zinc-500">注文番号</dt>
            <dd data-test="order-number">{{ $order->id }}</dd>
        </div>
        <div class="flex gap-3">
            <dt class="w-24 shrink-0 text-zinc-500">注文日</dt>
            <dd>{{ $order->created_at->isoFormat('M月D日(ddd) HH:mm') }}</dd>
        </div>
        <div class="flex gap-3">
            <dt class="w-24 shrink-0 text-zinc-500">イベント</dt>
            <dd>
                <flux:link :href="route('events.show', $order->event)" wire:navigate>{{ $order->event->title }}</flux:link>
            </dd>
        </div>
        <div class="flex gap-3">
            <dt class="w-24 shrink-0 text-zinc-500">開催日</dt>
            <dd>{{ $order->event->starts_at->isoFormat('M月D日(ddd) HH:mm') }}</dd>
        </div>
        <div class="flex gap-3">
            <dt class="w-24 shrink-0 text-zinc-500">会場</dt>
            <dd>{{ $order->event->venue }}</dd>
        </div>
    </dl>

    <flux:table class="mt-8">
        <flux:table.columns>
            <flux:table.column>券種</flux:table.column>
            <flux:table.column align="end">単価</flux:table.column>
            <flux:table.column align="end">枚数</flux:table.column>
            <flux:table.column align="end">小計</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($order->items as $item)
                <flux:table.row :key="$item->id" data-test="order-item">
                    <flux:table.cell>{{ $item->ticketType->name }}</flux:table.cell>
                    <flux:table.cell align="end">{{ number_format($item->unit_price) }}円</flux:table.cell>
                    <flux:table.cell align="end">{{ $item->quantity }}枚</flux:table.cell>
                    <flux:table.cell align="end">{{ number_format($item->quantity * $item->unit_price) }}円</flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <div class="mt-4 flex items-center justify-end gap-3">
        <flux:text>合計</flux:text>
        <flux:heading size="lg" data-test="order-total">{{ number_format($order->total_amount) }}円</flux:heading>
    </div>

    @if ($order->status === OrderStatus::Paid)
        @php
            $isBuyer = auth()->id() === $order->user_id;
            $qrCode = app(\App\Services\TicketQrCode::class);
        @endphp

        <flux:heading size="lg" level="2" class="mt-10">チケット</flux:heading>

        @if ($isBuyer)
            <flux:text class="mt-1">当日は、受付でこの QR コードをお見せください。</flux:text>
        @endif

        <ul class="mt-4 space-y-3" data-test="ticket-list">
            @foreach ($order->items as $item)
                @foreach ($item->tickets as $ticket)
                    <li class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 px-4 py-3 dark:border-zinc-700" data-test="ticket" data-checked-in="{{ $ticket->isCheckedIn() ? 'true' : 'false' }}">
                        <div>
                            <div>{{ $item->ticketType->name }}</div>

                            @if ($ticket->isCheckedIn())
                                <flux:badge size="sm" color="zinc" class="mt-1" data-test="ticket-checked-in">
                                    受付済み（{{ $ticket->checked_in_at->isoFormat('M月D日(ddd) HH:mm') }}）
                                </flux:badge>
                            @else
                                <flux:badge size="sm" color="green" class="mt-1">未受付</flux:badge>
                            @endif

                            @if ($isBuyer)
                                <div class="mt-2 font-mono text-sm tracking-wider" data-test="ticket-code">{{ $ticket->code }}</div>
                            @endif
                        </div>

                        @if ($isBuyer)
                            <div class="shrink-0 rounded bg-white p-1 {{ $ticket->isCheckedIn() ? 'opacity-30' : '' }}" data-test="ticket-qr" role="img" aria-label="チケットの QR コード">
                                {!! $qrCode->svg($ticket->code, 144) !!}
                            </div>
                        @endif
                    </li>
                @endforeach
            @endforeach
        </ul>
    @endif
</section>
