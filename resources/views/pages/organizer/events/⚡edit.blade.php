<?php

use App\Enums\EventStatus;
use App\Livewire\Forms\EventForm;
use App\Models\Event;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('イベントを編集')] class extends Component {
    public Event $event;

    public EventForm $form;

    /**
     * Mount the component.
     */
    public function mount(Event $event): void
    {
        $this->authorize('update', $event);

        $this->event = $event;
        $this->form->fillFromEvent($event);
    }

    /**
     * イベントの内容を保存する。
     */
    public function save(): void
    {
        $this->authorize('update', $this->event);

        $validated = $this->form->validate();

        $this->event->fill($validated)->save();

        Flux::toast(variant: 'success', text: 'イベントを保存しました。');
    }

    /**
     * 下書きと公開中を切り替える。管理者が非公開にしたイベントは切り替えられない。
     */
    public function togglePublish(): void
    {
        $this->authorize('update', $this->event);

        if ($this->event->status === EventStatus::Unpublished) {
            Flux::toast(variant: 'danger', text: '管理者により非公開にされているため、切り替えできません。');

            return;
        }

        $this->event->status = $this->event->isPublished()
            ? EventStatus::Draft
            : EventStatus::Published;
        $this->event->save();

        Flux::toast(
            variant: 'success',
            text: $this->event->isPublished() ? 'イベントを公開しました。' : 'イベントを下書きに戻しました。',
        );
    }

    /**
     * イベントを削除する。
     */
    public function deleteEvent(): void
    {
        $this->authorize('delete', $this->event);

        $this->event->delete();

        Flux::toast(variant: 'success', text: 'イベントを削除しました。');

        $this->redirectRoute('organizer.events.index', navigate: true);
    }
}; ?>

<section class="w-full">
    <div class="flex items-center gap-3">
        <flux:heading size="xl" level="1">イベントを編集</flux:heading>
        <flux:badge :color="$event->status->color()">{{ $event->status->label() }}</flux:badge>
    </div>

    <form wire:submit="save" class="my-6 w-full max-w-2xl space-y-6">
        <flux:input wire:model="form.title" label="タイトル" type="text" required />

        <flux:textarea wire:model="form.description" label="説明" rows="5" required />

        <flux:input wire:model="form.venue" label="会場" type="text" required />

        <div class="grid gap-6 sm:grid-cols-2">
            <flux:input wire:model="form.starts_at" label="開始日時" type="datetime-local" required />
            <flux:input wire:model="form.ends_at" label="終了日時" type="datetime-local" required />
        </div>

        <div class="flex items-center gap-3">
            <flux:button variant="primary" type="submit" data-test="save-event-button">保存する</flux:button>
            <flux:button :href="route('organizer.events.index')" wire:navigate>一覧に戻る</flux:button>
        </div>
    </form>

    <flux:separator class="max-w-2xl" />

    <div class="my-6 max-w-2xl space-y-6">
        <div>
            <flux:heading level="2">公開設定</flux:heading>

            @if ($event->status === \App\Enums\EventStatus::Unpublished)
                <flux:text class="mt-2">管理者により非公開にされています。公開に戻すことはできません。</flux:text>
                <flux:button class="mt-4" disabled data-test="toggle-publish-button">公開する</flux:button>
            @else
                <flux:text class="mt-2">
                    {{ $event->isPublished() ? '公開中です。下書きに戻すと参加者には表示されません。' : '下書きです。公開すると参加者に表示されます。' }}
                </flux:text>
                <flux:button class="mt-4" wire:click="togglePublish" data-test="toggle-publish-button">
                    {{ $event->isPublished() ? '下書きに戻す' : '公開する' }}
                </flux:button>
            @endif
        </div>

        <div>
            <flux:heading level="2">イベントの削除</flux:heading>
            <flux:text class="mt-2">削除すると元に戻せません。</flux:text>

            <flux:modal.trigger name="delete-event">
                <flux:button variant="danger" class="mt-4" data-test="delete-event-button">削除する</flux:button>
            </flux:modal.trigger>
        </div>
    </div>

    <flux:modal name="delete-event" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">イベントを削除しますか？</flux:heading>
                <flux:text class="mt-2">「{{ $event->title }}」を削除します。この操作は元に戻せません。</flux:text>
            </div>

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">キャンセル</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="deleteEvent" data-test="confirm-delete-event-button">削除する</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
