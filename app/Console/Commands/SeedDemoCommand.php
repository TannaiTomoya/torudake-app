<?php

namespace App\Console\Commands;

use App\Enums\EntryKind;
use App\Enums\EntryStatus;
use App\Enums\FigureMetric;
use App\Enums\FigureSource;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\ExternalCustomerSale;
use App\Models\ExternalMonthlyFigure;
use App\Models\JournalEntry;
use App\Services\JournalLineWriter;
use App\Support\FiscalYear;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * デモ用の会社に、架空の三期比較・顧客別の売上・今期の確定した経費を入れる（docs/03 §4.10）。
 * 数字はすべて架空。実在の帳票の数字は入れない。人は作らない。
 */
class SeedDemoCommand extends Command
{
    protected $signature = 'torudake:seed-demo {company : デモ用の会社のID}';

    protected $description = 'デモ用の会社に架空の数字（三期比較・顧客別・今期の経費）を入れる';

    /** 架空の基準額（月の売上） */
    private const BASE_YEN = 4_000_000;

    /** 季節の係数。秋（10〜12月）が多く、6・7月もやや多い */
    private const SEASON = [1 => 0.70, 2 => 0.65, 3 => 0.90, 4 => 0.80, 5 => 0.85, 6 => 1.10, 7 => 1.10, 8 => 0.85, 9 => 0.90, 10 => 1.25, 11 => 1.35, 12 => 1.30];

    /** 期ごとの伸び（前々期・前期・今期） */
    private const GROWTH = ['before' => 0.92, 'previous' => 1.00, 'current' => 1.08];

    /** 前期の7月だけ落とす（忙しい月なのに前年同月より少ない、の見せ場） */
    private const DIP_MONTH = 7;

    private const DIP_FACTOR = 0.80;

    /** 顧客別の割合（上位2社で約8割） */
    private const CUSTOMER_SHARES = ['A社' => 0.52, 'B社' => 0.27, 'C社' => 0.09, 'D社' => 0.07, 'E社' => 0.05];

    /** 今期の月ごとの架空の経費：分類の名前 => [取引先, 金額] */
    private const EXPENSES = [
        '燃料' => ['〇〇石油 八幡平店', 180_000],
        '部品・修理' => ['△△機械', 95_000],
        '消耗品' => ['□□ホームセンター', 42_000],
        '通信' => ['◇◇通信', 18_000],
    ];

    private const DEMO_MARK = '架空（デモ）';

    public function handle(JournalLineWriter $lines): int
    {
        $company = Company::query()->find((string) $this->argument('company'));

        if ($company === null) {
            $this->error('会社が見つかりません。');

            return self::FAILURE;
        }

        if (! $company->is_demo) {
            $this->error('デモ用の会社（--demo で作った会社）にだけ入れられます。');

            return self::FAILURE;
        }

        $today = CarbonImmutable::now();
        $current = FiscalYear::containing($today, $company->fiscal_year_end_month);
        $periods = ['before' => $current->previous()->previous(), 'previous' => $current->previous(), 'current' => $current];

        DB::transaction(function () use ($company, $today, $current, $periods, $lines): void {
            $previousTotal = 0;

            foreach ($periods as $name => $year) {
                foreach ($year->months() as $ym) {
                    if ($name === 'current' && $ym >= $today->format('Y-m')) {
                        break;
                    }

                    $month = (int) substr($ym, 5, 2);
                    $factor = self::SEASON[$month] * self::GROWTH[$name] * ($name === 'previous' && $month === self::DIP_MONTH ? self::DIP_FACTOR : 1);
                    $amount = (int) round(self::BASE_YEN * $factor, -3);
                    $previousTotal += $name === 'previous' ? $amount : 0;

                    $figure = $company->externalMonthlyFigures()->where('year_month', $ym)->where('metric', FigureMetric::Sales)->first() ?? new ExternalMonthlyFigure;
                    $figure->forceFill([
                        'company_id' => $company->id,
                        'year_month' => $ym,
                        'metric' => FigureMetric::Sales,
                        'amount_yen' => $amount,
                        'source' => FigureSource::Demo,
                    ])->save();
                }
            }

            $from = $periods['previous']->start->toDateString();
            $to = $periods['previous']->end()->toDateString();
            $rank = 1;
            foreach (self::CUSTOMER_SHARES as $label => $share) {
                $row = $company->externalCustomerSales()->whereDate('period_from', $from)->whereDate('period_to', $to)->where('rank', $rank)->first() ?? new ExternalCustomerSale;
                $row->forceFill([
                    'company_id' => $company->id,
                    'period_from' => $from,
                    'period_to' => $to,
                    'rank' => $rank++,
                    'label' => $label,
                    'amount_yen' => (int) round($previousTotal * $share, -3),
                ])->save();
            }

            if (! $company->journalEntries()->where('description', self::DEMO_MARK)->exists()) {
                $this->seedExpenses($company, $current, $today, $lines);
            }

            AuditLog::record(user: null, action: 'demo.seed', subject: $company, before: null, after: ['fiscal_year' => $current->start->format('Y-m')], company: $company);
        });

        $count = ExternalMonthlyFigure::query()->where('company_id', $company->id)->count();
        $this->info("架空の数字を入れました: {$company->name}（月ごとの売上 {$count} か月分）");

        return self::SUCCESS;
    }

    private function seedExpenses(Company $company, FiscalYear $current, CarbonImmutable $today, JournalLineWriter $lines): void
    {
        $bankAccount = $company->bankAccounts()->where('is_default_for_receipts', true)->firstOrFail();

        foreach ($current->months() as $i => $ym) {
            if ($ym > $today->format('Y-m')) {
                break;
            }

            $date = CarbonImmutable::parse($ym.'-10');
            $date = $date->greaterThan($today) ? $today->startOfDay() : $date;

            foreach (self::EXPENSES as $categoryName => [$vendor, $amount]) {
                $category = $company->categories()->where('name', $categoryName)->firstOrFail();

                $entry = new JournalEntry;
                $entry->forceFill([
                    'company_id' => $company->id,
                    'entry_date' => $date->toDateString(),
                    'kind' => EntryKind::Normal,
                    'status' => EntryStatus::Confirmed,
                    'vendor' => $vendor,
                    'description' => self::DEMO_MARK,
                    'category_id' => $category->id,
                    'bank_account_id' => $bankAccount->id,
                    'confirmed_at' => $date,
                ])->save();

                $lines->rewrite($entry, $category, $bankAccount, $amount + $i * 3_000);
            }
        }
    }
}
