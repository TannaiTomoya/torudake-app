<?php

namespace App\Support;

use App\Enums\BankAccountKind;
use App\Enums\DuplicateStrength;
use App\Enums\Side;
use App\Models\Company;
use App\Models\DuplicateReview;
use App\Models\JournalEntry;
use App\Models\SourceRecord;
use Carbon\CarbonImmutable;

/**
 * 同じ会社の中で、二重計上かもしれない仕訳を、コードの条件で探す（AI は使わない。結果は同じ入力なら同じ）。
 *
 * 範囲：前後 WINDOW_DAYS 日・同じ金額（借方の行）。対象外の原資料は含めない（下書き・確定・社長から戻しは含める）。
 * 支出と収入（返金）は別の取引として除く。店名が明らかに違うものも除く。
 * 日付が未確認の仮の値のときは「確かな一致」にしない。「重複」とは断定せず、判断は人がする。
 * 完全な重複検出ではない。範囲外の日付・金額が違うもの・店名がまったく違うものは拾わない。
 */
class DuplicateCandidateMatcher
{
    public const WINDOW_DAYS = 7;

    public const DISPLAY_LIMIT = 5;

    /** 店名が「似ている」と見る、短いほうの最小の文字数 */
    private const SIMILAR_MIN_LENGTH = 3;

    /**
     * 保存されている下書き・仕訳の値で探す。仕訳（金額）がまだない原資料は候補なし。
     */
    public function forSource(Company $company, SourceRecord $source): DuplicateCandidates
    {
        $entry = $source->journal_entry_id !== null
            ? $company->journalEntries()->with(['category', 'bankAccount'])->find($source->journal_entry_id)
            : null;

        if ($entry === null) {
            return $this->empty();
        }

        $review = ReadingReview::items($entry->reading_review, $entry->ai_check);

        return $this->match($company, new DuplicateTarget(
            sourceId: $source->id,
            entryId: $entry->id,
            date: CarbonImmutable::parse($entry->entry_date->toDateString()),
            vendor: $entry->vendor,
            amountYen: $entry->amountYen(),
            direction: $entry->category?->direction,
            bankKind: $entry->bankAccount?->kind,
            dateUncertain: ($review['date']['needs_confirmation'] ?? false) && $entry->confirmed_at === null,
        ));
    }

