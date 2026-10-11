<?php

namespace App\Enums;

enum UserRole: string
{
    case Customer = 'customer';
    case Organizer = 'organizer';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Customer => '参加者',
            self::Organizer => '主催者',
            self::Admin => '管理者',
        };
    }

    /**
     * 会員登録で選べるロール。
     *
     * @return array<int, self>
     */
    public static function registrable(): array
    {
        return [self::Customer, self::Organizer];
    }
}
