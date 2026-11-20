"""取引先への依存度（#75）の参照実装。PHP の App\\Support\\CustomerConcentration と同じ規則。

- 標準ライブラリだけ。整数の円だけを使い、float は使わない。取引先の名前は受け取らない。
- tests/fixtures/concentration_vectors.json の共通ベクトルで、PHP と同じ答えになることを確かめる。
- 合計は渡した全部の和。合計が0円以下なら出さない（None）。
- 上位は金額の大きい順。2社目が0円以下なら、上位2社には足さない。
- 割合は整数の%（.5 は0から遠いほうへ。返品で合計より大きくなっても100%まで）。上位2社の%が知らせる%を「超えた」ときだけ知らせる。
"""

from __future__ import annotations

from typing import Any

MIN_ALERT_PCT = 1
MAX_ALERT_PCT = 100
DEFAULT_ALERT_PCT = 70


def _is_int(value: Any) -> bool:
    return isinstance(value, int) and not isinstance(value, bool)


def share_pct(amount: int, total: int) -> int:
    if total <= 0:
        return 0
    pct = (abs(amount) * 200 + total) // (total * 2)
    return -pct if amount < 0 else min(pct, MAX_ALERT_PCT)


def compute(amounts: list[Any], alert_pct: int) -> dict[str, Any] | None:
    if not _is_int(alert_pct) or alert_pct < MIN_ALERT_PCT or alert_pct > MAX_ALERT_PCT:
        raise ValueError("知らせる割合は1〜100の整数で渡してください。")
    if any(not _is_int(amount) for amount in amounts):
        raise ValueError("金額は整数の円で渡してください。")

    ordered = sorted(amounts, reverse=True)
    total = sum(ordered)
    if not ordered or total <= 0:
        return None

    top1 = ordered[0]
    top2 = top1 + max(ordered[1] if len(ordered) > 1 else 0, 0)
    top2_pct = share_pct(top2, total)

    return {
        "customer_count": len(ordered),
        "total_yen": total,
        "top1_yen": top1,
        "top1_pct": share_pct(top1, total),
        "top2_yen": top2,
        "top2_pct": top2_pct,
        "alert_pct": alert_pct,
        "over": top2_pct > alert_pct,
    }