    public function match(Company $company, DuplicateTarget $target): DuplicateCandidates
    {
        if ($target->amountYen === null || $target->amountYen < 1) {
            return $this->empty();
        }

        $entries = $company->journalEntries()
            ->with(['category', 'bankAccount', 'sources'])
            ->when($target->entryId !== null, fn ($query) => $query->whereKeyNot($target->entryId))
            ->whereBetween('entry_date', [$target->date->subDays(self::WINDOW_DAYS)->toDateString(), $target->date->addDays(self::WINDOW_DAYS)->toDateString()])
            ->whereHas('lines', fn ($query) => $query->where('side', Side::Debit)->where('amount_yen', $target->amountYen))
            ->get();

        $targetFingerprint = self::targetFingerprint($target);
        $candidates = [];

        foreach ($entries as $entry) {
            if ($entry->sources->contains(fn (SourceRecord $source): bool => $source->id === $target->sourceId)) {
                continue;
            }

            $candidate = $this->evaluate($target, $entry, $targetFingerprint);

            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        usort($candidates, fn (DuplicateCandidate $a, DuplicateCandidate $b): int => [$a->strength->rank(), $a->dayDifference, $b->date, $a->entryId] <=> [$b->strength->rank(), $b->dayDifference, $a->date, $b->entryId]);

        return new DuplicateCandidates($this->withReviews($company, $target, $candidates), self::WINDOW_DAYS, self::DISPLAY_LIMIT);
    }

    public static function normalizeVendor(?string $vendor): string
    {
        $name = mb_strtolower(mb_convert_kana((string) $vendor, 'asKV'));
        $name = str_replace(['株式会社', '有限会社', '合同会社', '(株)', '(有)', '(同)', '㈱', '㈲'], '', $name);

        return (string) preg_replace('/[\s\-‐－―・,，.．()「」『』]+/u', '', $name);
    }

    public static function targetFingerprint(DuplicateTarget $target): string
    {
        return sha1(implode('|', ['t', $target->date->toDateString(), self::normalizeVendor($target->vendor), (int) $target->amountYen]));
    }

    public static function candidateFingerprint(JournalEntry $entry, int $amountYen): string
    {
        return sha1(implode('|', ['c', $entry->id, $entry->entry_date->toDateString(), self::normalizeVendor($entry->vendor), $amountYen]));
    }

    private function empty(): DuplicateCandidates
    {
        return new DuplicateCandidates([], self::WINDOW_DAYS, self::DISPLAY_LIMIT);
    }

    private function evaluate(DuplicateTarget $target, JournalEntry $entry, string $targetFingerprint): ?DuplicateCandidate
    {
        $amount = $entry->amountYen();
        $direction = $entry->category?->direction;

        // 返金（収入）と支出は別の取引
        if ($amount === null || ($target->direction !== null && $direction !== null && $target->direction !== $direction)) {
            return null;
        }

        $vendorRelation = $this->vendorRelation($target->vendor, $entry->vendor);

        if ($vendorRelation === 'different') {
            return null;
        }

        $days = (int) abs($target->date->diffInDays(CarbonImmutable::parse($entry->entry_date->toDateString()), true));
        $sameDate = $days === 0;

        $strength = match (true) {
            $vendorRelation === 'equal' && $sameDate => DuplicateStrength::Strong,
            $vendorRelation === 'equal', $vendorRelation === 'similar' && $sameDate => DuplicateStrength::Likely,
            default => DuplicateStrength::Weak,
        };

        $entryDateUncertain = ($entry->reading_review['date']['origin'] ?? null) === 'defaulted' && $entry->confirmed_at === null;
        $uncertain = $target->dateUncertain || $entryDateUncertain;

        if ($uncertain) {
            $strength = DuplicateStrength::Weak;
        }

        $reasons = ['amount_same', $sameDate ? 'date_same' : 'date_near', $vendorRelation === 'equal' ? 'vendor_same' : 'vendor_'.$vendorRelation];

        if ($uncertain) {
            $reasons[] = 'date_unconfirmed';
        }

        if (($target->bankKind === BankAccountKind::Invoice) !== ($entry->bankAccount?->kind === BankAccountKind::Invoice)
            && $target->bankKind !== null && $entry->bankAccount !== null) {
            $reasons[] = 'payment_differs';
        }

        return new DuplicateCandidate(
            entryId: $entry->id,
            sourceId: $entry->sources->first()?->id,
            strength: $strength,
            reasons: $reasons,
            date: $entry->entry_date->toDateString(),
            vendor: $entry->vendor,
            amountYen: $amount,
            statusLabel: $entry->status->label(),
            candidateFingerprint: self::candidateFingerprint($entry, $amount),
            targetFingerprint: $targetFingerprint,
            dayDifference: $days,
        );
    }

    /**
     * @return 'equal'|'similar'|'unknown'|'different'
     */
    private function vendorRelation(?string $a, ?string $b): string
    {
        $x = self::normalizeVendor($a);
        $y = self::normalizeVendor($b);

        if ($x === '' || $y === '') {
            return 'unknown';
        }

        if ($x === $y) {
            return 'equal';
        }

        [$short, $long] = mb_strlen($x) <= mb_strlen($y) ? [$x, $y] : [$y, $x];

        return mb_strlen($short) >= self::SIMILAR_MIN_LENGTH && str_contains($long, $short) ? 'similar' : 'different';
    }

    /**
     * 判断（別の取引・保留）は、判断したときと対象・候補の値が同じときだけ使う。変わっていたら無かったことにする。
     *
     * @param  list<DuplicateCandidate>  $candidates
     * @return list<DuplicateCandidate>
     */
    private function withReviews(Company $company, DuplicateTarget $target, array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        $latest = DuplicateReview::query()
            ->where('company_id', $company->id)
            ->where('source_record_id', $target->sourceId)
            ->whereIn('candidate_entry_id', array_map(fn (DuplicateCandidate $c): string => $c->entryId, $candidates))
            ->with('decider')
            ->orderBy('decided_at')
            ->orderBy('created_at')
            ->get()
            ->keyBy('candidate_entry_id');

        return array_map(function (DuplicateCandidate $candidate) use ($latest): DuplicateCandidate {
            $review = $latest->get($candidate->entryId);

            $valid = $review instanceof DuplicateReview
                && $review->target_fingerprint === $candidate->targetFingerprint
                && $review->candidate_fingerprint === $candidate->candidateFingerprint;

            return $candidate->withReview($valid ? $review : null);
        }, $candidates);
    }
}
