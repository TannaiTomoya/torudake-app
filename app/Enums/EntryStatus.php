<?php

namespace App\Enums;

enum EntryStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';

    public function label(): string
    {
        return match ($this) {
            self::Draft => '下書き',
            self::Approved => '承認済み',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'warn',
            self::Approved => 'ok',
        };
    }
}
