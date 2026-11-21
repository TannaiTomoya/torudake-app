<?php

namespace App\Services;

use App\Enums\ScheduleDirection;
use App\Models\CashSchedule;
use App\Models\Company;
use App\Support\ScheduleDelay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * 入金・支払いの予定を読むだけ（#67）。遅れの判定は ScheduleDelay の1か所だけ。
 * 取引先の名前と金額を返すので、呼ぶ側は manageSchedules を確かめてから使う（予定の画面・社長の画面）。
 *
 * @phpstan-import-type Summary from ScheduleDelay
 *
 * @phpstan-type LateReceipts array{count: int, total_yen: int, rows: list<array{due_date: string, days_late: int, amount_yen: int, party: string}>, more: int}
 */
class CashScheduleBoard
{
    /** 社長の画面に並べる遅れの件数 */
    public const OWNER_ROWS = 5;

    /**
     * まだ済んでいない予定（予定日の古い順）。
     *
     * @return Collection<int, CashSchedule>
     */
    public function open(Company $company, int $limit = 200): Collection
    {
        return $company->cashSchedules()
            ->whereNull('settled_on')
            ->with(['partner', 'bankAccount'])
            ->orderBy('due_date')
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * 済みの予定（済みの日の新しい順）。
     *
     * @return Collection<int, CashSchedule>
     */
    public function settled(Company $company, int $limit = 30): Collection
    {
        return $company->cashSchedules()
            ->whereNotNull('settled_on')
            ->with(['partner', 'bankAccount'])
            ->orderByDesc('settled_on')
            ->orderByDesc('updated_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * まだ済んでいない予定の件数・金額と、そのうち遅れの件数・金額（入金と支払いを分ける）。
     *
     * @return Summary
     */
    public function summary(Company $company, ?CarbonImmutable $today = null): array
    {
        $items = $company->cashSchedules()
            ->whereNull('settled_on')
            ->get(['id', 'direction', 'amount_yen', 'due_date', 'settled_on'])
            ->map(fn (CashSchedule $schedule): array => [
                'direction' => $schedule->direction->value,
                'amount_yen' => $schedule->amount_yen,
                'due_date' => $schedule->due_date->toDateString(),
                'settled_on' => null,
            ])
            ->all();

        return ScheduleDelay::summarize($items, ($today ?? CarbonImmutable::today())->toDateString());
    }

    /**
     * 社長の画面の「入金の遅れ」。予定日の古い順に OWNER_ROWS 件まで並べ、残りは件数だけ。
     *
     * @return LateReceipts
     */
    public function lateReceipts(Company $company, ?CarbonImmutable $today = null): array
    {
        $day = ($today ?? CarbonImmutable::today())->toDateString();

        // 遅れかどうかは SQL で書かず、ScheduleDelay で判定する（規則を1か所にする）
        $open = $company->cashSchedules()
            ->where('direction', ScheduleDirection::In->value)
            ->whereNull('settled_on')
            ->with('partner')
            ->orderBy('due_date')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $count = 0;
        $total = 0;
        $rows = [];
        foreach ($open as $schedule) {
            $judgement = ScheduleDelay::judge($schedule->due_date->toDateString(), null, $day);
            if ($judgement['status'] !== ScheduleDelay::STATUS_LATE) {
                continue;
            }

            $count++;
            $total += $schedule->amount_yen;
            if (count($rows) < self::OWNER_ROWS) {
                $rows[] = [
                    'due_date' => $schedule->due_date->toDateString(),
                    'days_late' => $judgement['days_late'],
                    'amount_yen' => $schedule->amount_yen,
                    'party' => $schedule->partyLabel() ?? '',
                ];
            }
        }

        return [
            'count' => $count,
            'total_yen' => $total,
            'rows' => $rows,
            'more' => max($count - self::OWNER_ROWS, 0),
        ];
    }
}
