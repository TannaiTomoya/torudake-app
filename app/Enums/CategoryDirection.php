<?php

namespace App\Enums;

enum CategoryDirection: string
{
    case Expense = 'expense';
    case Income = 'income';

    public function label(): string
    {
        return match ($this) {
            self::Expense => '支出',
            self::Income => '収入',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Expense => 'warn',
            self::Income => 'ok',
        };
    }
}
