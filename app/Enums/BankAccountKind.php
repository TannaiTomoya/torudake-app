<?php

namespace App\Enums;

enum BankAccountKind: string
{
    case Cash = 'cash';
    case Bank = 'bank';
    case Card = 'card';
    case Reimbursement = 'reimbursement';

    public function label(): string
    {
        return match ($this) {
            self::Cash => '現金',
            self::Bank => '銀行',
            self::Card => 'カード',
            self::Reimbursement => '立替',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Cash => 'ok',
            self::Bank => 'ink',
            self::Card => 'warn',
            self::Reimbursement => 'brand',
        };
    }
}
