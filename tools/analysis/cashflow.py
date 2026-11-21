"""資金繰り表の日ごとの見込み（#68）の参照実装。PHP の App\\Support\\CashFlowForecast と同じ規則。

- 標準ライブラリだけ。起点の残高・開始日・終了日・予定（日付・入金か支払いか・金額）だけを使う。取引先の名前は受け取らない。
- tests/fixtures/cashflow_vectors.json の共通ベクトルで、PHP と同じ答えになることを確かめる。
- 予定日は動かさない。土日の行に weekend=True（祝日は見ない）。
- 同じ日は入金合計 − 支払合計。balance_yen < 0 の日だけ below_zero=True。
"""

from __future__ import annotations

import re
from datetime import date, timedelta
from typing import Any

from weekend import is_weekend, month_ends_on_weekend_in_range

DIRECTION_IN = "in"
DIRECTION_OUT = "out"

_DATE = re.compile(r"\A\d{4}-\d{2}-\d{2}\Z")


def _day(value: Any) -> date:
    if not isinstance(value, str) or not _DATE.match(value):
        raise ValueError("日付は YYYY-MM-DD で渡してください。")
    try:
        return date.fromisoformat(value)
    except ValueError as error:
        raise ValueError("日付は YYYY-MM-DD で渡してください。") from error


def project(
    opening_balance_yen: int,
    from_date: Any,
    to_date: Any,
    items: list[Any],
) -> dict[str, Any]:
    if not isinstance(opening_balance_yen, int) or isinstance(opening_balance_yen, bool):
        raise ValueError("起点の残高は整数の円で渡してください。")

    start = _day(from_date)
    end = _day(to_date)
    if end < start:
        raise ValueError("終了日は開始日と同じか、それより後の日付で渡してください。")

    by_date: dict[str, dict[str, int]] = {}
    for item in items:
        if not isinstance(item, dict):
            raise ValueError("予定は、日付・入金か支払いか・金額の組で渡してください。")

        direction = item.get("direction")
        amount = item.get("amount_yen")
        if direction not in (DIRECTION_IN, DIRECTION_OUT):
            raise ValueError("入金か支払いかは in か out で渡してください。")
        if not isinstance(amount, int) or isinstance(amount, bool) or amount <= 0:
            raise ValueError("金額は1円以上の整数で渡してください。")

        due = _day(item.get("due_date")).isoformat()
        if due < start.isoformat() or due > end.isoformat():
            continue

        bucket = by_date.setdefault(due, {"in": 0, "out": 0})
        bucket[direction] += amount

    days: list[dict[str, Any]] = []
    below_zero_dates: list[str] = []
    balance = opening_balance_yen
    cursor = start

    while cursor <= end:
        key = cursor.isoformat()
        inflow = by_date.get(key, {}).get("in", 0)
        outflow = by_date.get(key, {}).get("out", 0)
        balance = balance + inflow - outflow
        below_zero = balance < 0
        if below_zero:
            below_zero_dates.append(key)

        days.append(
            {
                "date": key,
                "weekend": is_weekend(key),
                "in_yen": inflow,
                "out_yen": outflow,
                "balance_yen": balance,
                "below_zero": below_zero,
            }
        )
        cursor += timedelta(days=1)

    return {
        "days": days,
        "month_end_weekend_months": month_ends_on_weekend_in_range(
            start.isoformat(),
            end.isoformat(),
        ),
        "below_zero_dates": below_zero_dates,
    }
