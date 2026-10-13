<?php

use App\Exceptions\PurchaseException;
use App\Models\Event;
use App\Models\TicketType;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\OrderService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Stripe\Exception\ApiErrorException;

new #[Title('購入手続き')] class extends Component {
    public Event $event;

    /**
     * 購入できなかった理由。PurchaseException のメッセージをそのまま表示する。
     */
    public ?string $errorMessage = null;

    /**
     * 公開中のイベントは誰でも見られる。選んだ枚数が無ければ、詳細ページへ戻す。
     */
    public function mount(Event $event): void
    {
        $this->authorize('view', $event);

        $this->event = $event;

        if ($this->selectedQuantities() === []) {
            $this->redirectRoute('events.show', $event, navigate: true);
        }
    }

    /**
     * 詳細ページで選んだ枚数（セッション）。画面の表示用で、買えるかどうかの確認は createOrder() が行う。
     *
     * @return array<int, int>
     */
    private function selectedQuantities(): array
    {
        $selected = [];

        foreach (session("purchase.{$this->event->id}", []) as $ticketTypeId => $quantity) {
            if (is_int($ticketTypeId) && is_int($quantity)) {
                $selected[$ticketTypeId] = $quantity;
            }
        }

        return $selected;
    }

    /**
     * 表に出す行（券種・枚数・小計）。単価は表示用に DB の券種から取る。
     *
     * @return Collection<int, array{ticketType: TicketType, quantity: int, subtotal: int}>
     */
    #[Computed]
    public function lines(): Collection
    {
        $selected = $this->selectedQuantities();

        return $this->event->ticketTypes()
            ->whereKey(array_keys($selected))
            ->orderBy('sales_starts_at')
            ->orderBy('id')
            ->get()
            ->map(fn (TicketType $ticketType) => [
                'ticketType' => $ticketType,
                'quantity' => $selected[$ticketType->id],
                'subtotal' => $ticketType->price * $selected[$ticketType->id],
            ]);
    }

    #[Computed]
    public function total(): int
    {
        return $this->lines->sum('subtotal');
    }

    /**
     * 注文（支払い待ち）を作り、Stripe の決済ページへ進む。合計 0 円なら Stripe を通さず、その場で支払い済みにして完了画面へ進む。
     * 買えない場合は理由を表示する。決済ページを開けなかった場合は、注文を取り消して枠を戻し、選んだ枚数は残す（もう一度押せる）。
     */
    public function proceedToPayment(): void
    {
        $this->authorize('view', $this->event);

        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $selected = $this->selectedQuantities();

        if ($selected === []) {
            $this->redirectRoute('events.show', $this->event, navigate: true);

            return;
        }

        try {
            $order = app(OrderService::class)->createOrder($user, $this->event, $selected);
        } catch (PurchaseException $exception) {
            $this->errorMessage = $exception->getMessage();

            return;
        }

        if ($order->total_amount === 0) {
            session()->forget("purchase.{$this->event->id}");

            app(OrderService::class)->markAsPaid($order, null);

            $this->redirectRoute('orders.complete', $order, navigate: true);

            return;
        }

        try {
            $checkoutUrl = app(CheckoutService::class)->createSession($order);
        } catch (ApiErrorException) {
            app(OrderService::class)->cancel($order);
            $this->errorMessage = '決済ページを開けませんでした。時間をおいてお試しください。';

            return;
        }

        session()->forget("purchase.{$this->event->id}");

        $this->redirect($checkoutUrl);
    }
}; ?>

<section class="w-full max-w-3xl">
    <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('events.show', $event)" wire:navigate>
        イベントに戻る
    </flux:button>

    <flux:heading size="xl" level="1" class="mt-4">購入手続き</flux:heading>
    <flux:text class="mt-2">{{ $event->title }}（{{ $event->starts_at->isoFormat('M月D日(ddd) HH:mm') }}）</flux:text>

    @if ($errorMessage)
        <flux:callout variant="danger" icon="exclamation-triangle" class="mt-6" data-test="purchase-error">
            <flux:callout.heading>購入できません</flux:callout.heading>
            <flux:callout.text>
                {{ $errorMessage }}
                <flux:callout.link :href="route('events.show', $event)" wire:navigate>券種を選び直す</flux:callout.link>
            </flux:callout.text>
        </flux:callout>
    @endif

    <flux:table class="mt-6">
        <flux:table.columns>
            <flux:table.column>券種</flux:table.column>
            <flux:table.column>単価</flux:table.column>
            <flux:table.column>枚数</flux:table.column>
            <flux:table.column align="end">小計</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->lines as $line)
                <flux:table.row :key="$line['ticketType']->id" data-test="purchase-line">
                    <flux:table.cell>{{ $line['ticketType']->name }}</flux:table.cell>
                    <flux:table.cell>{{ number_format($line['ticketType']->price) }}円</flux:table.cell>
                    <flux:table.cell>{{ $line['quantity'] }}枚</flux:table.cell>
                    <flux:table.cell align="end">{{ number_format($line['subtotal']) }}円</flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <div class="mt-4 flex items-center justify-end gap-3">
        <flux:text>合計</flux:text>
        <flux:heading size="lg" data-test="purchase-total">{{ number_format($this->total) }}円</flux:heading>
    </div>

    <div class="mt-8 flex justify-end">
        <flux:button variant="primary" wire:click="proceedToPayment" wire:loading.attr="disabled" data-test="proceed-to-payment-button">
            支払いへ進む
        </flux:button>
    </div>
</section>
