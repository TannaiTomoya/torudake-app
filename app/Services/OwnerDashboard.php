<?php

namespace App\Services;

use App\Enums\CategoryDirection;
use App\Enums\EntryStatus;
use App\Enums\FigureMetric;
use App\Models\Company;
use App\Models\ExternalCustomerSale;
use App\Models\JournalEntry;
use App\Support\FiscalYear;
use App\Support\OwnerConcerns;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * 社長の画面の数字（docs/03 §4.7）。読むだけで、何も書き換えない。
 *
 * 数字の意味（#34）：
 * - reference_diff は「登録済みの売上 − 登録済みの支出（確定）」の参考の差額。現預金の残高でも、利益でもない。
 *   売上か支出の片方がない月は出さない（欠損を0にしない）。
 * - 前期との比較（totals.diff・pct）は、今期と前期の**両方に数字がある月だけ**を使う。前期が欠けた月を0円として足さない。
 *   実際の0円は数字として使い、欠けている月とは区別する。今期の売上の合計（totals.current）は別の指標として、数字のある全月を足す。
 * - 支出額は、確定した支出の仕訳の合計。損益上の費用とは限らない（借入返済・資産の購入などは今は扱わない）。
 *
 * @phpstan-type MonthRow array{
 *     month: int,
 *     year_months: array{current: string, previous: string, before: string},
 *     busy: bool,
 *     current: int|null,
 *     previous: int|null,
 *     before: int|null,
 *     average: int|null,
 *     latest_year_month: string|null,
 *     yoy_diff: int|null,
 *     yoy_pct: int|null,
 *     busy_but_lower: bool,
 *     expense: int|null,
 *     reference_diff: int|null,
 * }
 * @phpstan-type Customer array{label: string, amount_yen: int, share_pct: int}
 * @phpstan-type Dashboard array{
 *     periods: array{current: string, previous: string, before: string},
 *     months: list<MonthRow>,
 *     totals: array{
 *         current: int,
 *         months_with_sales: int,
 *         comparable_months: int,
 *         comparable_month_numbers: list<int>,
 *         comparable_current: int,
 *         comparable_previous: int,
 *         diff: int|null,
 *         pct: int|null,
 *         previous_missing_months: int,
 *     },
 *     expense_by_category: list<array{name: string, amount_yen: int}>,
 *     expense_info: array{period_from: string, period_to: string, confirmed_count: int, draft_count: int, returned_count: int, last_confirmed_at: string|null, as_of: string},
 *     concerns: Collection<int, JournalEntry>,
 *     customers: array{period_from: string, period_to: string, top: list<Customer>}|null,
 * }
 */
class OwnerDashboard
{
    /**
     * @return Dashboard
     */
    public function build(Company $company, ?CarbonInterface $today = null): array
    {
        $current = FiscalYear::containing($today ?? now(), $company->fiscal_year_end_month);
        $previous = $current->previous();
        $before = $previous->previous();

        $sales = $company->externalMonthlyFigures()
            ->where('metric', FigureMetric::Sales)
            ->whereBetween('year_month', [$before->start->format('Y-m'), $current->end()->format('Y-m')])
            ->pluck('amount_yen', 'year_month')
            ->map(fn ($amount): int => (int) $amount);

        $expenses = $this->expensesByMonth($company, $current, $today ?? now());

        $months = [];
        foreach (range(0, 11) as $i) {
            $ym = [$current->months()[$i], $previous->months()[$i], $before->months()[$i]];
            [$cur, $prev, $bef] = array_map(fn (string $key): ?int => $sales[$key] ?? null, $ym);
            $month = (int) substr($ym[0], 5, 2);

            [$latest, $base, $latestYm] = $cur !== null ? [$cur, $prev, $ym[0]] : [$prev, $bef, $prev !== null ? $ym[1] : null];
            $diff = $latest !== null && $base !== null ? $latest - $base : null;
            $known = array_values(array_filter([$cur, $prev, $bef], fn (?int $v): bool => $v !== null));
            $expense = $expenses['by_month'][$ym[0]] ?? null;

            $months[] = [
                'month' => $month,
                'year_months' => ['current' => $ym[0], 'previous' => $ym[1], 'before' => $ym[2]],
                'busy' => $company->isBusyMonth($month),
                'current' => $cur,
                'previous' => $prev,
                'before' => $bef,
                'average' => $known === [] ? null : (int) round(array_sum($known) / count($known)),
                'latest_year_month' => $latestYm,
                'yoy_diff' => $diff,
                'yoy_pct' => $diff !== null && $base > 0 ? (int) round($diff / $base * 100) : null,
                'busy_but_lower' => $company->isBusyMonth($month) && $diff !== null && $diff < 0,
                'expense' => $expense,
                'reference_diff' => $cur !== null && $expense !== null ? $cur - $expense : null,
            ];
        }

        $withSales = array_values(array_filter($months, fn (array $row): bool => $row['current'] !== null));
        $currentTotal = array_sum(array_column($withSales, 'current'));
        // 今期と前期の両方に数字がある月だけで比べる（前期が欠けた月を0円とみなさない）
        $comparable = array_values(array_filter($withSales, fn (array $row): bool => $row['previous'] !== null));
        $comparableCurrent = array_sum(array_column($comparable, 'current'));
        $comparablePrevious = array_sum(array_column($comparable, 'previous'));

        return [
            'periods' => [
                'current' => $this->periodLabel($current),
                'previous' => $this->periodLabel($previous),
                'before' => $this->periodLabel($before),
            ],
            'months' => $months,
            'totals' => [
                'current' => $currentTotal,
                'months_with_sales' => count($withSales),
                'comparable_months' => count($comparable),
                'comparable_month_numbers' => array_column($comparable, 'month'),
                'comparable_current' => $comparableCurrent,
                'comparable_previous' => $comparablePrevious,
                'diff' => $comparable === [] ? null : $comparableCurrent - $comparablePrevious,
                'pct' => $comparable !== [] && $comparablePrevious > 0 ? (int) round(($comparableCurrent - $comparablePrevious) / $comparablePrevious * 100) : null,
                'previous_missing_months' => count($withSales) - count($comparable),
            ],
            'expense_by_category' => $expenses['by_category'],
            'expense_info' => $expenses['info'],
            'concerns' => $this->concerns($company),
            'customers' => $this->customers($company),
        ];
    }

