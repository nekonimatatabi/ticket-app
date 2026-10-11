<?php

namespace App\Models;

use Database\Factories\TicketTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
     * 現在が販売期間内（開始と終了を含む）かどうか。
     */
    public function isOnSale(): bool
    {
        return now()->between($this->sales_starts_at, $this->sales_ends_at);
    }
}
