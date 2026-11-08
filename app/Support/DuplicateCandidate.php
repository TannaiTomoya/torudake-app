<?php

namespace App\Support;

use App\Enums\DuplicateStrength;
use App\Models\DuplicateReview;

/**
 * 二重計上の候補1件。根拠（reasons）はコードで再現できる条件の名前。
 */
final readonly class DuplicateCandidate
{
    /**
     * @param  list<string>  $reasons  amount_same・date_same・date_near・vendor_same・vendor_similar・vendor_unknown・date_unconfirmed・payment_differs
     */
    public function __construct(
        public string $entryId,
        public ?string $sourceId,
        public DuplicateStrength $strength,
        public array $reasons,
        public string $date,
        public ?string $vendor,
        public int $amountYen,
        public string $statusLabel,
        public string $candidateFingerprint,
        public string $targetFingerprint,
        public int $dayDifference,
        public ?DuplicateReview $review = null,
    ) {}

    public function withReview(?DuplicateReview $review): self
    {
        return new self($this->entryId, $this->sourceId, $this->strength, $this->reasons, $this->date, $this->vendor, $this->amountYen, $this->statusLabel, $this->candidateFingerprint, $this->targetFingerprint, $this->dayDifference, $review);
    }

    /**
     * 経理が「別の取引」と判断済み（判断したときと同じ値のときだけ）。
     */
    public function resolvedAsDifferent(): bool
    {
        return $this->review !== null && $this->review->decision->value === 'different';
    }

    /**
     * 気になる印に数える候補か。強い・近い一致で、「別の取引」と判断されていないもの。
     */
    public function raisesConcern(): bool
    {
        return $this->strength->raisesConcern() && ! $this->resolvedAsDifferent();
    }

    public static function reasonLabel(string $reason, int $days = 0): string
    {
        return match ($reason) {
            'amount_same' => '金額が同じ',
            'date_same' => '日付が同じ',
            'date_near' => "日付が{$days}日ちがい",
            'vendor_same' => '店名が同じ（表記のちがいをそろえて比較）',
            'vendor_similar' => '店名が似ている（一方がもう一方を含む）',
            'vendor_unknown' => '店名が片方か両方わからない',
            'date_unconfirmed' => '日付が未確認（AIが読めず仮に入れた日付）なので、確かな一致とは扱わない',
            'payment_differs' => '請求書（未払金）と、支払い済みの領収書の組み合わせかもしれない',
            default => $reason,
        };
    }
}
