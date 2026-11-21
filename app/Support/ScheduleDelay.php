<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * 入金と支払いの予定の「遅れ」（#67）。規則だけで判定し、AI は使わない。同じ入力なら同じ答えにする。
 * Python の参照実装（tools/analysis/schedule.py）と、tests/fixtures/schedule_vectors.json で同じ答えになることを確かめる。
 *
 * - 渡すのは日付（YYYY-MM-DD）・金額（円の整数）・入金か支払いかだけ。取引先の名前は受け取らない
 * - 済みの日があれば「済み」（予定日より後に済んでも、いまは遅れではない）
 * - 済みの日がなく、予定日が今日より前なら「遅れ」。遅れの日数は 今日 − 予定日
 * - 予定日が今日なら「今日」（遅れではない）。今日より先なら「これから」
 * - まとめは入金と支払いを分けて、まだ済んでいない件数・金額と、そのうち遅れの件数・金額を出す
 *
 * @phpstan-type Judgement array{status: string, days_late: int}
 * @phpstan-type SideSummary array{open_count: int, open_yen: int, late_count: int, late_yen: int}
 * @phpstan-type Summary array{in: SideSummary, out: SideSummary}
 */
final class ScheduleDelay
{
    public const STATUS_SETTLED = 'settled';

    public const STATUS_LATE = 'late';

    public const STATUS_DUE_TODAY = 'due_today';

    public const STATUS_UPCOMING = 'upcoming';

    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    /**
     * @return Judgement
     */
    public static function judge(mixed $dueDate, mixed $settledOn, mixed $today): array
    {
        $due = self::day($dueDate, '予定日');
        $now = self::day($today, '今日');

        if ($settledOn !== null) {
            self::day($settledOn, '済みの日');

            return ['status' => self::STATUS_SETTLED, 'days_late' => 0];
        }

        return match (true) {
            $due < $now => ['status' => self::STATUS_LATE, 'days_late' => $now - $due],
            $due === $now => ['status' => self::STATUS_DUE_TODAY, 'days_late' => 0],
            default => ['status' => self::STATUS_UPCOMING, 'days_late' => 0],
        };
    }

    /**
     * @param  array<array-key, mixed>  $items  それぞれ direction（in／out）・amount_yen・due_date・settled_on
     * @return Summary
     */
    public static function summarize(array $items, mixed $today): array
    {
        $empty = ['open_count' => 0, 'open_yen' => 0, 'late_count' => 0, 'late_yen' => 0];
        $summary = [self::DIRECTION_IN => $empty, self::DIRECTION_OUT => $empty];
        self::day($today, '今日');

        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException('予定は、入金か支払いか・金額・予定日・済みの日の組で渡してください。');
            }

            $direction = $item['direction'] ?? null;
            $amount = $item['amount_yen'] ?? null;
            if ($direction !== self::DIRECTION_IN && $direction !== self::DIRECTION_OUT) {
                throw new InvalidArgumentException('入金か支払いかは in か out で渡してください。');
            }
            if (! is_int($amount) || $amount <= 0) {
                throw new InvalidArgumentException('金額は1円以上の整数で渡してください。');
            }

            $judgement = self::judge($item['due_date'] ?? null, $item['settled_on'] ?? null, $today);
            if ($judgement['status'] === self::STATUS_SETTLED) {
                continue;
            }

            $summary[$direction]['open_count']++;
            $summary[$direction]['open_yen'] += $amount;
            if ($judgement['status'] === self::STATUS_LATE) {
                $summary[$direction]['late_count']++;
                $summary[$direction]['late_yen'] += $amount;
            }
        }

        return $summary;
    }

    /**
     * YYYY-MM-DD を、1970-01-01 からの日数にする（時刻・時差は使わない）。
     */
    private static function day(mixed $value, string $label): int
    {
        if (! is_string($value) || preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidArgumentException($label.'は YYYY-MM-DD の日付で渡してください。');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if ($date === false) {
            throw new InvalidArgumentException($label.'は YYYY-MM-DD の日付で渡してください。');
        }

        return intdiv($date->getTimestamp(), 86400);
    }
}
