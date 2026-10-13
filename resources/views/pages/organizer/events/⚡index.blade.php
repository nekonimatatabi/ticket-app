<?php

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('イベント一覧')] class extends Component {
    use WithPagination;

    /**
     * ログイン中の主催者のイベントを開催日順に取得する。
     */
    #[Computed]
    public function events(): LengthAwarePaginator
    {
        return auth()->user()->events()->withSales()->orderBy('starts_at')->paginate(10);
    }
}; ?>

<section class="w-full">
    <div class="flex items-center justify-between">
        <flux:heading size="xl" level="1">イベント一覧</flux:heading>

        <flux:button variant="primary" icon="plus" :href="route('organizer.events.create')" wire:navigate data-test="create-event-button">
            イベントを登録
        </flux:button>
    </div>

    @if ($this->events->isEmpty())
        <flux:text class="mt-8">まだイベントがありません。「イベントを登録」から作成してください。</flux:text>
    @else
        <flux:table :paginate="$this->events" class="mt-6">
            <flux:table.columns>
                <flux:table.column>タイトル</flux:table.column>
                <flux:table.column>開催日</flux:table.column>
                <flux:table.column>状態</flux:table.column>
                <flux:table.column align="end">販売枚数</flux:table.column>
                <flux:table.column align="end">売上</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->events as $event)
                    <flux:table.row :key="$event->id">
                        <flux:table.cell>{{ $event->title }}</flux:table.cell>
                        <flux:table.cell>{{ $event->starts_at->isoFormat('M月D日(ddd) HH:mm') }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$event->status->color()" inset="top bottom">
                                {{ $event->status->label() }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell align="end" data-test="sold-quantity">{{ number_format($event->soldQuantity()) }}枚</flux:table.cell>
                        <flux:table.cell align="end" data-test="sales-amount">{{ number_format($event->salesAmount()) }}円</flux:table.cell>
                        <flux:table.cell align="end">
                            <div class="flex justify-end gap-2">
                                <flux:button size="sm" :href="route('organizer.events.check-in', $event)" wire:navigate data-test="event-check-in-button">
                                    受付
                                </flux:button>
                                <flux:button size="sm" :href="route('organizer.events.orders', $event)" wire:navigate data-test="event-orders-button">
                                    売上
                                </flux:button>
                                <flux:button size="sm" :href="route('organizer.events.edit', $event)" wire:navigate>
                                    編集
                                </flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</section>
