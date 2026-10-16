<?php

use App\Enums\OrderStatus;
use App\Models\Event;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('売上と注文')] class extends Component {
    use WithPagination;

    public Event $event;

    /**
     * 見られるのは、イベントの所有者と管理者だけ。ほかの主催者には 403 を返す。
     */
    public function mount(Event $event): void
    {
        $this->authorize('viewOrders', $event);

        $this->event = $event;
    }

    /**
     * 全体の支払い済みの注文数。売上・販売枚数は Event::salesAmount() / soldQuantity() が返す。
     */
    #[Computed]
    public function paidOrderCount(): int
    {
        return $this->event->orders()->where('status', OrderStatus::Paid)->count();
    }

    /**
     * 券種ごとの販売枚数（sold_quantity）と売上（sales_amount）。支払い済みの注文だけが対象で、売れていない券種も 0 で出す。
     *
     * @return Collection<int, \App\Models\TicketType>
     */
    #[Computed]
    public function ticketTypes(): Collection
    {
        $paid = fn (Builder $items) => $items->whereHas('order', fn (Builder $orders) => $orders->where('status', OrderStatus::Paid));

        return $this->event->ticketTypes()
            ->withSum(['orderItems as sold_quantity' => $paid], 'quantity')
            ->withSum(['orderItems as sales_amount' => $paid], DB::raw('order_items.quantity * order_items.unit_price'))
            ->orderBy('sales_starts_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * 支払い済みの注文を、支払い日時の新しい順に10件ずつ取得する。
     */
    #[Computed]
    public function orders(): LengthAwarePaginator
    {
        return $this->event->orders()
            ->where('status', OrderStatus::Paid)
            ->with('user', 'items.ticketType')
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate(10);
    }
}; ?>

<section class="w-full">
    <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('organizer.events.index')" wire:navigate>
        イベント一覧に戻る
    </flux:button>

    <flux:heading size="xl" level="1" class="mt-4">売上と注文</flux:heading>
    <flux:text class="mt-2">{{ $event->title }}（{{ $event->starts_at->isoFormat('M月D日(ddd) HH:mm') }}）</flux:text>

    <div class="mt-6 grid gap-4 sm:grid-cols-3">
        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:text>売上合計</flux:text>
            <flux:heading size="xl" data-test="sales-amount">{{ number_format($event->salesAmount()) }}円</flux:heading>
        </div>
        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:text>販売枚数</flux:text>
            <flux:heading size="xl" data-test="sold-quantity">{{ number_format($event->soldQuantity()) }}枚</flux:heading>
        </div>
        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:text>注文数</flux:text>
            <flux:heading size="xl" data-test="paid-order-count">{{ number_format($this->paidOrderCount) }}件</flux:heading>
        </div>
    </div>

    <flux:heading size="lg" level="2" class="mt-10">券種ごとの販売状況</flux:heading>

    <flux:table class="mt-4">
        <flux:table.columns>
            <flux:table.column>券種</flux:table.column>
            <flux:table.column align="end">販売枚数 / 定員</flux:table.column>
            <flux:table.column align="end">売上</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->ticketTypes as $ticketType)
                <flux:table.row :key="$ticketType->id" data-test="ticket-type-row">
                    <flux:table.cell>{{ $ticketType->name }}</flux:table.cell>
                    <flux:table.cell align="end">{{ number_format((int) $ticketType->sold_quantity) }} / {{ number_format($ticketType->capacity) }}枚</flux:table.cell>
                    <flux:table.cell align="end">{{ number_format((int) $ticketType->sales_amount) }}円</flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="3">券種がまだありません。</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:heading size="lg" level="2" class="mt-10">支払い済みの注文</flux:heading>

    @if ($this->orders->isEmpty())
        <flux:text class="mt-4" data-test="no-paid-orders">支払い済みの注文はまだありません。</flux:text>
    @else
        <flux:table :paginate="$this->orders" class="mt-4">
            <flux:table.columns>
                <flux:table.column>支払い日時</flux:table.column>
                <flux:table.column>購入者</flux:table.column>
                <flux:table.column>内容</flux:table.column>
                <flux:table.column align="end">合計</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->orders as $order)
                    <flux:table.row :key="$order->id" data-test="paid-order-row">
                        <flux:table.cell>{{ $order->paid_at->isoFormat('M月D日(ddd) HH:mm') }}</flux:table.cell>
                        <flux:table.cell>
                            <div>{{ $order->user->name }}</div>
                            <div class="text-sm text-zinc-500">{{ $order->user->email }}</div>
                        </flux:table.cell>
                        <flux:table.cell>
                            @foreach ($order->items as $item)
                                <div>{{ $item->ticketType->name }} × {{ $item->quantity }}枚</div>
                            @endforeach
                        </flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($order->total_amount) }}円</flux:table.cell>
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
