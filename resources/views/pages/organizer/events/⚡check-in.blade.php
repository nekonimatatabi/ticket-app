<?php

use App\Enums\CheckInStatus;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\User;
use App\Services\CheckInService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('受付')] class extends Component {
    /**
     * 1人が1分間に受付できる回数。QR を読み取り続けても足りる数にして、総当たりだけを止める。
     */
    private const MAX_CHECK_INS_PER_MINUTE = 120;

    public Event $event;

    /**
     * 手入力欄のコード（USB のバーコードリーダーもここに入力される）。
     */
    public string $code = '';

    /**
     * 直近の受付の結果。画面から書き換えられないよう Locked にする。
     */
    #[Locked]
    public ?string $resultStatus = null;

    #[Locked]
    public ?int $resultTicketId = null;

    #[Locked]
    public ?string $notice = null;

    /**
     * 受付ができるのは、イベントの所有者と管理者だけ。ほかの主催者には 403 を返す。
     */
    public function mount(Event $event): void
    {
        $this->authorize('checkIn', $event);

        $this->event = $event;
    }

    /**
     * コードを受付する。カメラの読み取り（ticket-scanner.js）から呼ばれる。
     */
    public function checkIn(string $submittedCode): void
    {
        $this->authorize('checkIn', $this->event);

        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $this->notice = null;
        $this->resultTicketId = null;

        $limiterKey = "check-in:{$user->id}";

        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_CHECK_INS_PER_MINUTE)) {
            $this->resultStatus = null;
            $this->notice = '操作が多すぎます。しばらくしてから、もう一度お試しください。';

            return;
        }

        RateLimiter::hit($limiterKey, 60);

        $result = app(CheckInService::class)->checkIn($this->event, mb_substr($submittedCode, 0, 64), $user);

        $this->resultStatus = $result->status->value;
        $this->resultTicketId = $result->ticket?->id;
    }

    /**
     * 手入力欄のコードを受付して、欄を空にする。
     */
    public function submitCode(): void
    {
        $this->checkIn($this->code);

        $this->code = '';
    }

    /**
     * 誤って受付したチケットを、未受付に戻す。
     */
    public function undoCheckIn(int $ticketId): void
    {
        $this->authorize('checkIn', $this->event);

        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $undone = app(CheckInService::class)->undo($this->event, $ticketId, $user);

        $this->resultStatus = null;
        $this->resultTicketId = null;
        $this->notice = $undone ? '受付を取り消しました。' : '取り消せるチケットが見つかりませんでした。';
    }

    #[Computed]
    public function status(): ?CheckInStatus
    {
        return $this->resultStatus === null ? null : CheckInStatus::tryFrom($this->resultStatus);
    }

    /**
     * 直近の結果のチケット。このイベントのチケットだけを取得する（別のイベントのチケットの中身は出さない）。
     */
    #[Computed]
    public function resultTicket(): ?Ticket
    {
        if ($this->resultTicketId === null) {
            return null;
        }

        return Ticket::query()
            ->with('orderItem.order.user', 'orderItem.ticketType', 'checkedInBy')
            ->whereKey($this->resultTicketId)
            ->whereHas('orderItem.order', fn (Builder $orders) => $orders->where('event_id', $this->event->id))
            ->first();
    }

    /**
     * @return array{checked_in: int, issued: int, by_ticket_type: Collection<int, Ticket>}
     */
    #[Computed]
    public function stats(): array
    {
        return app(CheckInService::class)->stats($this->event);
    }

    /**
     * @return Collection<int, Ticket>
     */
    #[Computed]
    public function recent(): Collection
    {
        return app(CheckInService::class)->recent($this->event);
    }
}; ?>

