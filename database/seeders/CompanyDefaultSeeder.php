<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\BankAccountKind;
use App\Enums\CategoryDirection;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Company;
use Illuminate\Database\Seeder;

class CompanyDefaultSeeder extends Seeder
{
    public function run(?Company $company = null): void
    {
        if ($company !== null) {
            self::seedForCompany($company);
        }
    }

    public static function seedForCompany(Company $company): void
    {
        /** @var list<array{code: string, name: string, type: AccountType}> $accountDefinitions */
        $accountDefinitions = [
            ['code' => '101', 'name' => '現金', 'type' => AccountType::Asset],
            ['code' => '102', 'name' => '普通預金', 'type' => AccountType::Asset],
            ['code' => '103', 'name' => '売掛金', 'type' => AccountType::Asset],
            ['code' => '201', 'name' => '買掛金', 'type' => AccountType::Liability],
            ['code' => '202', 'name' => '未払金', 'type' => AccountType::Liability],
            ['code' => '203', 'name' => '役員借入金', 'type' => AccountType::Liability],
            ['code' => '301', 'name' => '資本金', 'type' => AccountType::Equity],
            ['code' => '302', 'name' => '繰越利益剰余金', 'type' => AccountType::Equity],
            ['code' => '401', 'name' => '売上高', 'type' => AccountType::Revenue],
            ['code' => '501', 'name' => '仕入高', 'type' => AccountType::Expense],
            ['code' => '502', 'name' => '外注費', 'type' => AccountType::Expense],
            ['code' => '503', 'name' => '車両費', 'type' => AccountType::Expense],
            ['code' => '504', 'name' => '修繕費', 'type' => AccountType::Expense],
            ['code' => '505', 'name' => '消耗品費', 'type' => AccountType::Expense],
            ['code' => '506', 'name' => '通信費', 'type' => AccountType::Expense],
            ['code' => '507', 'name' => '接待交際費', 'type' => AccountType::Expense],
            ['code' => '508', 'name' => '支払手数料', 'type' => AccountType::Expense],
            ['code' => '509', 'name' => '租税公課', 'type' => AccountType::Expense],
            ['code' => '510', 'name' => '雑費', 'type' => AccountType::Expense],
        ];

        /** @var array<string, Account> $accountsByName */
        $accountsByName = [];

        foreach ($accountDefinitions as $def) {
            /** @var Account|null $existing */
            $existing = $company->accounts()->where('code', $def['code'])->first();

            if ($existing === null) {
                $account = new Account;
                $account->forceFill([
                    'company_id' => $company->id,
                    'code' => $def['code'],
                    'name' => $def['name'],
                    'type' => $def['type'],
                ])->save();
            } else {
                $account = $existing;
            }

            $accountsByName[$def['name']] = $account;
        }

        /** @var list<array{name: string, direction: CategoryDirection, account_name: string, sort_order: int}> $categoryDefinitions */
        $categoryDefinitions = [
            ['name' => '売上', 'direction' => CategoryDirection::Income, 'account_name' => '売上高', 'sort_order' => 10],
            ['name' => '燃料', 'direction' => CategoryDirection::Expense, 'account_name' => '車両費', 'sort_order' => 20],
            ['name' => '部品・修理', 'direction' => CategoryDirection::Expense, 'account_name' => '修繕費', 'sort_order' => 30],
            ['name' => '消耗品', 'direction' => CategoryDirection::Expense, 'account_name' => '消耗品費', 'sort_order' => 40],
            ['name' => '通信', 'direction' => CategoryDirection::Expense, 'account_name' => '通信費', 'sort_order' => 50],
            ['name' => '交際', 'direction' => CategoryDirection::Expense, 'account_name' => '接待交際費', 'sort_order' => 60],
            ['name' => '手数料', 'direction' => CategoryDirection::Expense, 'account_name' => '支払手数料', 'sort_order' => 70],
            ['name' => '租税公課', 'direction' => CategoryDirection::Expense, 'account_name' => '租税公課', 'sort_order' => 80],
            ['name' => '雑費', 'direction' => CategoryDirection::Expense, 'account_name' => '雑費', 'sort_order' => 90],
        ];

        foreach ($categoryDefinitions as $def) {
            $exists = $company->categories()->where('name', $def['name'])->exists();
            if (! $exists) {
                $category = new Category;
                $category->forceFill([
                    'company_id' => $company->id,
                    'name' => $def['name'],
                    'direction' => $def['direction'],
                    'account_id' => $accountsByName[$def['account_name']]->id,
                    'sort_order' => $def['sort_order'],
                ])->save();
            }
        }

        /** @var list<array{name: string, kind: BankAccountKind, account_name: string, is_default: bool}> $bankDefinitions */
        $bankDefinitions = [
            ['name' => '現金', 'kind' => BankAccountKind::Cash, 'account_name' => '現金', 'is_default' => true],
            ['name' => '〇〇銀行（普通預金）', 'kind' => BankAccountKind::Bank, 'account_name' => '普通預金', 'is_default' => false],
            ['name' => '事業用カード', 'kind' => BankAccountKind::Card, 'account_name' => '未払金', 'is_default' => false],
            ['name' => '社長の立替', 'kind' => BankAccountKind::Reimbursement, 'account_name' => '役員借入金', 'is_default' => false],
            ['name' => '後日の請求書（未払金）', 'kind' => BankAccountKind::Invoice, 'account_name' => '未払金', 'is_default' => false],
        ];

        foreach ($bankDefinitions as $def) {
            $exists = $company->bankAccounts()->where('name', $def['name'])->exists();
            if (! $exists) {
                $bankAccount = new BankAccount;
                $bankAccount->forceFill([
                    'company_id' => $company->id,
                    'name' => $def['name'],
                    'kind' => $def['kind'],
                    'account_id' => $accountsByName[$def['account_name']]->id,
                    'is_default_for_receipts' => $def['is_default'],
                ])->save();
            }
        }
    }
}
