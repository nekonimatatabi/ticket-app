<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Services\OrderService;
use Database\Factories\TicketTypeFactory;
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
 * @property int $event_id
 * @property string $name
 * @property int $price
 * @property int $capacity
 * @property Carbon $sales_starts_at
 * @property Carbon $sales_ends_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'price', 'capacity', 'sales_starts_at', 'sales_ends_at'])]
class TicketType extends Model
{
    /** @use HasFactory<TicketTypeFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'capacity' => 'integer',
            'sales_starts_at' => 'datetime',
            'sales_ends_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * 残り枚数 = 定員 − 支払い済みの枚数 − PENDING_MINUTES 分以内の支払い待ちの枚数。
     * withReservedQuantity() で読み込んでいれば、追加の問い合わせをしない。
     */
    public function remaining(): int
    {
        $reserved = array_key_exists('reserved_quantity', $this->attributes)
            ? (int) $this->attributes['reserved_quantity']
            : $this->reservedQuantity();

        return max(0, $this->capacity - $reserved);
    }

    /**
     * 枚数を押さえている注文の明細の合計を、まとめて取得する（reserved_quantity）。
     *
     * @param  Builder<TicketType>  $query
     */
    #[Scope]
    protected function withReservedQuantity(Builder $query): void
    {
        $query->withSum(
            ['orderItems as reserved_quantity' => fn (Builder $items) => $items->whereHas('order', self::onlyOrdersReservingStock(...))],
            'quantity',
        );
    }

    private function reservedQuantity(): int
    {
        return (int) $this->orderItems()
            ->whereHas('order', self::onlyOrdersReservingStock(...))
            ->sum('quantity');
    }

    /**
     * 枚数を押さえている注文だけに絞る。支払い済みか、PENDING_MINUTES 分以内に作られた支払い待ち。
     *
     * @template TOrder of Model
     *
     * @param  Builder<TOrder>  $orders
     */
    private static function onlyOrdersReservingStock(Builder $orders): void
    {
        $orders->where(function (Builder $query) {
            $query->where('status', OrderStatus::Paid)
                ->orWhere(fn (Builder $pending) => $pending
                    ->where('status', OrderStatus::Pending)
                    ->where('created_at', '>=', now()->subMinutes(OrderService::PENDING_MINUTES)));
        });
    }

    /**
     * 現在が販売期間内（開始と終了を含む）かどうか。
     */
    public function isOnSale(): bool
    {
        return now()->between($this->sales_starts_at, $this->sales_ends_at);
    }
}
