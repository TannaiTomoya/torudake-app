"""架空の書き出しを作る（実在の会社・帳票・数字は使わない）。

`python3 -m tests.fixture_data` で tests/fixtures/export_2026_11/ を作り直す。
数字は test_analyze.py に手計算の期待値として書いてある。
"""

from __future__ import annotations

import hashlib
import json
from pathlib import Path
from typing import Any

FIXTURE_DIR = Path(__file__).parent / "fixtures" / "export_2026_11"
COMPANY = "k_0000000000000001"
MAX_LINE = 100_000_000_000


def key(prefix: str, n: int) -> str:
    return f"{prefix}_{n:016x}"


def run(n: int, subject: int | None, created: str, *, task="check_entry", driver="claude", prompt="check_entry.v1",
        valid=True, error=False, lines=None, out=None, no_input=False, no_output=False,
        input_tokens=100, output_tokens=50, latency=100) -> dict[str, Any]:
    input_features = None if no_input else {"entry_date": "2026-11-05", "lines": lines, "recent_entries_count": 0}
    output_features = None if no_output else out
    return {
        "id": key("r", n),
        "task": task,
        "subject_kind": "JournalEntry",
        "subject_key": None if subject is None else key("e", subject),
        "model": "claude-test",
        "prompt_version": prompt,
        "driver": driver,
        "valid": valid,
        "has_error": error,
        "input_tokens": input_tokens,
        "output_tokens": output_tokens,
        "latency_ms": latency,
        "created_at": created,
        "input_features": input_features,
        "output_features": output_features,
    }


def pair(debit: int, credit: int) -> list[dict[str, Any]]:
    return [{"side": "debit", "amount_yen": debit}, {"side": "credit", "amount_yen": credit}]


def l3_out(balanced: bool, debit: int | None, credit: int | None) -> dict[str, Any]:
    return {"debit_total": debit, "credit_total": credit, "balanced": balanced,
            "duplicate_suspected": False, "private_suspected": False, "concerns_count": 0}


def runs() -> list[dict[str, Any]]:
    t = "2026-11-05T01:00:{:02d}Z".format
    return [
        # 規則も AI も一致
        run(1, 1, t(1), lines=pair(1000, 1000), out=l3_out(True, 1000, 1000), latency=100),
        # 規則は不一致（1円差）なのに AI は「一致」（誤った一致判定）
        run(2, 2, t(2), lines=pair(1000, 999), out=l3_out(True, 1000, 1000), latency=200),
        # 規則は一致なのに AI は「不一致」（誤った不一致判定）。合計も1円ずれている
        run(3, 3, t(3), lines=pair(500, 500), out=l3_out(False, 500, 501), input_tokens=200, latency=300),
        # 規則も AI も不一致（合計も同じ）
        run(4, 4, t(4), lines=pair(300, 200), out=l3_out(False, 300, 200), latency=400),
        # 空行
        run(5, 5, t(5), lines=[], out=l3_out(False, 0, 0), output_tokens=None, latency=500),
        # 不正な金額（null）
        run(6, 6, t(6), lines=[{"side": "debit", "amount_yen": None}, {"side": "credit", "amount_yen": 100}],
            out=l3_out(False, 0, 100), latency=600),
        # 範囲外（AI の合計は null）
        run(7, 7, t(7), lines=pair(MAX_LINE + 1, MAX_LINE + 1), out=l3_out(False, None, None), latency=700),
        # 再試行：古い有効な実行 → 失敗 → 最後の有効な実行（最後の1件だけ採る）
        run(8, 8, t(10), lines=pair(100, 90), out=l3_out(True, 100, 90), latency=999),
        run(9, 8, t(11), valid=False, error=True, lines=None, out=None, no_input=True, no_output=True),
        run(10, 8, t(12), lines=pair(100, 100), out=l3_out(True, 100, 100), latency=800),
        # 実AI以外
        run(11, 9, t(13), driver="fake", lines=pair(10, 10), out=l3_out(True, 10, 10)),
        run(12, 10, t(14), driver="fake", lines=pair(10, 10), out=l3_out(True, 10, 10)),
        # 入力・出力の欠損（valid だが評価できない）
        run(13, 11, t(15), no_input=True, out=l3_out(True, 10, 10)),
        run(14, 12, t(16), lines=pair(10, 10), no_output=True),
        # 別の prompt_version は別の組に分ける
        run(15, 13, t(17), prompt="check_entry.v2", lines=pair(10, 10), out=l3_out(True, 10, 10),
            input_tokens=10, output_tokens=10, latency=10),
        # check_entry 以外は L3 に入れない
        run(16, 14, t(18), task="read_receipt", lines=None, out=None, no_input=True, no_output=True),
    ]