<section class="w-full max-w-3xl">
    @vite('resources/js/ticket-scanner.js')

    <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('organizer.events.orders', $event)" wire:navigate>
        売上と注文に戻る
    </flux:button>

    <flux:heading size="xl" level="1" class="mt-4">受付</flux:heading>
    <flux:text class="mt-2">{{ $event->title }}（{{ $event->starts_at->isoFormat('M月D日(ddd) HH:mm') }}）</flux:text>

    <div class="mt-6 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
        <flux:text>受付済み / 発行済み</flux:text>
        <flux:heading size="xl" data-test="check-in-count">
            {{ number_format($this->stats['checked_in']) }} / {{ number_format($this->stats['issued']) }}枚
        </flux:heading>

        @if ($this->stats['by_ticket_type']->isNotEmpty())
            <ul class="mt-3 space-y-1 text-sm text-zinc-600 dark:text-zinc-400" data-test="check-in-by-ticket-type">
                @foreach ($this->stats['by_ticket_type'] as $row)
                    <li>{{ $row->name }}: {{ number_format((int) $row->checked_in) }} / {{ number_format((int) $row->issued) }}枚</li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="mt-6" x-data="{
        scanning: false,
        error: '',
        failed: false,
        scanner: null,
        async start() {
            this.error = '';

            if (! window.TicketScanner) {
                this.error = '読み取りの機能を読み込み中です。少し待ってから、もう一度お試しください。';

                return;
            }

            try {
                // 読み取ったら、結果が出るまでカメラを一時停止する（続けて読んで、前の結果が上書きされないように）。
                // 通信に失敗したら failed にして、前の人の結果を隠し、読み取りをやり直してもらう。
                this.scanner = new window.TicketScanner(
                    'ticket-scanner-view',
                    (code) => {
                        this.failed = false;

                        return $wire.checkIn(code);
                    },
                    { onError: () => { this.failed = true; } },
                );
                this.scanning = true;
                await this.$nextTick();
                await this.scanner.start();
            } catch (error) {
                this.scanning = false;
                this.error = 'カメラを使えませんでした。ブラウザでカメラの使用を許可するか、下の入力欄にコードを入力してください。';
            }
        },
        async stop() {
            await this.scanner?.stop();
            this.scanning = false;
        },
    }" x-on:beforeunload.window="scanner?.stop()">
        <div class="flex items-center gap-3">
            <flux:button variant="primary" icon="qr-code" x-show="! scanning" x-on:click="start()" data-test="start-scan-button">
                カメラで読み取る
            </flux:button>
            <flux:button x-show="scanning" x-cloak x-on:click="stop()" data-test="stop-scan-button">
                読み取りを止める
            </flux:button>
        </div>

        <flux:text class="mt-2 text-sm" x-text="error" x-show="error" x-cloak data-test="scan-error"></flux:text>

        <div wire:ignore x-show="scanning" x-cloak class="mt-4 max-w-sm overflow-hidden rounded-lg">
            <div id="ticket-scanner-view"></div>
        </div>

        <flux:text class="mt-2 text-sm text-zinc-500">カメラは、https の画面（開発中は localhost）でだけ使えます。</flux:text>

    <form wire:submit="submitCode" x-on:submit="failed = false" class="mt-6 flex items-end gap-3">
        <div class="grow">
            <flux:input wire:model="code" label="コードを入力" placeholder="チケットのコード（26 文字）" autocomplete="off" autocapitalize="characters" data-test="code-input" />
        </div>
        <flux:button type="submit" variant="primary" data-test="submit-code-button">受付する</flux:button>
    </form>

    <flux:callout class="mt-6" variant="danger" icon="exclamation-triangle" x-show="failed" x-cloak data-test="scan-failed">
        <flux:callout.heading>通信に失敗しました</flux:callout.heading>
        <flux:callout.text>このチケットは受付できていません。通信の状態を確かめて、もう一度読み取ってください。</flux:callout.text>
    </flux:callout>

    <flux:callout class="mt-6" icon="arrow-path" wire:loading wire:target="checkIn,submitCode" data-test="check-in-loading">
        <flux:callout.text>確認しています…</flux:callout.text>
    </flux:callout>

    <div wire:loading.remove wire:target="checkIn,submitCode">
    <div x-show="! failed">
    @if ($this->notice)
        <flux:callout class="mt-6" icon="information-circle" data-test="check-in-notice">
            <flux:callout.text>{{ $this->notice }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($this->status)
        <flux:callout class="mt-6" :variant="$this->status->calloutVariant()" data-test="check-in-result" data-status="{{ $this->status->value }}">
            <flux:callout.heading>{{ $this->status->label() }}</flux:callout.heading>

            @if ($this->resultTicket)
                <flux:callout.text>
                    {{ $this->resultTicket->orderItem->ticketType->name }}
                    ／ {{ $this->resultTicket->orderItem->order->user->name }}様

                    @if ($this->status === CheckInStatus::AlreadyCheckedIn && $this->resultTicket->checked_in_at)
                        <br>
                        受付日時: {{ $this->resultTicket->checked_in_at->isoFormat('M月D日(ddd) HH:mm') }}
                        @if ($this->resultTicket->checkedInBy)
                            （担当: {{ $this->resultTicket->checkedInBy->name }}）
                        @endif
                    @endif
                </flux:callout.text>

                @if ($this->status === CheckInStatus::Success)
                    <div class="mt-3">
                        <flux:button size="sm" wire:click="undoCheckIn({{ $this->resultTicket->id }})" wire:confirm="この受付を取り消しますか？" data-test="undo-result-button">
                            取り消す
                        </flux:button>
                    </div>
                @endif
            @endif
        </flux:callout>
    @endif
    </div>
    </div>
    </div>

    <flux:heading size="lg" level="2" class="mt-10">直近の受付</flux:heading>

    @if ($this->recent->isEmpty())
        <flux:text class="mt-4" data-test="no-recent">まだ受付したチケットはありません。</flux:text>
    @else
        <flux:table class="mt-4">
            <flux:table.columns>
                <flux:table.column>受付日時</flux:table.column>
                <flux:table.column>券種</flux:table.column>
                <flux:table.column>購入者</flux:table.column>
                <flux:table.column>担当</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->recent as $ticket)
                    <flux:table.row :key="$ticket->id" data-test="recent-row">
                        <flux:table.cell>{{ $ticket->checked_in_at->isoFormat('M月D日(ddd) HH:mm') }}</flux:table.cell>
                        <flux:table.cell>{{ $ticket->orderItem->ticketType->name }}</flux:table.cell>
                        <flux:table.cell>{{ $ticket->orderItem->order->user->name }}</flux:table.cell>
                        <flux:table.cell>{{ $ticket->checkedInBy?->name }}</flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:button size="sm" wire:click="undoCheckIn({{ $ticket->id }})" wire:confirm="この受付を取り消しますか？" data-test="undo-button">
                                取り消す
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</section>
