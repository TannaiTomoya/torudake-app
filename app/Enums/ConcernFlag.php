<?php

namespace App\Enums;

enum ConcernFlag: string
{
    case Amount = 'amount';
    case AmountMissing = 'amount_missing';
    case AiDuplicate = 'ai_duplicate';
    case AiPrivate = 'ai_private';
    case AiUnbalanced = 'ai_unbalanced';
    case Corrected = 'corrected';

    public function label(): string
    {
        return match ($this) {
            self::Amount => '金額が大きい',
            self::AmountMissing => '金額の記載なし',
            self::AiDuplicate => '重複の疑い',
            self::AiPrivate => '私的支出の疑い',
            self::AiUnbalanced => '貸借が合わない',
            self::Corrected => '経理がAIを直した',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Amount, self::AmountMissing => 'warn',
            self::AiDuplicate, self::AiPrivate, self::AiUnbalanced => 'ng',
            self::Corrected => 'brand',
        };
    }
}
