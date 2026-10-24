<?php

namespace App\Enums;

enum SourceStatus: string
{
    case New = 'new';
    case Drafted = 'drafted';
    case Confirmed = 'confirmed';
    case Ignored = 'ignored';

    public function label(): string
    {
        return match ($this) {
            self::New => '未処理',
            self::Drafted => 'AIの下書き',
            self::Confirmed => '確定',
            self::Ignored => '対象外',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'brand',
            self::Drafted => 'warn',
            self::Confirmed => 'ok',
            self::Ignored => 'muted',
        };
    }
}
