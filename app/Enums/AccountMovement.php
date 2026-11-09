<?php

namespace App\Enums;

/**
 * 仕訳の1行の勘定が、その取引で増えたか減ったか。実際の借方・貸方から逆算しない（取引の意味から決める）。
 */
enum AccountMovement: string
{
    case Increase = 'increase';
    case Decrease = 'decrease';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Increase => '増加',
            self::Decrease => '減少',
            self::Unknown => '不明',
        };
    }
}
