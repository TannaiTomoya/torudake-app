<?php

namespace App\Enums;

enum ReportStatus: string
{
    case Reading = 'reading';
    case Review = 'review';
    case Saved = 'saved';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Reading => 'AIが読んでいます',
            self::Review => '確かめて保存してください',
            self::Saved => '保存しました',
            self::Failed => '読めませんでした',
        };
    }
}
