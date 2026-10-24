<?php

namespace App\Support;

use App\Enums\EntryStatus;
use App\Enums\SourceStatus;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * 「あと n 件」＝その月の未処理の原資料＋下書き・社長から戻しの仕訳。ここ以外で数えない。
 * 下書きのある原資料は仕訳の側で数えるので、二重に数えない。
 */
final class RemainingCount
{
    public static function for(Company $company, ?CarbonInterface $month = null): int
    {
        $from = CarbonImmutable::instance($month ?? now())->startOfMonth();
        $to = $from->endOfMonth();

        $newSources = $company->sourceRecords()
            ->where('status', SourceStatus::New)
            ->whereBetween('created_at', [$from, $to])
            ->count();

        $openEntries = $company->journalEntries()
            ->whereIn('status', [EntryStatus::Draft, EntryStatus::Returned])
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->count();

        return $newSources + $openEntries;
    }
}
