<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * 資金繰り表の日ごとの見込み（#68）。規則だけで計算し、AI は使わない。同じ入力なら同じ答え。
 * Python の参照実装（tools/analysis/cashflow.py）と、tests/fixtures/cashflow_vectors.json で同じ答えになることを確かめる。
 *
 * - 渡すのは起点の残高・開始日・終了日・予定（日付・入金か支払いか・金額）だけ。取引先の名前は受け取らない
 * - 予定日は動かさない。土日の行に weekend=true（祝日は見ない）
 * - 同じ日は入金合計 − 支払合計をその日の増減にする
 * - balance_yen < 0 の日だけ below_zero=true（借越の限度額は見ない）
 * - 金額は整数の円。予定の金額は1円以上
 *
 * @phpstan-type Day array{date: string, weekend: bool, in_yen: int, out_yen: int, balance_yen: int, below_zero: bool}
 * @phpstan-type Forecast array{days: list<Day>, month_end_weekend_months: list<string>, below_zero_dates: list<string>}
 */
final class CashFlowForecast
{
    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    /**
     * @param  array<array-key, mixed>  $items  それぞれ due_date・direction（in／out）・amount_yen
     * @return Forecast
     */
    public static function project(int $openingBalanceYen, mixed $fromDate, mixed $toDate, array $items): array
    {
        $from = self::day($fromDate);
        $to = self::day($toDate);
        if ($to < $from) {
            throw new InvalidArgumentException('終了日は開始日と同じか、それより後の日付で渡してください。');
        }

        $byDate = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException('予定は、日付・入金か支払いか・金額の組で渡してください。');
            }

            $direction = $item['direction'] ?? null;
            $amount = $item['amount_yen'] ?? null;
            if ($direction !== self::DIRECTION_IN && $direction !== self::DIRECTION_OUT) {
                throw new InvalidArgumentException('入金か支払いかは in か out で渡してください。');
            }
            if (! is_int($amount) || $amount <= 0) {
                throw new InvalidArgumentException('金額は1円以上の整数で渡してください。');
            }

            $due = self::day($item['due_date'] ?? null)->format('Y-m-d');
            if ($due < $from->format('Y-m-d') || $due > $to->format('Y-m-d')) {
                continue;
            }

            if (! isset($byDate[$due])) {
                $byDate[$due] = ['in' => 0, 'out' => 0];
            }
            $byDate[$due][$direction] += $amount;
        }

        $days = [];
        $belowZeroDates = [];
        $balance = $openingBalanceYen;
        $cursor = $from;

        while ($cursor <= $to) {
            $date = $cursor->format('Y-m-d');
            $in = $byDate[$date]['in'] ?? 0;
            $out = $byDate[$date]['out'] ?? 0;
            $balance = $balance + $in - $out;
            $belowZero = $balance < 0;
            if ($belowZero) {
                $belowZeroDates[] = $date;
            }

            $days[] = [
                'date' => $date,
                'weekend' => WeekendCalendar::isWeekend($date),
                'in_yen' => $in,
                'out_yen' => $out,
                'balance_yen' => $balance,
                'below_zero' => $belowZero,
            ];

            $cursor = $cursor->modify('+1 day');
        }

        return [
            'days' => $days,
            'month_end_weekend_months' => WeekendCalendar::monthEndsOnWeekendInRange(
                $from->format('Y-m-d'),
                $to->format('Y-m-d'),
            ),
            'below_zero_dates' => $belowZeroDates,
        ];
    }

    private static function day(mixed $value): DateTimeImmutable
    {
        if (! is_string($value) || preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidArgumentException('日付は YYYY-MM-DD で渡してください。');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if ($date === false) {
            throw new InvalidArgumentException('日付は YYYY-MM-DD で渡してください。');
        }

        return $date;
    }
}
