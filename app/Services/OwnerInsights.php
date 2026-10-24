<?php

namespace App\Services;

use App\Enums\InsightKind;
use App\Jobs\GenerateOwnerInsight;
use App\Models\Company;
use App\Models\OwnerInsight;
use App\Services\Ai\Tasks\Forecast;
use App\Services\Ai\Tasks\OwnerBrief;
use Illuminate\Support\Facades\DB;

/**
 * 社長へのひとこと・来月の見込み（docs/03 §4.7）。
 * 入力（社長の画面の数字と確定した仕訳）のハッシュが前回と同じなら AI を呼ばない。違えばジョブで作り直し、
 * できるまで前回のものを「更新中」として出す。ジョブが5分たっても終わらなければ、もう一度頼む。
 *
 * @phpstan-import-type Dashboard from OwnerDashboard
 */
class OwnerInsights
{
    private const STALE_MINUTES = 5;

    public function __construct(private readonly OwnerDashboard $dashboard) {}

    /**
     * @param  Dashboard|null  $dashboard
     * @return array{brief: OwnerInsight, forecast: OwnerInsight}
     */
    public function refresh(Company $company, ?array $dashboard = null): array
    {
        $dashboard ??= $this->dashboard->build($company);

        return [
            'brief' => $this->ensure($company, InsightKind::Brief, $this->briefInput($dashboard)),
            'forecast' => $this->ensure($company, InsightKind::Forecast, $this->forecastInput($dashboard)),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function hash(InsightKind $kind, array $input): string
    {
        $version = match ($kind) {
            InsightKind::Brief => OwnerBrief::PROMPT_VERSION,
            InsightKind::Forecast => Forecast::PROMPT_VERSION,
        };

        return hash('sha256', $version.'|'.json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function ensure(Company $company, InsightKind $kind, array $input): OwnerInsight
    {
        $hash = self::hash($kind, $input);

        [$insight, $dispatch] = DB::transaction(function () use ($company, $kind, $hash): array {
            Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();

            $insight = $company->ownerInsights()->where('kind', $kind)->lockForUpdate()->first() ?? new OwnerInsight;
            $insight->forceFill(['company_id' => $company->id, 'kind' => $kind]);

            $fresh = $insight->input_hash === $hash;
            $recent = $insight->pending_at?->greaterThan(now()->subMinutes(self::STALE_MINUTES)) === true;
            $alreadyAsked = $insight->pending_hash === $hash && $recent;
            $recentlyFailed = $insight->pending_hash === null && $insight->error !== null && $recent;

            if ($fresh) {
                if ($insight->pending_hash !== null) {
                    $insight->forceFill(['pending_hash' => null, 'pending_at' => null])->save();
                }

                return [$insight, false];
            }

            if ($alreadyAsked || $recentlyFailed) {
                return [$insight, false];
            }

            $insight->forceFill(['pending_hash' => $hash, 'pending_at' => now()])->save();

            return [$insight, true];
        });

        if ($dispatch) {
            GenerateOwnerInsight::dispatch($company->id, $kind->value, $hash, $input);
            $insight->refresh();
        }

        return $insight;
    }

    /**
     * ひとことに渡すのは集計した数字だけ（取引先の名前・人の名前は渡さない）。
     *
     * @param  Dashboard  $dashboard
     * @return array<string, mixed>
     */
    public function briefInput(array $dashboard): array
    {
        return [
            'periods' => $dashboard['periods'],
            'months' => array_map(fn (array $row): array => [
                'month' => $row['month'],
                'busy' => $row['busy'],
                'current' => $row['current'],
                'previous' => $row['previous'],
                'before' => $row['before'],
                'average' => $row['average'],
                'latest_year_month' => $row['latest_year_month'],
                'yoy_diff' => $row['yoy_diff'],
                'yoy_pct' => $row['yoy_pct'],
                'busy_but_lower' => $row['busy_but_lower'],
                'expense' => $row['expense'],
            ], $dashboard['months']),
            'totals' => $dashboard['totals'],
            'expense_by_category' => $dashboard['expense_by_category'],
            'customers' => $dashboard['customers']['top'] ?? [],
            'concern_count' => $dashboard['concerns']->count(),
        ];
    }

    /**
     * 見込みに渡すのは、過去の月の実績・忙しい月の印・3期の平均・今期の経費。
     *
     * @param  Dashboard  $dashboard
     * @return array<string, mixed>
     */
    public function forecastInput(array $dashboard): array
    {
        $target = now()->addMonthNoOverflow()->month;
        $sales = [];
        $expenses = [];
        $targetRow = null;

        foreach ($dashboard['months'] as $row) {
            foreach (['before', 'previous', 'current'] as $period) {
                if ($row[$period] !== null) {
                    $sales[$row['year_months'][$period]] = $row[$period];
                }
            }
            if ($row['expense'] !== null) {
                $expenses[$row['year_months']['current']] = $row['expense'];
            }
            if ($row['month'] === $target) {
                $targetRow = $row;
            }
        }
        ksort($sales);
        ksort($expenses);

        $history = [];
        foreach (['before', 'previous', 'current'] as $period) {
            if ($targetRow !== null && $targetRow[$period] !== null) {
                $history[$targetRow['year_months'][$period]] = $targetRow[$period];
            }
        }

        return [
            'target_month' => $target,
            'target_busy' => $targetRow['busy'] ?? false,
            'target_average' => $targetRow['average'] ?? null,
            'target_history' => $history,
            'growth_pct' => $dashboard['totals']['pct'],
            'monthly_sales' => $sales,
            'monthly_expenses' => $expenses,
        ];
    }
}
