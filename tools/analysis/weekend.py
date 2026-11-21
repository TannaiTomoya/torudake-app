"""土日かどうか（#68）の参照実装。PHP の App\\Support\\WeekendCalendar と同じ規則。

- 標準ライブラリだけ。祝日の表は持たない。土曜または日曜なら True。
- 日付は動かさない（判定だけ）。あとで祝日や #67 の印からも使う。
"""

from __future__ import annotations

import re
from datetime import date, timedelta
from typing import Any

_DATE = re.compile(r"\A\d{4}-\d{2}-\d{2}\Z")


def _parse(value: Any) -> date:
    if not isinstance(value, str) or not _DATE.match(value):
        raise ValueError("日付は YYYY-MM-DD で渡してください。")
    try:
        return date.fromisoformat(value)
    except ValueError as error:
        raise ValueError("日付は YYYY-MM-DD で渡してください。") from error


def is_weekend(value: Any) -> bool:
    day = _parse(value)
    return day.weekday() >= 5  # 0=月 … 5=土 6=日


def month_ends_on_weekend_in_range(from_date: Any, to_date: Any) -> list[str]:
    start = _parse(from_date)
    end = _parse(to_date)
    if end < start:
        raise ValueError("終了日は開始日と同じか、それより後の日付で渡してください。")

    months: list[str] = []
    cursor = start.replace(day=1)
    last_month = end.replace(day=1)

    while cursor <= last_month:
        if cursor.month == 12:
            next_month = cursor.replace(year=cursor.year + 1, month=1, day=1)
        else:
            next_month = cursor.replace(month=cursor.month + 1, day=1)
        month_end = next_month - timedelta(days=1)
        if start <= month_end <= end and is_weekend(month_end.isoformat()):
            months.append(f"{cursor.year:04d}-{cursor.month:02d}")
        cursor = next_month

    return months
