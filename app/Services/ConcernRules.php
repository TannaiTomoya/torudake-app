<?php

namespace App\Services;

use App\Enums\ConcernFlag;
use App\Models\Company;

/**
 * 社長に見てもらう「気になる仕訳」の印を決める（03 §4.5）。
 */
class ConcernRules
{
    /**
     * @param  array<string, mixed>|null  $aiCheck  L3 の検算結果
     * @return list<string>
     */
    public function for(Company $company, ?int $amountYen, ?array $aiCheck, bool $corrected, bool $amountMissing): array
    {
        $flags = [];

        if ($amountYen !== null && $amountYen >= $company->concern_amount_yen) {
            $flags[] = ConcernFlag::Amount;
        }

        if ($amountMissing) {
            $flags[] = ConcernFlag::AmountMissing;
        }

        if (($aiCheck['duplicate_suspected'] ?? false) === true) {
            $flags[] = ConcernFlag::AiDuplicate;
        }

        if (($aiCheck['private_suspected'] ?? false) === true) {
            $flags[] = ConcernFlag::AiPrivate;
        }

        if (($aiCheck['balanced'] ?? true) === false) {
            $flags[] = ConcernFlag::AiUnbalanced;
        }

        if ($corrected) {
            $flags[] = ConcernFlag::Corrected;
        }

        return array_map(fn (ConcernFlag $flag): string => $flag->value, $flags);
    }
}
