<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * 土日かどうか（#68）。祝日の表は持たない。あとで祝日や #67 の印からも使う。
 * Python の参照実装（tools/analysis/weekend.py）と同じ規則。
 */
final class WeekendCalendar
{
    /**
     * 土曜または日曜なら true。日付は動かさない（判定だけ）。
     */
    public static function isWeekend(mixed $date): bool
    {
        $day = self::parse($date);
        $weekday = (int) $day->format('N'); // 1=月 … 6=土 7=日

        return $weekday >= 6;
    }

    /**
     * その月の最終日が土日で、かつ from〜to のあいだにある月（YYYY-MM）を古い順で返す。
     *
     * @return list<string>
     */
    public static function monthEndsOnWeekendInRange(mixed $from, mixed $to): array
    {
        $start = self::parse($from);
        $end = self::parse($to);
        if ($end < $start) {
            throw new InvalidArgumentException('終了日は開始日と同じか、それより後の日付で渡してください。');
        }

        $months = [];
        $cursor = $start->modify('first day of this month');
        $lastMonth = $end->modify('first day of this month');

        while ($cursor <= $lastMonth) {
            $monthEnd = $cursor->modify('last day of this month');
            if ($monthEnd >= $start && $monthEnd <= $end && self::isWeekend($monthEnd->format('Y-m-d'))) {
                $months[] = $cursor->format('Y-m');
            }
            $cursor = $cursor->modify('first day of next month');
        }

        return $months;
    }

    private static function parse(mixed $value): DateTimeImmutable
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
