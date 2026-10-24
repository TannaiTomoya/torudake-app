<?php

namespace App\Services;

use App\Models\AiCorrection;
use App\Models\BankAccount;
use App\Models\Company;

/**
 * 人が直した支払い元を、同じ店の次の下書きに当てる。AI の「覚えています」には頼らず、直した記録から決める。
 */
class PaymentMemory
{
    public static function normalize(?string $vendor): ?string
    {
        if ($vendor === null) {
            return null;
        }

        $key = mb_strtolower((string) preg_replace('/\s+/u', '', mb_convert_kana($vendor, 'asKV')));

        return $key === '' ? null : $key;
    }

    public function for(Company $company, ?string $vendor): ?BankAccount
    {
        $key = self::normalize($vendor);
        if ($key === null) {
            return null;
        }

        /** @var AiCorrection|null $correction */
        $correction = $company->aiCorrections()
            ->where('field', 'credit_account')
            ->whereNotNull('vendor')
            ->latest('created_at')
            ->latest('id')
            ->limit(100)
            ->get()
            ->first(fn (AiCorrection $c): bool => self::normalize($c->vendor) === $key);

        if ($correction === null || $correction->after === null) {
            return null;
        }

        /** @var BankAccount|null $bankAccount */
        $bankAccount = $company->bankAccounts()->whereNull('archived_at')->where('name', $correction->after)->first();

        return $bankAccount;
    }
}
