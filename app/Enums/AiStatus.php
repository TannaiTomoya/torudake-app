<?php

namespace App\Enums;

enum AiStatus: string
{
    case Waiting = 'waiting';
    case Reading = 'reading';
    case Done = 'done';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Waiting => '待機中',
            self::Reading => '読み取り中',
            self::Done => '完了',
            self::Failed => '失敗',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Waiting => 'muted',
            self::Reading => 'brand',
            self::Done => 'ok',
            self::Failed => 'ng',
        };
    }
}
