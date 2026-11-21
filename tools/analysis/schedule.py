"""入金と支払いの予定の「遅れ」（#67）の参照実装。PHP の App\\Support\\ScheduleDelay と同じ規則。

- 標準ライブラリだけ。日付（YYYY-MM-DD）・金額（円の整数）・入金か支払いかだけを使う。取引先の名前は受け取らない。
- tests/fixtures/schedule_vectors.json の共通ベクトルで、PHP と同じ答えになることを確かめる。
- 済みの日があれば「済み」。済みの日がなく、予定日が今日より前なら「遅れ」（日数は 今日 − 予定日）。
- 予定日が今日なら「今日」（遅れではない）。今日より先なら「これから」。
- まとめは入金と支払いを分けて、まだ済んでいない件数・金額と、そのうち遅れの件数・金額を出す。
"""

from __future__ import annotations

import re
from datetime import date
from typing import Any

STATUS_SETTLED = "settled"
STATUS_LATE = "late"
STATUS_DUE_TODAY = "due_today"
STATUS_UPCOMING = "upcoming"

DIRECTION_IN = "in"
DIRECTION_OUT = "out"

_DATE = re.compile(r"\A\d{4}-\d{2}-\d{2}\Z")


def _day(value: Any, label: str) -> int:
    if not isinstance(value, str) or not _DATE.match(value):
        raise ValueError(f"{label}は YYYY-MM-DD の日付で渡してください。")
    try:
        return date.fromisoformat(value).toordinal()
    except ValueError as error:
        raise ValueError(f"{label}は YYYY-MM-DD の日付で渡してください。") from error


def judge(due_date: Any, settled_on: Any, today: Any) -> dict[str, Any]:
    due = _day(due_date, "予定日")
    now = _day(today, "今日")

    if settled_on is not None:
        _day(settled_on, "済みの日")
        return {"status": STATUS_SETTLED, "days_late": 0}

    if due < now:
        return {"status": STATUS_LATE, "days_late": now - due}
    if due == now:
        return {"status": STATUS_DUE_TODAY, "days_late": 0}
    return {"status": STATUS_UPCOMING, "days_late": 0}


def summarize(items: list[Any], today: Any) -> dict[str, dict[str, int]]:
    summary = {
        direction: {"open_count": 0, "open_yen": 0, "late_count": 0, "late_yen": 0}
        for direction in (DIRECTION_IN, DIRECTION_OUT)
    }
    _day(today, "今日")

    for item in items:
        if not isinstance(item, dict):
            raise ValueError("予定は、入金か支払いか・金額・予定日・済みの日の組で渡してください。")

        direction = item.get("direction")
        amount = item.get("amount_yen")
        if direction not in (DIRECTION_IN, DIRECTION_OUT):
            raise ValueError("入金か支払いかは in か out で渡してください。")
        if not isinstance(amount, int) or isinstance(amount, bool) or amount <= 0:
            raise ValueError("金額は1円以上の整数で渡してください。")

        judgement = judge(item.get("due_date"), item.get("settled_on"), today)
        if judgement["status"] == STATUS_SETTLED:
            continue

        summary[direction]["open_count"] += 1
        summary[direction]["open_yen"] += amount
        if judgement["status"] == STATUS_LATE:
            summary[direction]["late_count"] += 1
            summary[direction]["late_yen"] += amount

    return summary
