<?php

namespace App\Enums;

enum EventStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Unpublished = 'unpublished';

    public function label(): string
    {
        return match ($this) {
            self::Draft => '下書き',
            self::Published => '公開中',
            self::Unpublished => '非公開',
        };
    }

    /**
     * Flux のバッジに渡す色。
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'zinc',
            self::Published => 'green',
            self::Unpublished => 'red',
        };
    }
}
