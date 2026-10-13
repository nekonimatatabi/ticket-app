<?php

use App\Enums\EventStatus;
use App\Livewire\Forms\EventForm;
use App\Livewire\Forms\TicketTypeForm;
use App\Models\Event;
use App\Models\TicketType;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('イベントを編集')] class extends Component {
    public Event $event;

    public EventForm $form;

    public TicketTypeForm $ticketTypeForm;

    /**
     * 編集中の券種の ID。null のときは新しい券種を追加するフォーム。
     */
    public ?int $editingTicketTypeId = null;

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

        if (! $this->event->isPublished() && ! $this->event->canBePublished()) {
            Flux::toast(variant: 'danger', text: '券種が1つもないため公開できません。先に券種を追加してください。');

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
     * このイベントの券種を販売開始日時の順に取得する。
     *
     * @return Collection<int, TicketType>
     */
    #[Computed]
    public function ticketTypes(): Collection
    {
        return $this->event->ticketTypes()
            ->withExists('orderItems as has_orders')
            ->orderBy('sales_starts_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * このイベントに注文があるか。あれば、外部キーの制約でイベントを削除できない。
     */
    #[Computed]
    public function hasOrders(): bool
    {
        return $this->event->orders()->exists();
    }

    /**
     * 一覧の「編集」を押した券種の値をフォームに入れる。
     */
    public function editTicketType(int $ticketTypeId): void
    {
        $this->authorize('update', $this->event);

        $ticketType = $this->event->ticketTypes()->findOrFail($ticketTypeId);

        $this->resetValidation();
        $this->ticketTypeForm->fillFromTicketType($ticketType);
        $this->editingTicketTypeId = $ticketType->id;
    }

    /**
     * 券種を追加する。編集中のときは、その券種を更新する。
     */
    public function saveTicketType(): void
    {
        $this->authorize('update', $this->event);

        if ($this->editingTicketTypeId === null) {
            $this->event->ticketTypes()->create($this->ticketTypeForm->validatedPayload());
            $message = '券種を追加しました。';
        } else {
            // 購入と同じ行ロックの中で、確保済みの枚数を数えて更新する（検証のあとに購入が入って、定員を下回るのを防ぐ）
            DB::transaction(function (): void {
                $ticketType = $this->event->ticketTypes()->lockForUpdate()->findOrFail($this->editingTicketTypeId);

                $ticketType->update($this->ticketTypeForm->validatedPayload($ticketType->reservedQuantity()));
            });
            $message = '券種を更新しました。';
        }

        $this->cancelTicketTypeEdit();

        Flux::toast(variant: 'success', text: $message);
    }

    /**
     * 券種を削除する。注文のある券種と、公開中のイベントの最後の券種は削除できない。
     */
    public function deleteTicketType(int $ticketTypeId): void
    {
        $this->authorize('update', $this->event);

        $ticketType = $this->event->ticketTypes()->findOrFail($ticketTypeId);

        if ($ticketType->orderItems()->exists()) {
            Flux::toast(variant: 'danger', text: '注文のある券種は削除できません。');

            return;
        }

        if ($this->event->isPublished() && $this->event->ticketTypes()->count() <= 1) {
            Flux::toast(variant: 'danger', text: '公開中のイベントの最後の券種は削除できません。先に下書きに戻してください。');

            return;
        }

        $ticketType->delete();

        if ($this->editingTicketTypeId === $ticketType->id) {
            $this->cancelTicketTypeEdit();
        }

        Flux::toast(variant: 'success', text: '券種を削除しました。');
    }

    /**
     * 券種のフォームを空にして、追加用の状態に戻す。
     */
    public function cancelTicketTypeEdit(): void
    {
        $this->ticketTypeForm->reset();
        $this->resetValidation();
        $this->editingTicketTypeId = null;
    }

    /**
     * イベントを削除する。注文のあるイベントは削除できない。
     */
    public function deleteEvent(): void
    {
        $this->authorize('update', $this->event);

        if ($this->event->orders()->exists()) {
            Flux::toast(variant: 'danger', text: '注文があるため、イベントは削除できません。公開を止めるには、下書きに戻してください。');
            Flux::modal('delete-event')->close();

            return;
        }

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

    <div class="my-6 max-w-2xl space-y-6" data-test="ticket-types-section">
        <flux:heading level="2">券種</flux:heading>

        @if ($this->ticketTypes->isEmpty())
            <flux:text>券種がありません。下のフォームから追加してください。公開するには券種が1つ以上必要です。</flux:text>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>券種名</flux:table.column>
                    <flux:table.column>価格</flux:table.column>
                    <flux:table.column>定員</flux:table.column>
                    <flux:table.column>販売期間</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->ticketTypes as $ticketType)
                        <flux:table.row :key="$ticketType->id">
                            <flux:table.cell>
                                {{ $ticketType->name }}
                                <flux:badge size="sm" :color="$ticketType->isOnSale() ? 'green' : 'zinc'" inset="top bottom">
                                    {{ $ticketType->isOnSale() ? '販売中' : '販売期間外' }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>{{ number_format($ticketType->price) }}円</flux:table.cell>
                            <flux:table.cell>{{ number_format($ticketType->capacity) }}枚</flux:table.cell>
                            <flux:table.cell>
                                {{ $ticketType->sales_starts_at->isoFormat('M月D日(ddd) HH:mm') }}
                                〜 {{ $ticketType->sales_ends_at->isoFormat('M月D日(ddd) HH:mm') }}
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                <div class="flex justify-end gap-2">
                                    <flux:button size="sm" wire:click="editTicketType({{ $ticketType->id }})" data-test="edit-ticket-type-button">
                                        編集
                                    </flux:button>
                                    <flux:button
                                        size="sm"
                                        variant="danger"
                                        wire:click="deleteTicketType({{ $ticketType->id }})"
                                        wire:confirm="この券種を削除しますか？"
                                        :disabled="$ticketType->has_orders"
                                        data-test="delete-ticket-type-button"
                                    >
                                        削除
                                    </flux:button>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>

            @if ($this->ticketTypes->contains('has_orders', true))
                <flux:text data-test="ticket-type-delete-blocked-message">注文のある券種は削除できません。</flux:text>
            @endif
        @endif

        <form wire:submit="saveTicketType" class="space-y-6 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading level="3">{{ $editingTicketTypeId === null ? '券種を追加' : '券種を編集' }}</flux:heading>

            <flux:input wire:model="ticketTypeForm.name" label="券種名" type="text" placeholder="例: 一般、学生" />

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="ticketTypeForm.price" label="価格（円）" type="number" min="0" step="1" />
                <flux:input wire:model="ticketTypeForm.capacity" label="定員（枚）" type="number" min="1" step="1" />
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="ticketTypeForm.sales_starts_at" label="販売開始" type="datetime-local" />
                <flux:input wire:model="ticketTypeForm.sales_ends_at" label="販売終了" type="datetime-local" />
            </div>

            <div class="flex items-center gap-3">
                <flux:button variant="primary" type="submit" data-test="save-ticket-type-button">
                    {{ $editingTicketTypeId === null ? '追加する' : '更新する' }}
                </flux:button>

                @if ($editingTicketTypeId !== null)
                    <flux:button wire:click="cancelTicketTypeEdit" data-test="cancel-ticket-type-edit-button">キャンセル</flux:button>
                @endif
            </div>
        </form>
    </div>

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
                @if (! $event->isPublished() && $this->ticketTypes->isEmpty())
                    <flux:text class="mt-2 text-amber-600 dark:text-amber-400">公開するには、先に券種を1つ以上追加してください。</flux:text>
                @endif
                <flux:button class="mt-4" wire:click="togglePublish" data-test="toggle-publish-button">
                    {{ $event->isPublished() ? '下書きに戻す' : '公開する' }}
                </flux:button>
            @endif
        </div>

        <div>
            <flux:heading level="2">イベントの削除</flux:heading>

            @if ($this->hasOrders)
                <flux:text class="mt-2" data-test="delete-event-blocked-message">
                    注文があるため削除できません。公開を止めるには、下書きに戻してください。
                </flux:text>
                <flux:button variant="danger" class="mt-4" disabled data-test="delete-event-button">削除する</flux:button>
            @else
                <flux:text class="mt-2">削除すると元に戻せません。</flux:text>

                <flux:modal.trigger name="delete-event">
                    <flux:button variant="danger" class="mt-4" data-test="delete-event-button">削除する</flux:button>
                </flux:modal.trigger>
            @endif
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
