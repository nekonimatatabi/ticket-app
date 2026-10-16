<?php

namespace App\Models;

use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * チケット。支払いが確定したときに、明細の枚数分だけ1枚ずつ発行する。code は1枚ごとの固有コード（ULID）。
 * 受付（入場の確認）をすると checked_in_at と checked_in_by が入る。受付の状態はサーバー側で決める値なので、
 * マス代入は許可しない（CheckInService が明示的に更新する）。
 *
 * @property int $id
 * @property int $order_item_id
 * @property string $code
 * @property Carbon|null $checked_in_at
 * @property int|null $checked_in_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['code'])]
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    /**
     * チケットのコード（ULID）の形。Crockford の Base32（大文字。I・L・O・U は使わない）26 文字。
     */
    public const CODE_PATTERN = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    /**
     * 入力されたコードを、比較できる形にそろえる（前後の空白を除き、大文字にする）。コードの形でなければ null。
     */
    public static function normalizeCode(string $code): ?string
    {
        $code = strtoupper(trim($code));

        return preg_match(self::CODE_PATTERN, $code) === 1 ? $code : null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'checked_in_at' => 'datetime',
        ];
    }

    /**
     * 受付済み（入場済み）か。
     */
    public function isCheckedIn(): bool
    {
        return $this->checked_in_at !== null;
    }

    /**
     * 受付をした人。
     *
     * @return BelongsTo<User, $this>
     */
    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
