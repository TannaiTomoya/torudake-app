<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CompanySettingsService
{
    /**
     * @param  array{name: string, fiscal_year_end_month: int, concern_amount_yen: int}  $data
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
            ];

            $locked->forceFill([
                'name' => $data['name'],
                'fiscal_year_end_month' => $data['fiscal_year_end_month'],
                'concern_amount_yen' => $data['concern_amount_yen'],
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
                ],
                company: $locked,
            );

            return $locked;
        });
    }

    public function renameCategory(Company $company, User $user, string $categoryId, string $name): Category
    {
        return DB::transaction(function () use ($company, $user, $categoryId, $name): Category {
            /** @var Category $category */
            $category = $company->categories()->lockForUpdate()->findOrFail($categoryId);

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
