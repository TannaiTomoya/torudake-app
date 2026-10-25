<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompanySettingsService
{
    /**
     * @param  array{name: string, fiscal_year_end_month: int, concern_amount_yen: int, busy_months: list<int>}  $data
     */
    public function updateCompany(Company $company, User $user, array $data): Company
    {
        return DB::transaction(function () use ($company, $user, $data): Company {
            /** @var Company $locked */
            $locked = Company::query()->lockForUpdate()->findOrFail($company->id);

            $before = [
                'name' => $locked->name,
                'fiscal_year_end_month' => $locked->fiscal_year_end_month,
                'concern_amount_yen' => $locked->concern_amount_yen,
                'busy_months' => $locked->busy_months,
            ];

            $busyMonths = array_values(array_unique($data['busy_months']));
            sort($busyMonths);

            $locked->forceFill([
                'name' => $data['name'],
                'fiscal_year_end_month' => $data['fiscal_year_end_month'],
                'concern_amount_yen' => $data['concern_amount_yen'],
                'busy_months' => $busyMonths,
            ])->save();

            AuditLog::record(
                user: $user,
                action: 'company.settings.update',
                subject: $locked,
                before: $before,
                after: [
                    'name' => $locked->name,
                    'fiscal_year_end_month' => $locked->fiscal_year_end_month,
                    'concern_amount_yen' => $locked->concern_amount_yen,
                    'busy_months' => $locked->busy_months,
                ],
                company: $locked,
            );

            return $locked;
        });
    }

    public function renameCategory(Company $company, User $user, string $categoryId, string $name): Category
    {
        return DB::transaction(function () use ($company, $user, $categoryId, $name): Category {
            // 別々の分類を同時に同名へ変える処理も、会社単位で直列にする。
            $lockedCompany = Company::query()->lockForUpdate()->findOrFail($company->id);
            /** @var Category $category */
            $category = $lockedCompany->categories()->lockForUpdate()->findOrFail($categoryId);

            $name = trim($name);
            if ($name === '' || mb_strlen($name) > 100) {
                throw ValidationException::withMessages(['categoryName' => '分類名は1〜100文字で入れてください。']);
            }

            if ($category->archived_at === null && $lockedCompany->categories()
                ->whereNull('archived_at')->whereKeyNot($category->id)->where('name', $name)
                ->lockForUpdate()->first() !== null) {
                throw ValidationException::withMessages(['categoryName' => '同じ名前の分類が既にあります。別の名前にしてください。']);
            }

            if ($category->name === $name) {
                return $category;
            }

            $before = ['name' => $category->name];

            $category->forceFill(['name' => $name])->save();

            AuditLog::record(
                user: $user,
                action: 'category.update',
                subject: $category,
                before: $before,
                after: ['name' => $category->name],
                company: $company,
            );

            return $category;
        });
    }

    public function setDefaultBankAccount(Company $company, User $user, string $bankAccountId): BankAccount
    {
        return DB::transaction(function () use ($company, $user, $bankAccountId): BankAccount {
            /** @var BankAccount $target */
            $target = $company->bankAccounts()->lockForUpdate()->findOrFail($bankAccountId);

            $company->bankAccounts()->lockForUpdate()->get()->each(function (BankAccount $account) use ($target): void {
                $shouldBeDefault = $account->id === $target->id;
                if ($account->is_default_for_receipts !== $shouldBeDefault) {
                    $account->forceFill(['is_default_for_receipts' => $shouldBeDefault])->save();
                }
            });

            AuditLog::record(
                user: $user,
                action: 'bank_account.set_default',
                subject: $target,
                before: null,
                after: ['default_bank_account_id' => $target->id, 'name' => $target->name],
                company: $company,
            );

            return $target->refresh();
        });
    }
}
