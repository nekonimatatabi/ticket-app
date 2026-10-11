<?php

namespace App\Exceptions;

use App\Models\TicketType;
use App\Services\OrderService;
use RuntimeException;

/**
 * 購入できない理由。メッセージは画面にそのまま出せる日本語。
 */
class PurchaseException extends RuntimeException
{
    public static function eventNotOnSale(): self
    {
        return new self('このイベントは現在購入できません。');
    }

    public static function noTicketSelected(): self
    {
        return new self('チケットを1枚以上選んでください。');
    }

    public static function invalidQuantity(): self
    {
        return new self('枚数は1〜'.OrderService::MAX_QUANTITY.'枚で指定してください。');
    }

    public static function unknownTicketType(): self
    {
        return new self('選択された券種が見つかりません。');
    }

    public static function notOnSale(TicketType $ticketType): self
    {
        return new self("「{$ticketType->name}」は販売期間外です。");
    }

    public static function notEnoughRemaining(TicketType $ticketType): self
    {
        return new self("「{$ticketType->name}」は残り枚数が足りません（残り{$ticketType->remaining()}枚）。");
    }
}
