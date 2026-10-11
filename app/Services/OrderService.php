<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\PurchaseException;
use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class OrderService
{
    /**
     * 支払い待ちの注文が枚数を押さえておく時間（分）。
     */
    public const PENDING_MINUTES = 15;

    /**
     * 1つの券種で、1回の注文に入れられる最大枚数。
     */
    public const MAX_QUANTITY = 10;

    /**
     * 支払い待ち（pending）の注文と明細を作る。
     *
     * 画面から渡る値は書き換えられるので、枚数・券種・販売期間・残り枚数は、ここで必ず確認する。
     * 金額は受け取らず、DB の券種の価格から計算する。確認で1つでも駄目なら、何も作らない。
     *
     * @param  array<int|string, mixed>  $quantities  [券種ID => 枚数]。枚数が 0 の券種は「買わない」として無視する
     *
     * @throws PurchaseException
     */
    public function createOrder(User $user, Event $event, array $quantities): Order
    {
        return DB::transaction(function () use ($user, $event, $quantities): Order {
            if (! $event->isPublished()) {
                throw PurchaseException::eventNotOnSale();
            }

            $selected = $this->selectedQuantities($quantities);
            $ticketTypes = $this->findTicketTypes($event, array_keys($selected));

            $totalAmount = 0;

            foreach ($selected as $ticketTypeId => $quantity) {
                $ticketType = $ticketTypes[$ticketTypeId];

                if (! $ticketType->isOnSale()) {
                    throw PurchaseException::notOnSale($ticketType);
                }

                if ($ticketType->remaining() < $quantity) {
                    throw PurchaseException::notEnoughRemaining($ticketType);
                }

                $totalAmount += $ticketType->price * $quantity;
            }

            $order = new Order;
            $order->user_id = $user->id;
            $order->event_id = $event->id;
            $order->status = OrderStatus::Pending;
            $order->total_amount = $totalAmount;
            $order->save();

            foreach ($selected as $ticketTypeId => $quantity) {
                $order->items()->create([
                    'ticket_type_id' => $ticketTypeId,
                    'quantity' => $quantity,
                    'unit_price' => $ticketTypes[$ticketTypeId]->price,
                ]);
            }

            return $order->load('items');
        });
    }

    /**
     * 渡された枚数を整数にそろえ、買う券種だけを残す。
     *
     * @param  array<int|string, mixed>  $quantities
     * @return array<int, int> [券種ID => 1〜MAX_QUANTITY の枚数]
     *
     * @throws PurchaseException
     */
    private function selectedQuantities(array $quantities): array
    {
        $selected = [];

        foreach ($quantities as $ticketTypeId => $value) {
            $quantity = $this->toInteger($value);

            if ($quantity === null || $quantity < 0 || $quantity > self::MAX_QUANTITY) {
                throw PurchaseException::invalidQuantity();
            }

            if (! is_int($ticketTypeId)) {
                throw PurchaseException::unknownTicketType();
            }

            if ($quantity > 0) {
                $selected[$ticketTypeId] = $quantity;
            }
        }

        if ($selected === []) {
            throw PurchaseException::noTicketSelected();
        }

        return $selected;
    }

    /**
     * int か、整数を表す文字列（"3"）だけを受け付ける。小数・数字以外・null は null を返す。
     */
    private function toInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);

            return $integer === false ? null : $integer;
        }

        return null;
    }

    /**
     * このイベントの券種だけを、残り枚数の集計つきで取得する。別のイベントの券種や存在しない ID はエラー。
     *
     * @param  array<int, int>  $ticketTypeIds
     * @return Collection<int, TicketType>
     *
     * @throws PurchaseException
     */
    private function findTicketTypes(Event $event, array $ticketTypeIds): Collection
    {
        $ticketTypes = $event->ticketTypes()
            ->whereKey($ticketTypeIds)
            ->withReservedQuantity()
            ->get()
            ->keyBy('id');

        if ($ticketTypes->count() !== count($ticketTypeIds)) {
            throw PurchaseException::unknownTicketType();
        }

        return $ticketTypes;
    }
}
