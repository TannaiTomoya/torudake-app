<?php

namespace App\Support;

/**
 * 読み取りの自信が低い欄と、宛名の種類を決める（直して確定・ライブ画面・気になる印で同じ決まりを使う）。
 */
final class ReadingChecks
{
    /** 欄ごとの「自信が低い」の境目。合計は読み違いの害が大きいので高めにする */
    public const THRESHOLDS = ['date' => 0.7, 'vendor' => 0.7, 'total' => 0.8];

    public const ADDRESSEE_KINDS = ['own', 'other_company', 'person', 'none'];

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
