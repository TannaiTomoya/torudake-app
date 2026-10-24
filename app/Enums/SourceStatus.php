<?php

namespace App\Enums;

enum SourceStatus: string
{
    case New = 'new';
    case Drafted = 'drafted';
    case Journaled = 'journaled';
    case Ignored = 'ignored';

    public function label(): string
    {
        return match ($this) {
            self::New => '未処理',
            self::Drafted => '下書きあり',
            self::Journaled => '仕訳済み',
            self::Ignored => '対象外',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'brand',
            self::Drafted => 'warn',
            self::Journaled => 'ok',
            self::Ignored => 'muted',
        };
    }
}
