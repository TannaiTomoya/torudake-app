<?php

namespace App\Services;

use App\Enums\BankAccountKind;
use App\Enums\EntryKind;
use App\Enums\EntryStatus;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Support\EntryRecommendation;
use App\Support\VendorKey;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * 同じ店の確定履歴から、分類・支払い元の候補を出す（#54）。読むだけで、何も書き換えない。
 *
 * - 根拠は、**この会社の人が確定した仕訳**だけ（他社の履歴は使わない）。店名は `vendor_key` の索引で引くので、履歴が多くても同じ店が範囲外にならない。
 * - 分類・支払い元は ID で数える（名前だけで別のものに結び付けない）。いまは使われていない（archived）ものは候補にしない。
 * - 履歴1件を規則にしない：{@see self::MIN_CONFIRMED} 件に満たない、最後の確認が {@see self::STALE_DAYS} 日より前、同じ店で使い方が分かれている、後日請求と食い違う、戻された仕訳がある、のどれかなら hold（参考）。
 * - 「後の正解」を過去の推薦に使わない：推薦は、呼んだ時点で確定している履歴だけから決める。
 */
class EntryRecommender
{
    /** 候補を下書きに当てるのに必要な、確定した件数 */
    public const MIN_CONFIRMED = 2;

    /** 最後の確認がこの日数より前なら古い */
    public const STALE_DAYS = 180;

    /** 使い方が分かれていても、この割合以上が同じ使い方で、直近もそうなら一つにそろっているとみなす */
    public const DOMINANT_PCT = 80;

    /** 1回に見る確定の仕訳の数（同じ店だけに絞ったうえでの上限） */
    public const HISTORY_LIMIT = 50;

    public function for(Company $company, ?string $vendor, ?string $paymentMethod = null, ?CarbonInterface $today = null): EntryRecommendation
    {
        $key = VendorKey::of($vendor);
        if ($key === null) {
            return new EntryRecommendation(EntryRecommendation::NONE, reasons: ['no_vendor']);
        }

        $today = CarbonImmutable::instance($today ?? now());

        $entries = $company->journalEntries()
            ->where('vendor_key', $key)
            ->where('kind', EntryKind::Normal)
            ->whereIn('status', [EntryStatus::Confirmed, EntryStatus::Returned])
            ->whereNotNull('category_id')
            ->whereNotNull('bank_account_id')
            ->with(['category', 'bankAccount'])
            ->orderByDesc('confirmed_at')
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get();

        $reasons = [];
        if ($entries->contains(fn (JournalEntry $entry): bool => $entry->status === EntryStatus::Returned)) {
            $reasons[] = 'returned_in_history';
        }

        $confirmed = $entries->filter(fn (JournalEntry $entry): bool => $entry->status === EntryStatus::Confirmed);
        $usable = $confirmed->filter(fn (JournalEntry $entry): bool => $this->isUsable($company, $entry));
        if ($usable->count() < $confirmed->count()) {
            $reasons[] = 'retired';
        }

        if ($usable->isEmpty()) {
            return new EntryRecommendation(EntryRecommendation::NONE, reasons: $reasons);
        }

        /** @var array<string, list<JournalEntry>> $groups */
        $groups = [];
        foreach ($usable as $entry) {
            $groups[$entry->category_id.'|'.$entry->bank_account_id][] = $entry;
        }
        uasort($groups, fn (array $a, array $b): int => [count($b), $b[0]->confirmed_at] <=> [count($a), $a[0]->confirmed_at]);

        $candidates = [];
        foreach (array_slice($groups, 0, 3) as $group) {
            $first = $group[0];
            $candidates[] = [
                'category_id' => (string) $first->category_id,
                'category_name' => (string) $first->category?->name,
                'bank_account_id' => (string) $first->bank_account_id,
                'bank_account_name' => (string) $first->bankAccount?->name,
                'count' => count($group),
                'last_confirmed_on' => ($first->confirmed_at ?? $first->updated_at)?->toDateString() ?? $today->toDateString(),
                'entry_ids' => array_slice(array_map(fn (JournalEntry $entry): string => $entry->id, $group), 0, 5),
            ];
        }

        $top = $groups[array_key_first($groups)];
        $total = $usable->count();

        if (count($groups) > 1) {
            $dominant = count($top) / $total * 100 >= self::DOMINANT_PCT && count($top) >= self::MIN_CONFIRMED;
            // いちばん新しい確認が多数派と違うなら、使い方が変わりつつある
            $latestIsTop = $usable->first()->id === $top[0]->id;
            if (! ($dominant && $latestIsTop)) {
                $reasons[] = 'mixed_usage';
            }
        }

        if ($total < self::MIN_CONFIRMED) {
            $reasons[] = 'few_history';
        }

        $lastConfirmed = $top[0]->confirmed_at;
        if ($lastConfirmed !== null && $lastConfirmed->lt($today->subDays(self::STALE_DAYS))) {
            $reasons[] = 'stale';
        }

        if ($paymentMethod === 'invoice_later' && $top[0]->bankAccount?->kind !== BankAccountKind::Invoice) {
            $reasons[] = 'payment_conflict';
        }

        return new EntryRecommendation(
            $reasons === [] ? EntryRecommendation::CONFIDENT : EntryRecommendation::HOLD,
            $candidates,
            $total,
            array_values(array_unique($reasons)),
        );
    }

    /**
     * 下書きに残した記録の候補が、いまも根拠どおりか。根拠にした仕訳が戻された・直された、候補が使えなくなった、
     * 現在の候補と違う、のどれかなら false（失効）。候補がなかった記録は、失効する対象がないので true。
     *
     * @param  array<string, mixed>  $snapshot  {@see EntryRecommendation::snapshot()}
     */
    public function stillValid(Company $company, array $snapshot, ?string $vendor, ?CarbonInterface $today = null): bool
    {
        $candidates = is_array($snapshot['candidates'] ?? null) ? $snapshot['candidates'] : [];
        $recorded = is_array($candidates[0] ?? null) ? $candidates[0] : null;
        if ($recorded === null) {
            return true;
        }

        $paymentMethod = is_string($snapshot['payment_method'] ?? null) ? $snapshot['payment_method'] : null;
        $current = $this->for($company, $vendor, $paymentMethod, $today)->top();
        if ($current === null
            || $current['category_id'] !== ($recorded['category_id'] ?? null)
            || $current['bank_account_id'] !== ($recorded['bank_account_id'] ?? null)) {
            return false;
        }

        $ids = is_array($recorded['entry_ids'] ?? null) ? array_values(array_filter($recorded['entry_ids'], 'is_string')) : [];

        return $ids === [] || $company->journalEntries()
            ->whereIn('id', $ids)
            ->where('status', EntryStatus::Confirmed)
            ->where('category_id', $recorded['category_id'])
            ->where('bank_account_id', $recorded['bank_account_id'])
            ->count() === count($ids);
    }

    private function isUsable(Company $company, JournalEntry $entry): bool
    {
        return $entry->category !== null
            && $entry->bankAccount !== null
            && $entry->category->company_id === $company->id
            && $entry->bankAccount->company_id === $company->id
            && $entry->category->archived_at === null
            && $entry->bankAccount->archived_at === null;
    }
}