    private function periodLabel(FiscalYear $year): string
    {
        return $year->end()->isoFormat('YYYY年M月期');
    }

    /**
     * 今期の確定した支出（支出の分類の仕訳）。支出のない月は入れない（画面では「—」）。
     * 含めたもの（確定）と、含めていないもの（未確定の下書き・社長から戻した仕訳）の件数も返す。
     *
     * @return array{by_month: array<string, int>, by_category: list<array{name: string, amount_yen: int}>, info: array{period_from: string, period_to: string, confirmed_count: int, draft_count: int, returned_count: int, last_confirmed_at: string|null, as_of: string}}
     */
    private function expensesByMonth(Company $company, FiscalYear $year, CarbonInterface $asOf): array
    {
        $entries = $company->journalEntries()
            ->whereIn('status', [EntryStatus::Confirmed, EntryStatus::Draft, EntryStatus::Returned])
            ->whereBetween('entry_date', [$year->start->toDateString(), $year->end()->toDateString()])
            ->with(['category', 'lines'])
            ->get()
            ->filter(fn (JournalEntry $entry): bool => $entry->category?->direction === CategoryDirection::Expense);

        $confirmed = $entries->where('status', EntryStatus::Confirmed);

        $byMonth = [];
        $byCategory = [];
        foreach ($confirmed as $entry) {
            $amount = $entry->amountYen() ?? 0;
            $key = CarbonImmutable::instance($entry->entry_date)->format('Y-m');
            $byMonth[$key] = ($byMonth[$key] ?? 0) + $amount;
            $name = (string) $entry->category?->name;
            $byCategory[$name] = ($byCategory[$name] ?? 0) + $amount;
        }
        arsort($byCategory);

        return [
            'by_month' => $byMonth,
            'by_category' => array_map(
                fn (string $name, int $amount): array => ['name' => $name, 'amount_yen' => $amount],
                array_keys($byCategory),
                array_values($byCategory),
            ),
            'info' => [
                'period_from' => $year->start->toDateString(),
                'period_to' => $year->end()->toDateString(),
                'confirmed_count' => $confirmed->count(),
                'draft_count' => $entries->where('status', EntryStatus::Draft)->count(),
                'returned_count' => $entries->where('status', EntryStatus::Returned)->count(),
                'last_confirmed_at' => $confirmed->max('confirmed_at')?->toIso8601String(),
                'as_of' => $asOf->toIso8601String(),
            ],
        ];
    }

    /**
     * 気になる印があり、社長がまだ見ていない確定した仕訳。
     *
     * @return Collection<int, JournalEntry>
     */
    private function concerns(Company $company): Collection
    {
        return OwnerConcerns::query($company)
            ->with(['category', 'lines'])
            ->latest('confirmed_at')
            ->get();
    }

    /**
     * 一番新しい期間の取引先ごとの売上から、上位3社の割合。
     *
     * @return array{period_from: string, period_to: string, top: list<Customer>}|null
     */
    private function customers(Company $company): ?array
    {
        /** @var ExternalCustomerSale|null $latest */
        $latest = $company->externalCustomerSales()->latest('period_to')->first();

        if ($latest === null) {
            return null;
        }

        $rows = $company->externalCustomerSales()
            ->whereDate('period_from', $latest->period_from)
            ->whereDate('period_to', $latest->period_to)
            ->orderBy('rank')
            ->get();
        $total = (int) $rows->sum('amount_yen');

        return [
            'period_from' => $latest->period_from->isoFormat('YYYY年M月'),
            'period_to' => $latest->period_to->isoFormat('YYYY年M月'),
            'top' => array_values($rows->take(3)->map(fn (ExternalCustomerSale $row): array => [
                'label' => $row->label,
                'amount_yen' => $row->amount_yen,
                'share_pct' => $total > 0 ? (int) round($row->amount_yen / $total * 100) : 0,
            ])->all()),
        ];
    }
}
