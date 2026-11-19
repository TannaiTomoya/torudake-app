"""来月の売上の見込み（#70）の参照実装。PHP の App\\Support\\SalesForecastRule と同じ規則。

- 標準ライブラリだけ。整数の円だけを使い、float は使わない。
- tests/fixtures/forecast_vectors.json の共通ベクトルで、PHP と同じ答えになることを確かめる。
- 使うのは同じ月の過去3期（1〜3年前）の売上だけ。数字のない年は数えない（実際の0円は数える）。
- 幅＝いちばん少ない年〜多い年。真ん中＝3期なら真ん中の年、2期なら平均（.5 は0から遠いほうへ）、1期ならその年。
"""

from __future__ import annotations

import re
from typing import Any

YEARS = 3
_MONTH = re.compile(r"^(\d{4})-(0[1-9]|1[0-2])$")


def _half_away_from_zero(total: int) -> int:
    half = (abs(total) + 1) // 2
    return -half if total < 0 else half


def compute(target_year_month: str, sales: dict[str, Any], busy: bool) -> dict[str, Any]:
    match = _MONTH.match(target_year_month) if isinstance(target_year_month, str) else None
    if match is None:
        raise ValueError("月は YYYY-MM で渡してください。")
    year, month = int(match.group(1)), match.group(2)

    history = []
    for back in range(YEARS, 0, -1):
        year_month = f"{year - back:04d}-{month}"
        amount = sales.get(year_month)
        valid = isinstance(amount, int) and not isinstance(amount, bool)
        history.append({"year_month": year_month, "amount_yen": amount if valid else None})

    known = sorted((row for row in history if row["amount_yen"] is not None), key=lambda r: (r["amount_yen"], r["year_month"]))

    result = {
        "target_year_month": target_year_month,
        "busy": busy,
        "history": history,
        "known_years": len(known),
        "low_yen": None, "low_year_month": None,
        "high_yen": None, "high_year_month": None,
        "middle_yen": None, "middle_year_month": None,
    }
    if not known:
        return result

    low, high = known[0], known[-1]
    result.update({
        "low_yen": low["amount_yen"], "low_year_month": low["year_month"],
        "high_yen": high["amount_yen"], "high_year_month": high["year_month"],
    })
    if len(known) == 2:
        result["middle_yen"] = _half_away_from_zero(low["amount_yen"] + high["amount_yen"])
    else:
        middle = known[len(known) // 2]
        result["middle_yen"] = middle["amount_yen"]
        result["middle_year_month"] = middle["year_month"]
    return result
