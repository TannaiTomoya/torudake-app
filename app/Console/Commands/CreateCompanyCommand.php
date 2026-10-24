<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Company;
use Database\Seeders\CompanyDefaultSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CreateCompanyCommand extends Command
{
    protected $signature = 'torudake:create-company {name : 会社名} {--fiscal-year-end=7 : 決算月（1〜12）} {--demo : デモ用の会社として作成する}';

    protected $description = '会社と、勘定科目・分類・口座の初期値を作成する';

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));
        $fiscalYearEnd = (int) $this->option('fiscal-year-end');
        $isDemo = (bool) $this->option('demo');

        if ($name === '') {
            $this->error('会社名を指定してください。');

            return self::FAILURE;
        }

        if ($fiscalYearEnd < 1 || $fiscalYearEnd > 12) {
            $this->error('決算月は1〜12で指定してください。');

            return self::FAILURE;
        }

        /** @var Company $company */
        $company = DB::transaction(function () use ($name, $fiscalYearEnd, $isDemo): Company {
            $company = Company::create([
                'name' => $name,
                'fiscal_year_end_month' => $fiscalYearEnd,
                'allow_self_approval' => false,
                'is_demo' => $isDemo,
            ]);

            CompanyDefaultSeeder::seedForCompany($company);

            AuditLog::record(
                user: null,
                action: 'company.create',
                subject: $company,
                before: null,
                after: [
                    'name' => $company->name,
                    'fiscal_year_end_month' => $company->fiscal_year_end_month,
                    'is_demo' => $company->is_demo,
                ],
                company: $company,
            );

            return $company;
        });

        $this->info("会社を作成しました: {$company->name} (ID: {$company->id})");

        return self::SUCCESS;
    }
}
