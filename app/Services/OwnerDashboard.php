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
 *     left: int|null,
 * }
 * @phpstan-type Customer array{label: string, amount_yen: int, share_pct: int}
 * @phpstan-type Dashboard array{
 *     periods: array{current: string, previous: string, before: string},
 *     months: list<MonthRow>,
 *     totals: array{current: int, previous_same_months: int, diff: int, pct: int|null, months_with_sales: int},
 *     expense_by_category: list<array{name: string, amount_yen: int}>,
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

        $expenses = $this->expensesByMonth($company, $current);

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
                'left' => $cur !== null && $expense !== null ? $cur - $expense : null,
            ];
        }

        $withSales = array_values(array_filter($months, fn (array $row): bool => $row['current'] !== null));
        $currentTotal = array_sum(array_column($withSales, 'current'));
        $previousSame = array_sum(array_map(fn (array $row): int => $row['previous'] ?? 0, $withSales));

        return [
            'periods' => [
                'current' => $this->periodLabel($current),
                'previous' => $this->periodLabel($previous),
                'before' => $this->periodLabel($before),
            ],
            'months' => $months,
            'totals' => [
                'current' => $currentTotal,
                'previous_same_months' => $previousSame,
                'diff' => $currentTotal - $previousSame,
                'pct' => $previousSame > 0 ? (int) round(($currentTotal - $previousSame) / $previousSame * 100) : null,
                'months_with_sales' => count($withSales),
            ],
            'expense_by_category' => $expenses['by_category'],
            'concerns' => $this->concerns($company),
            'customers' => $this->customers($company),
        ];
    }

    private function periodLabel(FiscalYear $year): string
    {
        return $year->end()->isoFormat('YYYY年M月期');
    }

    /**
     * 今期の確定した経費（支出の分類）。経費のない月は入れない（画面では「—」）。
     *
     * @return array{by_month: array<string, int>, by_category: list<array{name: string, amount_yen: int}>}
     */
    private function expensesByMonth(Company $company, FiscalYear $year): array
    {
        $entries = $company->journalEntries()
            ->where('status', EntryStatus::Confirmed)
            ->whereBetween('entry_date', [$year->start->toDateString(), $year->end()->toDateString()])
            ->with(['category', 'lines'])
            ->get()
            ->filter(fn (JournalEntry $entry): bool => $entry->category?->direction === CategoryDirection::Expense);

        $byMonth = [];
        $byCategory = [];
        foreach ($entries as $entry) {
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
