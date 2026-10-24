<?php

namespace App\Support;

use App\Enums\EntryStatus;
use App\Enums\SourceStatus;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * 「あと n 件」＝未処理の原資料＋下書き・社長から戻しの仕訳。月指定がない場合は全期間を数える。
 * 下書きのある原資料は仕訳の側で数えるので、二重に数えない。
 */
final class RemainingCount
{
    public static function for(Company $company, ?CarbonInterface $month = null): int
    {
        $from = CarbonImmutable::instance($month ?? now())->startOfMonth();
        $to = $from->endOfMonth();

        $sources = $company->sourceRecords()->where('status', SourceStatus::New);
        $entries = $company->journalEntries()->whereIn('status', [EntryStatus::Draft, EntryStatus::Returned]);

        if ($month !== null) {
            $sources->whereBetween('created_at', [$from, $to]);
            $entries->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()]);
        }

        return $sources->count() + $entries->count();
    }

    /** 未処理のうち、原資料の日付または仕訳日付が今月より前の件数。 */
    public static function fromPreviousMonths(Company $company, ?CarbonInterface $month = null): int
    {
        $from = CarbonImmutable::instance($month ?? now())->startOfMonth();

        $oldSources = $company->sourceRecords()
            ->where('status', SourceStatus::New)
            ->where(function (Builder $query) use ($from): void {
                $query->where(function (Builder $query) use ($from): void {
                    $query->whereNotNull('occurred_on')
                        ->whereDate('occurred_on', '<', $from->toDateString());
                })->orWhere(function (Builder $query) use ($from): void {
                    $query->whereNull('occurred_on')
                        ->where('created_at', '<', $from);
                });
            })
            ->count();

        $oldEntries = $company->journalEntries()
            ->whereIn('status', [EntryStatus::Draft, EntryStatus::Returned])
            ->whereDate('entry_date', '<', $from->toDateString())
            ->count();

        return $oldSources + $oldEntries;
    }
}
