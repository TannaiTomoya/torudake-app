<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * 読み取り値の日付・自信・宛名を確かめる（原資料・仕訳・画面で同じ決まりを使う）。
 */
final class ReadingChecks
{
    /** 欄ごとの「自信が低い」の境目。合計は読み違いの害が大きいので高めにする */
    public const THRESHOLDS = ['date' => 0.7, 'vendor' => 0.7, 'total' => 0.8];

    public const ADDRESSEE_KINDS = ['own', 'other_company', 'person', 'none'];

    /**
     * AI の日付を厳密な YYYY-MM-DD として検証する。存在しない日・未来日は保存対象にしない。
     */
    public static function validReceiptDate(mixed $value): ?CarbonImmutable
    {
        $date = self::parseDate($value);

        return $date !== null && ! $date->isAfter(CarbonImmutable::today()) ? $date : null;
    }

    /**
     * AI の日付が使えない理由。使えるなら null。
     *
     * @return 'missing'|'invalid'|'future'|null
     */
    public static function dateProblem(mixed $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return 'missing';
        }

        $date = self::parseDate($value);

        if ($date === null) {
            return 'invalid';
        }

        return $date->isAfter(CarbonImmutable::today()) ? 'future' : null;
    }

    private static function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }

        $errors = CarbonImmutable::getLastErrors();

        if (! $date instanceof CarbonImmutable
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->toDateString() !== $value) {
            return null;
        }

        return $date;
    }

    /**
     * @param  array<string, mixed>|null  $aiCheck  仕訳の ai_check
     * @return list<string> date・vendor・total のうち自信が低いもの
     */
    public static function lowConfidenceFields(?array $aiCheck): array
    {
        $confidence = is_array($aiCheck['reading_confidence'] ?? null) ? $aiCheck['reading_confidence'] : [];
        $amountMissing = ($aiCheck['amount_missing'] ?? false) === true;
        $low = [];

        foreach (self::THRESHOLDS as $field => $threshold) {
            if ($field === 'total' && $amountMissing) {
                continue;
            }

            if (is_numeric($confidence[$field] ?? null) && (float) $confidence[$field] < $threshold) {
                $low[] = $field;
            }
        }

        return $low;
    }

    /**
     * 自信度が欠けている・範囲外（0〜1でない）・数字でない欄。「確認不要」とは扱わない。
     * 自信度の項目自体がない仕訳（AI がまだ読んでいない・古いデータ）は対象にしない。
     *
     * @param  array<string, mixed>|null  $aiCheck  仕訳の ai_check
     * @return list<string> date・vendor・total のうち自信度が使えないもの
     */
    public static function unknownConfidenceFields(?array $aiCheck): array
    {
        if ($aiCheck === null || ! array_key_exists('reading_confidence', $aiCheck)) {
            return [];
        }

        $confidence = is_array($aiCheck['reading_confidence']) ? $aiCheck['reading_confidence'] : [];
        $amountMissing = ($aiCheck['amount_missing'] ?? false) === true;
        $unknown = [];

        foreach (array_keys(self::THRESHOLDS) as $field) {
            if ($field === 'total' && $amountMissing) {
                continue;
            }

            $value = $confidence[$field] ?? null;

            if (! is_numeric($value) || (float) $value < 0.0 || (float) $value > 1.0) {
                $unknown[] = $field;
            }
        }

        return $unknown;
    }

    /**
     * AI の答えた宛名の種類を、会社名との突き合わせで確かめる。
     * 「(株)」「株式会社」・敬称・空白の違いだけなら自分の会社、上様・空欄は none。
     */
    public static function addresseeKind(?string $addressee, ?string $aiKind, string $companyName): string
    {
        $name = self::normalize((string) $addressee);

        if ($name === '' || $name === '上') {
            return 'none';
        }

        if ($name === self::normalize($companyName)) {
            return 'own';
        }

        return in_array($aiKind, self::ADDRESSEE_KINDS, true) ? (string) $aiKind : 'other_company';
    }

    private static function normalize(string $name): string
    {
        $name = mb_convert_kana($name, 'asKV');

        return str_replace(
            ['株式会社', '有限会社', '合同会社', '(株)', '(有)', '(同)', '㈱', '㈲', '（株）', '（有）', '御中', '様', '殿', ' ', '　'],
            '',
            $name,
        );
    }
}
