<?php

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('マイチケット')] class extends Component {
    use WithPagination;

    /**
     * ログイン中のユーザー自身の注文を、新しい順に10件ずつ取得する。他人の注文は、クエリの時点で含まれない。
     */
    #[Computed]
    public function orders(): LengthAwarePaginator
    {
        return auth()->user()
            ->orders()
            ->with('event')
            ->withSum('items as ticket_count', 'quantity')
            ->latest()
            ->orderByDesc('id')
            ->paginate(10);
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl" level="1">マイチケット</flux:heading>

    @if ($this->orders->isEmpty())
        <flux:text class="mt-8" data-test="no-orders">まだ注文がありません。イベントを探して、チケットを購入しましょう。</flux:text>

        <flux:button class="mt-4" :href="route('home')" wire:navigate>イベント一覧へ</flux:button>
    @else
        <flux:table :paginate="$this->orders" class="mt-6">
            <flux:table.columns>
                <flux:table.column>注文日</flux:table.column>
                <flux:table.column>イベント</flux:table.column>
                <flux:table.column>開催日</flux:table.column>
                <flux:table.column align="end">枚数</flux:table.column>
                <flux:table.column align="end">合計</flux:table.column>
                <flux:table.column>状態</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->orders as $order)
                    <flux:table.row :key="$order->id" data-test="order-row">
                        <flux:table.cell>{{ $order->created_at->isoFormat('M月D日(ddd) HH:mm') }}</flux:table.cell>
                        <flux:table.cell>{{ $order->event->title }}</flux:table.cell>
                        <flux:table.cell>{{ $order->event->starts_at->isoFormat('M月D日(ddd) HH:mm') }}</flux:table.cell>
                        <flux:table.cell align="end">{{ number_format((int) $order->ticket_count) }}枚</flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($order->total_amount) }}円</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$order->status->color()" inset="top bottom">
                                {{ $order->status->label() }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:button size="sm" :href="route('orders.show', $order)" wire:navigate>
                                詳細
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</section>
