<?php

namespace App\Services;

use App\Enums\CheckInStatus;
use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * 受付（チェックイン）。当日、チケットの QR コード（チケットのコード）を読み取って、入場を確認する。
 */
class CheckInService
{
    /**
     * コードのチケットを、このイベントの受付済みにする。
     *
     * 判定は、コードの形 → 存在 → このイベントのチケットか → 注文が支払い済みか、の順。
     * 受付済みにするのは「checked_in_at が空の行だけ更新する」条件付き UPDATE で、2台の端末で同時に読んでも、
     * 成功するのは1台だけ（支払いの確定と同じ考え方）。ほかの端末が先に受付した場合は AlreadyCheckedIn。
     */
    public function checkIn(Event $event, string $code, User $staff): CheckInResult
    {
        $normalized = Ticket::normalizeCode($code);

        if ($normalized === null) {
            return new CheckInResult(CheckInStatus::NotFound);
        }

        $ticket = Ticket::query()
            ->with('orderItem.order.user', 'orderItem.ticketType', 'checkedInBy')
            ->where('code', $normalized)
            ->first();

        if ($ticket === null) {
            return new CheckInResult(CheckInStatus::NotFound);
        }

        if ($ticket->orderItem->order->event_id !== $event->id) {
            return new CheckInResult(CheckInStatus::WrongEvent);
        }

        if ($ticket->orderItem->order->status !== OrderStatus::Paid) {
            return new CheckInResult(CheckInStatus::NotPaid, $ticket);
        }

        $updated = Ticket::query()
            ->whereKey($ticket->id)
            ->whereNull('checked_in_at')
            ->update(['checked_in_at' => now(), 'checked_in_by' => $staff->id]);

        $ticket->refresh()->load('checkedInBy');

        return new CheckInResult($updated === 1 ? CheckInStatus::Success : CheckInStatus::AlreadyCheckedIn, $ticket);
    }

    /**
     * 受付を取り消す（誤って受付した場合）。このイベントのチケットで、受付済みのものだけが対象。
     *
     * @return bool 取り消せたら true。別のイベントのチケット・存在しない・未受付なら false
     */
    public function undo(Event $event, int $ticketId, User $staff): bool
    {
        $undone = Ticket::query()
            ->whereKey($ticketId)
            ->whereNotNull('checked_in_at')
            ->whereHas('orderItem.order', fn (Builder $orders) => $orders->where('event_id', $event->id))
            ->update(['checked_in_at' => null, 'checked_in_by' => null]);

        if ($undone > 0) {
            Log::info('受付を取り消しました。', ['ticket_id' => $ticketId, 'event_id' => $event->id, 'staff_id' => $staff->id]);
        }

        return $undone > 0;
    }

    /**
     * 受付済み / 発行済みの枚数。支払い済みの注文のチケットだけを数える。
     *
     * @return array{checked_in: int, issued: int, by_ticket_type: Collection<int, Ticket>}
     *                                                                                      by_ticket_type は、券種ごとの ticket_type_id・name・issued・checked_in を持つ（Ticket モデルの属性として）
     */
    public function stats(Event $event): array
    {
        /** @var Collection<int, Ticket> $byTicketType */
        $byTicketType = Ticket::query()
            ->join('order_items', 'order_items.id', '=', 'tickets.order_item_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('ticket_types', 'ticket_types.id', '=', 'order_items.ticket_type_id')
            ->where('orders.event_id', $event->id)
            ->where('orders.status', OrderStatus::Paid)
            ->groupBy('ticket_types.id', 'ticket_types.name')
            ->orderBy('ticket_types.id')
            ->selectRaw('ticket_types.id as ticket_type_id, ticket_types.name as name, count(tickets.id) as issued, count(tickets.checked_in_at) as checked_in')
            ->get();

        return [
            'checked_in' => (int) $byTicketType->sum('checked_in'),
            'issued' => (int) $byTicketType->sum('issued'),
            'by_ticket_type' => $byTicketType,
        ];
    }

    /**
     * 直近に受付したチケットを、新しい順に取得する。
     *
     * @return Collection<int, Ticket>
     */
    public function recent(Event $event, int $limit = 10): Collection
    {
        return Ticket::query()
            ->with('orderItem.order.user', 'orderItem.ticketType', 'checkedInBy')
            ->whereNotNull('checked_in_at')
            ->whereHas('orderItem.order', fn (Builder $orders) => $orders->where('event_id', $event->id))
            ->orderByDesc('checked_in_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
