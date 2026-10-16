<?php

use App\Models\Event;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('公開中のイベント')] class extends Component {
    use WithPagination;

    /**
     * 公開中で、まだ終わっていないイベントを開催日の近い順に取得する。
     */
    #[Computed]
    public function events(): LengthAwarePaginator
    {
        return Event::query()
            ->published()
            ->upcoming()
            ->withMin('ticketTypes', 'price')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->paginate(12);
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl" level="1">公開中のイベント</flux:heading>

    @if ($this->events->isEmpty())
        <flux:text class="mt-8">公開中のイベントはありません。</flux:text>
    @else
        <div class="mt-6 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->events as $event)
                <a
                    href="{{ route('events.show', $event) }}"
                    wire:navigate
                    wire:key="event-{{ $event->id }}"
                    class="group block"
                    data-test="event-card"
                >
                    <flux:card class="h-full overflow-hidden p-0 transition group-hover:border-zinc-400 dark:group-hover:border-zinc-500">
                        <div class="flex aspect-video items-center justify-center bg-zinc-100 dark:bg-zinc-700">
                            <flux:icon.photo class="size-10 text-zinc-400 dark:text-zinc-500" />
                        </div>

                        <div class="space-y-2 p-4">
                            <flux:heading size="lg">{{ $event->title }}</flux:heading>

                            <flux:text>{{ $event->starts_at->isoFormat('M月D日(ddd) HH:mm') }}</flux:text>
                            <flux:text>{{ $event->venue }}</flux:text>

                            @if ($event->ticket_types_min_price !== null)
                                <flux:text class="font-medium text-zinc-800 dark:text-white" data-test="event-price">
                                    {{ (int) $event->ticket_types_min_price === 0 ? '無料' : number_format((int) $event->ticket_types_min_price).'円〜' }}
                                </flux:text>
                            @endif
                        </div>
                    </flux:card>
                </a>
            @endforeach
        </div>

        <div class="mt-8">
            <flux:pagination :paginator="$this->events" />
        </div>
    @endif
</section>
