<?php

namespace App\Services;

use App\Enums\CheckInStatus;
use App\Models\Ticket;

/**
 * 受付の結果。ticket は、このイベントのチケットで結果が分かったときだけ入る
 * （見つからない・別のイベントのときは null。別のイベントのチケットの中身を見せないため）。
 */
final readonly class CheckInResult
{
    public function __construct(
        public CheckInStatus $status,
        public ?Ticket $ticket = null,
    ) {}
}
