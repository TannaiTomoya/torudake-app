<?php

namespace App\Enums;

enum Side: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    public function label(): string
    {
        return match ($this) {
            self::Debit => '借方',
            self::Credit => '貸方',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Debit => 'ink',
            self::Credit => 'ink-sub',
        };
    }
}
