<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * 来月の売上の見込みを規則で出す（#70）。AI は使わず、同じ入力なら同じ答えにする。
 * Python の参照実装（tools/analysis/sales_forecast.py）と、tests/fixtures/forecast_vectors.json で同じ答えになることを確かめる。
 *
 * - 使うのは、見込みを出す月と同じ月の過去3期（1年前・2年前・3年前）の売上だけ
 * - 数字のない年は数えない（0円とみなさない。実際の0円は数える。#34）
 * - 幅＝いちばん少ない年〜いちばん多い年。真ん中＝3期そろえば真ん中の年、2期なら2つの平均（四捨五入・.5 は0から遠いほうへ）、1期ならその年
 * - 3つの値から統計としての「8割の範囲」は出せないので、出すのは幅と真ん中だけ。経費は出さない（3期分がない）
 *
 * @phpstan-type HistoryRow array{year_month: string, amount_yen: int|null}
 * @phpstan-type Forecast array{target_year_month: string, busy: bool, history: list<HistoryRow>, known_years: int, low_yen: int|null, low_year_month: string|null, high_yen: int|null, high_year_month: string|null, middle_yen: int|null, middle_year_month: string|null}
 */
final class SalesForecastRule
{
    public const YEARS = 3;

    /**
     * @param  array<string, mixed>  $sales  year_month（YYYY-MM）→ 売上（円）。ない月は入れない
     * @return Forecast
     */
    public static function compute(string $targetYearMonth, array $sales, bool $busy): array
    {
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $targetYearMonth, $m) !== 1) {
            throw new InvalidArgumentException('月は YYYY-MM で渡してください。');
        }

        $history = [];
        foreach (range(self::YEARS, 1) as $back) {
            $yearMonth = sprintf('%04d-%s', (int) $m[1] - $back, $m[2]);
            $amount = $sales[$yearMonth] ?? null;
            $history[] = ['year_month' => $yearMonth, 'amount_yen' => is_int($amount) ? $amount : null];
        }

        $known = array_values(array_filter($history, fn (array $row): bool => $row['amount_yen'] !== null));
        usort($known, fn (array $a, array $b): int => $a['amount_yen'] <=> $b['amount_yen'] ?: strcmp($a['year_month'], $b['year_month']));

        $result = [
            'target_year_month' => $targetYearMonth,
            'busy' => $busy,
            'history' => $history,
            'known_years' => count($known),
            'low_yen' => null, 'low_year_month' => null,
            'high_yen' => null, 'high_year_month' => null,
            'middle_yen' => null, 'middle_year_month' => null,
        ];
        if ($known === []) {
            return $result;
        }

        $low = $known[0];
        $high = $known[count($known) - 1];
        $result['low_yen'] = $low['amount_yen'];
        $result['low_year_month'] = $low['year_month'];
        $result['high_yen'] = $high['amount_yen'];
        $result['high_year_month'] = $high['year_month'];

        if (count($known) === 2) {
            $result['middle_yen'] = self::halfAwayFromZero((int) $low['amount_yen'] + (int) $high['amount_yen']);
        } else {
            $middle = $known[intdiv(count($known), 2)];
            $result['middle_yen'] = $middle['amount_yen'];
            $result['middle_year_month'] = $middle['year_month'];
        }

        return $result;
    }

    /** 2つの和を2で割り、.5 は0から遠いほうへ丸める（float を使わない） */
    private static function halfAwayFromZero(int $sum): int
    {
        $half = intdiv(abs($sum) + 1, 2);

        return $sum < 0 ? -$half : $half;
    }
}
