"""月の試算表（#65）の参照実装。PHP の App\\Support\\TrialBalance と同じ規則。

- 標準ライブラリだけ。整数の円だけを使い、float は使わない。
- tests/fixtures/trial_balance_vectors.json の共通ベクトルで、PHP と同じ答えになることを確かめる。
- 足すのは確定（confirmed）の仕訳だけ。開始仕訳（opening）は月の動きではなく「前月まで」に入れる。
- 前月まで：資産・負債・純資産は月より前のすべて、収益・費用はその期の初めから。
- 開始仕訳があれば、その日付より前の通常の仕訳は数えない（開始仕訳の残高に含まれているため）。
- 科目コードは文字として並べる（PHP の strcmp と同じ順）。
- 残高の符号：資産・費用は借方が＋、負債・純資産・収益は貸方が＋。
"""

from __future__ import annotations

import calendar
import re
from datetime import date
from typing import Any

from models import MAX_LINE_YEN

_MONTH = re.compile(r"^\d{4}-(0[1-9]|1[0-2])$")
_DEBIT_PLUS = ("asset", "expense")
_PROFIT_AND_LOSS = ("revenue", "expense")


def fiscal_year_start(month_start: date, end_month: int) -> date:
    start = date(month_start.year, end_month % 12 + 1, 1)
    return date(start.year - 1, start.month, 1) if start > month_start else start


def compute(accounts: list[dict[str, Any]], entries: list[dict[str, Any]], month: str, fiscal_year_end_month: int) -> dict[str, Any]:
    if not isinstance(month, str) or not _MONTH.match(month):
        raise ValueError("月は YYYY-MM で渡してください。")
    if isinstance(fiscal_year_end_month, bool) or not isinstance(fiscal_year_end_month, int) or not 1 <= fiscal_year_end_month <= 12:
        raise ValueError("決算月は1〜12です。")

    year, mon = int(month[:4]), int(month[5:])
    month_start = date(year, mon, 1)
    month_start_text = month_start.isoformat()
    month_end_text = date(year, mon, calendar.monthrange(year, mon)[1]).isoformat()
    fiscal_start_text = fiscal_year_start(month_start, fiscal_year_end_month).isoformat()

    sums: dict[str, dict[str, Any]] = {}
    for account in accounts:
        sums[account["id"]] = {
            "account_id": account["id"], "code": account["code"], "name": account["name"], "type": account["type"],
            "carried_debit": 0, "carried_credit": 0, "debit": 0, "credit": 0,
        }

    opening_dates = [e["date"] for e in entries if e["kind"] == "opening" and e["status"] == "confirmed" and e["date"] <= month_end_text]
    opening_date = min(opening_dates) if opening_dates else None

    has_opening = False
    problem = None
    debit_total = 0
    credit_total = 0

    for entry in entries:
        entry_date = entry["date"]
        if entry["status"] != "confirmed" or entry_date > month_end_text:
            continue
        is_opening = entry["kind"] == "opening"
        if is_opening:
            has_opening = True
        elif opening_date is not None and entry_date < opening_date:
            continue
        in_month = not is_opening and entry_date >= month_start_text

        for line in entry["lines"]:
            account_id = line.get("account_id")
            side = line.get("side")
            amount = line.get("amount")
            valid_amount = isinstance(amount, int) and not isinstance(amount, bool) and 1 <= amount <= MAX_LINE_YEN
            if not isinstance(account_id, str) or account_id not in sums or side not in ("debit", "credit") or not valid_amount:
                problem = problem or "invalid_line"
                continue

            row = sums[account_id]
            if in_month:
                row[side] += amount
                if side == "debit":
                    debit_total += amount
                else:
                    credit_total += amount
                continue

            if row["type"] in _PROFIT_AND_LOSS and entry_date < fiscal_start_text:
                continue
            row["carried_" + side] += amount

    if problem is None and debit_total != credit_total:
        problem = "unbalanced"

    rows = []
    for s in sums.values():
        sign = 1 if s["type"] in _DEBIT_PLUS else -1
        carried = sign * (s["carried_debit"] - s["carried_credit"])
        closing = carried + sign * (s["debit"] - s["credit"])
        if carried == 0 and s["debit"] == 0 and s["credit"] == 0 and s["carried_debit"] == 0:
            continue
        rows.append({
            "account_id": s["account_id"], "code": s["code"], "name": s["name"], "type": s["type"],
            "carried": carried, "debit": s["debit"], "credit": s["credit"], "closing": closing,
        })
    rows.sort(key=lambda r: (r["code"], r["account_id"]))

    return {
        "month": month,
        "fiscal_year_start": fiscal_start_text,
        "has_opening": has_opening,
        "rows": rows,
        "debit_total": debit_total,
        "credit_total": credit_total,
        "balanced": problem is None,
        "problem": problem,
    }
