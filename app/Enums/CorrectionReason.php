<?php

namespace App\Enums;

enum CorrectionReason: string
{
    case Misread = 'misread';
    case WrongCategory = 'wrong_category';
    case WrongPayment = 'wrong_payment';
    case Private = 'private';
    case TaxRate = 'tax_rate';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Misread => '読み間違い',
            self::WrongCategory => '分類違い',
            self::WrongPayment => '支払い元違い',
            self::Private => '私的支出',
            self::TaxRate => '税率違い',
            self::Other => 'その他',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Misread => 'warn',
            self::WrongCategory => 'brand',
            self::WrongPayment => 'brand',
            self::Private => 'ng',
            self::TaxRate => 'ink-sub',
            self::Other => 'muted',
        };
    }
}
