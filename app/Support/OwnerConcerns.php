<?php

namespace App\Support;

use App\Enums\EntryStatus;
use App\Models\Company;
use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 気になる仕訳＝気になる印があり、社長がまだ見ていない確定した仕訳（03 §4.7 ③）。ここ以外で条件を書かない。
 */
final class OwnerConcerns
{
    /**
     * @return HasMany<JournalEntry, Company>
     */
    public static function query(Company $company): HasMany
    {
        return $company->journalEntries()
            ->where('status', EntryStatus::Confirmed)
            ->whereNull('owner_seen_at')
            ->whereJsonLength('concern_flags', '>', 0);
    }

    public static function count(Company $company): int
    {
        return self::query($company)->count();
    }
}
