<?php

namespace App\Enums;

/**
 * 受付（チェックイン）の結果。
 */
enum CheckInStatus: string
{
    case Success = 'success';
    case AlreadyCheckedIn = 'already_checked_in';
    case NotFound = 'not_found';
    case WrongEvent = 'wrong_event';
    case NotPaid = 'not_paid';

    public function label(): string
    {
        return match ($this) {
            self::Success => '受付しました',
            self::AlreadyCheckedIn => 'すでに受付済みです',
            self::NotFound => 'チケットが見つかりません',
            self::WrongEvent => '別のイベントのチケットです',
            self::NotPaid => 'お支払いが完了していないチケットです',
        };
    }

    /**
     * Flux の callout / バッジに渡す色。
     */
    public function color(): string
    {
        return match ($this) {
            self::Success => 'green',
            self::AlreadyCheckedIn => 'amber',
            self::NotFound, self::WrongEvent, self::NotPaid => 'red',
        };
    }

    /**
     * Flux の callout に渡す variant。
     */
    public function calloutVariant(): string
    {
        return match ($this) {
            self::Success => 'success',
            self::AlreadyCheckedIn => 'warning',
            self::NotFound, self::WrongEvent, self::NotPaid => 'danger',
        };
    }

    public function isSuccess(): bool
    {
        return $this === self::Success;
    }
}
