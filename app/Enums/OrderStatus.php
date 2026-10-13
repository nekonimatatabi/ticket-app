<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => '支払い待ち',
            self::Paid => '支払い済み',
            self::Cancelled => 'キャンセル',
            self::Refunded => '返金済み',
        };
    }

    /**
     * Flux のバッジに渡す色。
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Paid => 'green',
            self::Cancelled => 'zinc',
            self::Refunded => 'red',
        };
    }
}
