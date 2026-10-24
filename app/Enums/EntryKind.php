<?php

namespace App\Enums;

enum EntryKind: string
{
    case Normal = 'normal';
    case Opening = 'opening';

    public function label(): string
    {
        return match ($this) {
            self::Normal => '通常',
            self::Opening => '期首残高',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Normal => 'ink',
            self::Opening => 'muted',
        };
    }
}