def correction(n: int, entry: int, field: str, kind: str, vendor: int | None, created: str) -> dict[str, Any]:
    return {
        "id": key("x", n), "ai_run_id": key("r", 1), "journal_entry_id": key("e", entry), "field": field,
        "before": None, "after": None, "reason": "wrong_category", "kernel_kind": kind,
        "vendor_key": None if vendor is None else key("v", vendor), "corrected_by": key("u", 1), "created_at": created,
    }


def corrections() -> list[dict[str, Any]]:
    return [
        correction(1, 1, "category", "vendor_to_category", 1, "2026-11-05T01:00:00Z"),
        correction(2, 1, "credit_account", "vendor_to_payment", 1, "2026-11-05T01:00:00Z"),
        correction(3, 2, "category", "vendor_to_category", 1, "2026-11-06T01:00:00Z"),
        correction(4, 3, "amount", "amount_change", 2, "2026-11-07T01:00:00Z"),
        correction(5, 4, "date", "date_change", 3, "2026-11-08T01:00:00Z"),
        correction(6, 4, "other", "other", None, "2026-11-09T01:00:00Z"),
        # 日本時間では 10/31 23:59（前の月）
        correction(7, 5, "category", "vendor_to_category", 2, "2026-10-31T14:59:59Z"),
        correction(8, 1, "vendor", "vendor_change", 1, "2026-11-05T01:00:00Z"),
    ]


def first(n: int, entry: int, at: str, *, kind="ai", klass="real", complete=True, unchanged=True, changed=(), filled=(),
          description=False, recommendation=None, post=0) -> dict[str, Any]:
    return {
        "id": key("f", n), "journal_entry_id": key("e", entry), "ai_run_id": key("r", 1), "kind": kind,
        "evaluation_class": klass, "driver": "claude", "prompt_version": "journalize.v1",
        "required_complete": complete, "unchanged": unchanged, "changed_fields": list(changed),
        "filled_fields": list(filled), "description_changed": description, "draft_origin": None,
        "reading_methods": None, "duplicate": None, "recommendation": recommendation, "first_confirmed_by": key("u", 1),
        "first_confirmed_at": at,
        "post_events": [{"kind": "returned", "changed_fields": [], "occurred_at": at} for _ in range(post)],
    }


def firsts() -> list[dict[str, Any]]:
    nov = "2026-11-10T01:00:00Z"

    def rec(outcome: str, expired: bool = False) -> dict[str, Any]:
        return {"status": "confident", "outcome": outcome, "expired": expired}

    return [
        first(1, 1, nov, recommendation=rec("adopted")),
        first(2, 2, nov, recommendation=rec("held")),
        first(3, 3, nov, unchanged=False, changed=["category"], recommendation=rec("rejected")),
        first(4, 4, nov, unchanged=False, filled=["amount"], description=True),
        first(5, 5, nov, recommendation=rec("adopted"), post=1),
        first(6, 6, nov, recommendation=rec("no_candidate")),
        first(7, 7, nov),
        first(8, 8, nov, unchanged=False, changed=["date", "vendor"], recommendation=rec("held", True)),
        first(9, 9, nov, kind="manual", unchanged=False),
        first(10, 10, nov, klass="demo"),
        # 日本時間では 10/31 23:59（前の月）
        first(11, 11, "2026-10-31T14:59:59Z", unchanged=False, changed=["amount"]),
    ]


def build(directory: Path) -> None:
    directory.mkdir(parents=True, exist_ok=True)
    files = {"ai_runs.jsonl": runs(), "ai_corrections.jsonl": corrections(), "first_confirmations.jsonl": firsts()}
    meta: dict[str, Any] = {}
    for name, rows in files.items():
        body = "".join(json.dumps(r, ensure_ascii=False, sort_keys=True) + "\n" for r in rows).encode()
        (directory / name).write_bytes(body)
        meta[name] = {"rows": len(rows), "sha256": hashlib.sha256(body).hexdigest()}
    manifest = {
        "schema_version": 4, "metric_version": "analysis-metrics.v2", "sensitivity": "pseudonymized-confidential",
        "company_key": COMPANY, "month": "2026-11", "files": meta,
    }
    (directory / "manifest.json").write_text(json.dumps(manifest, ensure_ascii=False, indent=2, sort_keys=True) + "\n")


if __name__ == "__main__":
    build(FIXTURE_DIR)
