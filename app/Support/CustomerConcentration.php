<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * 取引先への依存度（#75）。上位1社・上位2社が売上の何%かを規則で出す。AI は使わず、同じ入力なら同じ答えにする。
 * Python の参照実装（tools/analysis/concentration.py）と、tests/fixtures/concentration_vectors.json で同じ答えになることを確かめる。
 *
 * - 渡すのは金額（円の整数）だけ。取引先の名前は受け取らない
 * - 合計は渡した全部の和（社長の画面の ⑤ 売上の柱と同じ）。合計が0円以下なら出さない（null）
 * - 上位は金額の大きい順。2社目が0円以下（返品だけなど）なら、上位2社には足さない
 * - 割合は整数の%（四捨五入。.5 は0から遠いほうへ。float は使わない）。返品で合計より大きくなっても100%まで
 * - 金額は ±4京円（4×10^16）までを想定する（それより大きいと整数で計算できない）
 * - 知らせるのは、上位2社の%が会社設定の%を「超えた」とき。同じ値は知らせない。表示と同じ整数の%で比べる
 *
 * @phpstan-type Concentration array{customer_count: int, total_yen: int, top1_yen: int, top1_pct: int, top2_yen: int, top2_pct: int, alert_pct: int, over: bool}
 */
final class CustomerConcentration
{
    public const MIN_ALERT_PCT = 1;

    public const MAX_ALERT_PCT = 100;

    public const DEFAULT_ALERT_PCT = 70;

    /**
     * @param  array<array-key, mixed>  $amounts  取引先ごとの売上（円）。順番は問わない
     * @return Concentration|null
     */
    public static function compute(array $amounts, int $alertPct): ?array
    {
        if ($alertPct < self::MIN_ALERT_PCT || $alertPct > self::MAX_ALERT_PCT) {
            throw new InvalidArgumentException('知らせる割合は1〜100の整数で渡してください。');
        }

        $sorted = [];
        foreach ($amounts as $amount) {
            if (! is_int($amount)) {
                throw new InvalidArgumentException('金額は整数の円で渡してください。');
            }
            $sorted[] = $amount;
        }
        rsort($sorted);

        $total = array_sum($sorted);
        if ($sorted === [] || $total <= 0) {
            return null;
        }

        $top1 = $sorted[0];
        $top2 = $top1 + max($sorted[1] ?? 0, 0);
        $top2Pct = self::sharePct($top2, $total);

        return [
            'customer_count' => count($sorted),
            'total_yen' => $total,
            'top1_yen' => $top1,
            'top1_pct' => self::sharePct($top1, $total),
            'top2_yen' => $top2,
            'top2_pct' => $top2Pct,
            'alert_pct' => $alertPct,
            'over' => $top2Pct > $alertPct,
        ];
    }

    /**
     * 金額が合計の何%か（整数。.5 は0から遠いほうへ）。合計が0円以下なら 0。100%を超える分は100%にする。
     */
    public static function sharePct(int $amount, int $total): int
    {
        if ($total <= 0) {
            return 0;
        }

        $pct = intdiv(abs($amount) * 200 + $total, $total * 2);

        return $amount < 0 ? -$pct : min($pct, self::MAX_ALERT_PCT);
    }
}
