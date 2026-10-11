<?php

use App\Enums\EventStatus;
use App\Livewire\Forms\EventForm;
use App\Models\Event;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('イベントを登録')] class extends Component {
    public EventForm $form;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('create', Event::class);
    }

    /**
     * 下書きとしてイベントを登録する。
     */
    public function save(): void
    {
        $this->authorize('create', Event::class);

        $validated = $this->form->validate();

        $event = new Event($validated);
        $event->organizer_id = auth()->id();
        $event->status = EventStatus::Draft;
        $event->save();

        Flux::toast(variant: 'success', text: 'イベントを下書きで登録しました。');

        $this->redirectRoute('organizer.events.edit', $event, navigate: true);
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl" level="1">イベントを登録</flux:heading>
    <flux:text class="mt-2">最初は下書きで保存されます。準備ができたら編集画面から公開してください。</flux:text>

    <form wire:submit="save" class="my-6 w-full max-w-2xl space-y-6">
        <flux:input wire:model="form.title" label="タイトル" type="text" required autofocus />

        <flux:textarea wire:model="form.description" label="説明" rows="5" required />

        <flux:input wire:model="form.venue" label="会場" type="text" required />

        <div class="grid gap-6 sm:grid-cols-2">
            <flux:input wire:model="form.starts_at" label="開始日時" type="datetime-local" required />
            <flux:input wire:model="form.ends_at" label="終了日時" type="datetime-local" required />
        </div>

        <div class="flex items-center gap-3">
            <flux:button variant="primary" type="submit" data-test="save-event-button">登録する</flux:button>
            <flux:button :href="route('organizer.events.index')" wire:navigate>キャンセル</flux:button>
        </div>
    </form>
</section>
