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
    case AddresseeOtherCompany = 'addressee_other_company';
    case AddresseePerson = 'addressee_person';
    case LowConfidence = 'low_confidence';

    public function label(): string
    {
        return match ($this) {
            self::AddresseeOtherCompany => '宛名が別の会社（他社の領収証かも）',
            self::AddresseePerson => '宛名が個人名（私的な支出かも）',
            self::LowConfidence => '読み取りの自信が低い',
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
            self::Amount, self::AmountMissing, self::LowConfidence => 'warn',
            self::AiDuplicate, self::AiPrivate, self::AiUnbalanced, self::AddresseeOtherCompany, self::AddresseePerson => 'ng',
            self::Corrected => 'brand',
        };
    }
}
