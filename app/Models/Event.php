<?php

namespace App\Models;

use App\Enums\EventStatus;
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
}
