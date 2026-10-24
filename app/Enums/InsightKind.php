<?php

namespace App\Enums;

enum InsightKind: string
{
    case Brief = 'brief';
    case Forecast = 'forecast';

    public function label(): string
    {
        return match ($this) {
            self::Brief => '今月のひとこと',
            self::Forecast => '来月の見込み',
        };
    }

    public function task(): AiTask
    {
        return match ($this) {
            self::Brief => AiTask::OwnerBrief,
            self::Forecast => AiTask::Forecast,
        };
    }
}
