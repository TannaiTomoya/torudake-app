<?php

namespace App\Services;

use App\Enums\BankAccountKind;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\BankBalance;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 通帳の残高を、口座×日付で1件ずつ入れる・直す・取り消す（#66）。AI は使わない。
 * 同じ口座・同じ日にもう一度入れたら上書きし、前の値を監査記録に残す。マイナスの残高（当座借越）も入れられる。
 */
class BankBalanceService
{
    /** 残高の上限（円）。±1兆円 */
    public const MAX_ABS_YEN = 1_000_000_000_000;

    public function enter(Company $company, User $user, string $bankAccountId, CarbonImmutable $date, int $balanceYen, string $source = BankBalance::SOURCE_MANUAL): BankBalance
    {
        return DB::transaction(function () use ($company, $user, $bankAccountId, $date, $balanceYen, $source): BankBalance {
            // 同じ口座の入力は、口座の行で直列にする（同じ日の二重登録を防ぐ）
            /** @var BankAccount $account */
            $account = $company->bankAccounts()->lockForUpdate()->findOrFail($bankAccountId);

            if ($account->kind !== BankAccountKind::Bank || $account->archived_at !== null) {
                throw ValidationException::withMessages(['bankAccountId' => '残高を入れられるのは、使っている銀行の口座だけです。']);
            }
            if (abs($balanceYen) > self::MAX_ABS_YEN) {
                throw ValidationException::withMessages(['balanceYen' => '残高が扱える範囲を超えています。']);
            }
            if ($date->greaterThan(CarbonImmutable::today())) {
                throw ValidationException::withMessages(['balanceDate' => '先の日付の残高は入れられません。']);
            }

            /** @var BankBalance|null $existing */
            $existing = $company->bankBalances()
                ->where('bank_account_id', $account->id)
                ->whereDate('balance_date', $date->toDateString())
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->balance_yen === $balanceYen && $existing->source === $source) {
                    return $existing;
                }

                $before = $this->auditValues($existing);
                $existing->forceFill(['balance_yen' => $balanceYen, 'source' => $source, 'entered_by' => $user->id])->save();
                AuditLog::record(user: $user, action: 'bank_balance.update', subject: $existing, before: $before, after: $this->auditValues($existing), company: $company);

                return $existing;
            }

            $balance = new BankBalance;
            $balance->forceFill([
                'company_id' => $company->id,
                'bank_account_id' => $account->id,
                'balance_date' => $date->toDateString(),
                'balance_yen' => $balanceYen,
                'source' => $source,
                'entered_by' => $user->id,
            ])->save();
            AuditLog::record(user: $user, action: 'bank_balance.enter', subject: $balance, after: $this->auditValues($balance), company: $company);

            return $balance;
        });
    }

    public function remove(Company $company, User $user, string $balanceId): void
    {
        DB::transaction(function () use ($company, $user, $balanceId): void {
            /** @var BankBalance $balance */
            $balance = $company->bankBalances()->lockForUpdate()->findOrFail($balanceId);

            AuditLog::record(user: $user, action: 'bank_balance.delete', subject: $balance, before: $this->auditValues($balance), company: $company);
            $balance->delete();
        });
    }

    /**
     * @return array{bank_account_id: string, balance_date: string, balance_yen: int, source: string}
     */
    private function auditValues(BankBalance $balance): array
    {
        return [
            'bank_account_id' => $balance->bank_account_id,
            'balance_date' => $balance->balance_date->toDateString(),
            'balance_yen' => $balance->balance_yen,
            'source' => $balance->source,
        ];
    }
}
