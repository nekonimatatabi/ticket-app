<?php

namespace App\Models;

use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organizer_id
 * @property string $title
 * @property string|null $description
 * @property string $venue
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property EventStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['title', 'description', 'venue', 'starts_at', 'ends_at'])]
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'status' => EventStatus::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    /**
     * @return HasMany<TicketType, $this>
     */
    public function ticketTypes(): HasMany
    {
        return $this->hasMany(TicketType::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @param  Builder<Event>  $query
     */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('status', EventStatus::Published);
    }

    /**
     * @param  Builder<Event>  $query
     */
    #[Scope]
    protected function upcoming(Builder $query): void
    {
        $query->where('ends_at', '>', now());
    }

    /**
     * 券種が1つ以上あれば公開できる。
     */
    public function canBePublished(): bool
    {
        return $this->ticketTypes()->exists();
    }

    public function isPublished(): bool
    {
        return $this->status === EventStatus::Published;
    }

    /**
     * 売上と販売枚数を、一覧用にまとめて取得する（sold_quantity、sales_amount）。支払い済みの注文だけが対象。
     *
     * @param  Builder<Event>  $query
     */
    #[Scope]
    protected function withSales(Builder $query): void
    {
        if ($query->getQuery()->columns === null) {
            $query->select('events.*');
        }

        $query->addSelect([
            'sold_quantity' => OrderItem::query()
                ->selectRaw('coalesce(sum(order_items.quantity), 0)')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereColumn('orders.event_id', 'events.id')
                ->where('orders.status', OrderStatus::Paid),
            'sales_amount' => Order::query()
                ->selectRaw('coalesce(sum(orders.total_amount), 0)')
                ->whereColumn('orders.event_id', 'events.id')
                ->where('orders.status', OrderStatus::Paid),
        ]);
    }

    /**
     * 販売枚数 = 支払い済みの注文の明細の枚数の合計。withSales() で読み込んでいれば、追加の問い合わせをしない。
     */
    public function soldQuantity(): int
    {
        if (array_key_exists('sold_quantity', $this->attributes)) {
            return (int) $this->attributes['sold_quantity'];
        }

        return (int) OrderItem::query()
            ->whereHas('order', fn (Builder $orders) => $orders
                ->where('event_id', $this->id)
                ->where('status', OrderStatus::Paid))
            ->sum('quantity');
    }

    /**
     * 売上（円） = 支払い済みの注文の合計金額の合計。取り消し・返金・支払い待ちは含めない。
     */
    public function salesAmount(): int
    {
        if (array_key_exists('sales_amount', $this->attributes)) {
            return (int) $this->attributes['sales_amount'];
        }

        return (int) $this->orders()->where('status', OrderStatus::Paid)->sum('total_amount');
    }
}
