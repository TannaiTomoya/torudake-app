<?php

namespace App\Support;

/**
 * 読み取り欄（日付・店名・金額）ごとに、「AIが読んだ値」「読めず補った値」と、要確認の理由を分けて扱う。
 * AI の自信度は正解率ではない。閾値以上でも読み違いはあり得るので、「確認不要」とは表示しない。
 * 画像上の位置はわからないので、文字の位置を示す枠は作らない。
 *
 * journal_entries.reading_review には、下書きを作ったときの「読めたか・補ったか」だけを残す（原文は ai_runs）。
 */
final class ReadingReview
{
    public const FIELDS = ['date' => '日付', 'vendor' => '店名', 'amount' => '金額'];

    /** ai_check.reading_confidence の項目名 */
    private const CONFIDENCE_KEY = ['date' => 'date', 'vendor' => 'vendor', 'amount' => 'total'];

    private const REASON_LABELS = [
        'date_missing' => '日付が読めませんでした。今日の日付を仮に入れています（未確認）',
        'date_invalid' => '日付として読めませんでした。今日の日付を仮に入れています（未確認）',
        'date_future' => '未来の日付が読み取られました。今日の日付を仮に入れています（未確認）',
        'vendor_missing' => '店名が読めませんでした',
        'amount_missing' => '金額の記載がありません（人が入れます）',
        'low_confidence' => 'AIの読み取りの自信が低めです',
        'confidence_unknown' => 'AIの読み取りの自信が不明です（確認不要とは言えません）',
    ];

    /**
     * AI の読み取り（L1）から、下書きを作るときに残す。
     *
     * @param  array<string, mixed>  $reading
     * @return array<string, mixed>
     */
    public static function forDraft(array $reading, ?string $vendor): array
    {
        $dateProblem = ReadingChecks::dateProblem($reading['date'] ?? null);
        $total = $reading['total_yen'] ?? null;

        return [
            'date' => $dateProblem === null ? ['origin' => 'read'] : ['origin' => 'defaulted', 'problem' => $dateProblem],
            'vendor' => ['origin' => $vendor !== null && trim($vendor) !== '' ? 'read' : 'missing'],
            'amount' => ['origin' => is_int($total) && $total >= 1 ? 'read' : 'missing'],
        ];
    }

    /**
     * AI が読めず、人が最初から入れる下書き。日付は今日の日付が初期値で、読んだ値ではない。
     *
     * @return array<string, mixed>
     */
    public static function forManual(): array
    {
        return [
            'date' => ['origin' => 'defaulted', 'problem' => 'missing'],
            'vendor' => ['origin' => 'missing'],
            'amount' => ['origin' => 'missing'],
        ];
    }

    /**
     * 欄ごとの状態。reading_review がない仕訳（古いデータ）は空。
     *
     * @param  array<string, mixed>|null  $review  journal_entries.reading_review
     * @param  array<string, mixed>|null  $aiCheck  journal_entries.ai_check
     * @return array<string, array{field: string, label: string, origin: string, reasons: list<string>, needs_confirmation: bool}>
     */
    public static function items(?array $review, ?array $aiCheck): array
    {
        if ($review === null) {
            return [];
        }

        $low = ReadingChecks::lowConfidenceFields($aiCheck);
        $unknown = ReadingChecks::unknownConfidenceFields($aiCheck);
        $items = [];

        foreach (self::FIELDS as $field => $label) {
            $info = is_array($review[$field] ?? null) ? $review[$field] : [];
            $origin = in_array($info['origin'] ?? null, ['read', 'defaulted', 'missing'], true) ? (string) $info['origin'] : 'read';
            $reasons = [];

            if ($origin === 'defaulted') {
                $reasons[] = 'date_'.(in_array($info['problem'] ?? null, ['missing', 'invalid', 'future'], true) ? $info['problem'] : 'invalid');
            } elseif ($origin === 'missing') {
                $reasons[] = $field.'_missing';
            }

            $key = self::CONFIDENCE_KEY[$field];

            if ($origin === 'read' && in_array($key, $low, true)) {
                $reasons[] = 'low_confidence';
            }

            if ($origin === 'read' && in_array($key, $unknown, true)) {
                $reasons[] = 'confidence_unknown';
            }

            $items[$field] = [
                'field' => $field,
                'label' => $label,
                'origin' => $origin,
                'reasons' => $reasons,
                // 保存を止めるのは、AI が読めず補った日付を、人が確かめも直しもしないとき（本人の決定 2026-11-08）
                'needs_confirmation' => $field === 'date' && $origin === 'defaulted',
            ];
        }

        return $items;
    }

    public static function reasonLabel(string $reason): string
    {
        return self::REASON_LABELS[$reason] ?? $reason;
    }

    public static function originLabel(string $origin): string
    {
        return match ($origin) {
            'defaulted' => '未確認の初期値',
            'missing' => 'AIは読めませんでした',
            default => 'AIが読んだ値',
        };
    }
}
