<?php

namespace App\Enums;

/**
 * 予定が入金か支払いか（#67）。値は App\Support\ScheduleDelay の DIRECTION_IN／DIRECTION_OUT と同じ。
 */
enum ScheduleDirection: string
{
    case In = 'in';
    case Out = 'out';

    public function label(): string
    {
        return match ($this) {
            self::In => '入金',
            self::Out => '支払い',
        };
    }

    public function settledLabel(): string
    {
        return match ($this) {
            self::In => '入金済み',
            self::Out => '支払済み',
        };
    }
}
