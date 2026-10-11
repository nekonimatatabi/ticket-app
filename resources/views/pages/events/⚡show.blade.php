<?php

use App\Models\Event;
use App\Models\TicketType;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('イベント詳細')] class extends Component {
    public Event $event;

    /**
     * 公開中のイベントは誰でも見られる。それ以外は所有者と管理者だけで、ほかの人には 404 を返す。
     */
    public function mount(Event $event): void
    {
        $this->authorize('view', $event);

        $this->event = $event->load('organizer');
    }

    /**
     * 券種を販売開始の順に取得する。残り枚数はまとめて集計する。
     *
     * @return Collection<int, TicketType>
     */
    #[Computed]
    public function ticketTypes(): Collection
    {
        return $this->event->ticketTypes()
            ->withReservedQuantity()
            ->orderBy('sales_starts_at')
            ->orderBy('id')
            ->get();
    }
}; ?>

<section class="w-full max-w-3xl">
    <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('home')" wire:navigate>イベント一覧</flux:button>

    <div class="mt-4 flex aspect-video items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-700">
        <flux:icon.photo class="size-14 text-zinc-400 dark:text-zinc-500" />
    </div>

    <div class="mt-6 flex flex-wrap items-center gap-3">
        <flux:heading size="xl" level="1">{{ $event->title }}</flux:heading>
        @unless ($event->isPublished())
            <flux:badge :color="$event->status->color()">{{ $event->status->label() }}</flux:badge>
        @endunless
    </div>

    <dl class="mt-6 space-y-3">
        <div class="flex items-start gap-3">
            <flux:icon.calendar-days class="mt-0.5 size-5 shrink-0 text-zinc-500" />
            <dd data-test="event-datetime">
                {{ $event->starts_at->isoFormat('M月D日(ddd) HH:mm') }}
                〜 {{ $event->ends_at->isoFormat('M月D日(ddd) HH:mm') }}
            </dd>
        </div>
        <div class="flex items-start gap-3">
            <flux:icon.map-pin class="mt-0.5 size-5 shrink-0 text-zinc-500" />
            <dd data-test="event-venue">{{ $event->venue }}</dd>
        </div>
        <div class="flex items-start gap-3">
            <flux:icon.user class="mt-0.5 size-5 shrink-0 text-zinc-500" />
            <dd data-test="event-organizer">主催: {{ $event->organizer->name }}</dd>
        </div>
    </dl>

    <flux:text class="mt-6" data-test="event-description">{!! nl2br(e($event->description)) !!}</flux:text>

    <flux:separator class="my-8" />

    <flux:heading level="2">券種</flux:heading>

    @if ($this->ticketTypes->isEmpty())
        <flux:text class="mt-4">券種はまだありません。</flux:text>
    @else
        <flux:table class="mt-4">
            <flux:table.columns>
                <flux:table.column>券種</flux:table.column>
                <flux:table.column>価格</flux:table.column>
                <flux:table.column>残り枚数</flux:table.column>
                <flux:table.column>販売期間</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->ticketTypes as $ticketType)
                    <flux:table.row :key="$ticketType->id" data-test="ticket-type-row">
                        <flux:table.cell>
                            {{ $ticketType->name }}
                            @if ($ticketType->isOnSale())
                                <flux:badge size="sm" color="green" inset="top bottom">販売中</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc" inset="top bottom">
                                    {{ $ticketType->sales_starts_at->isFuture() ? '販売前' : '販売終了' }}
                                </flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ number_format($ticketType->price) }}円</flux:table.cell>
                        <flux:table.cell>
                            {{ $ticketType->remaining() === 0 ? '売り切れ' : '残り'.number_format($ticketType->remaining()).'枚' }}
                        </flux:table.cell>
                        <flux:table.cell>
                            {{ $ticketType->sales_starts_at->isoFormat('M月D日(ddd) HH:mm') }}
                            〜 {{ $ticketType->sales_ends_at->isoFormat('M月D日(ddd) HH:mm') }}
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</section>
