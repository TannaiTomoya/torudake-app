#!/usr/bin/env python3
"""分析用の書き出し（docs/12-analysis-export-spec.md）から、修正の傾向と L3（検算）の誤差を集計する。

使い方：python3 tools/analysis/analyze.py 書き出しのフォルダか zip [...] [--format json] [--top 10] [--strict]

- ファイルだけを読む。本番 DB・ネットワーク・AI には触れず、入力を書き換えない。2回実行すると同じ結果になる。
- 測るのは「人が下書きに手を入れたか」と「保存済みの L3 の答えが、同じ入力の整数計算とどれだけ違ったか」。
  AI の答えが会計として正しかった割合（正解率）ではない。valid はスキーマを通ったという意味で、正解ではない。
- 率は PHP の App\\Support\\FirstConfirmationMetrics と同じ丸め（四捨五入・.5 は切り上げ）。母数が 0 の率は None。
"""

from __future__ import annotations

import argparse
import json
import sys
from collections import Counter, defaultdict
from pathlib import Path
from typing import Any

from models import (
    AiRun,
    Dataset,
    FatalInputError,
    FirstConfirmation,
    check_balance,
    is_int,
    load_inputs,
    month_of,
)

REAL_DRIVER = "claude"
L3_TASK = "check_entry"
ISSUE_SAMPLE = 50


def pct(part: int, whole: int) -> int | None:
    """四捨五入（.5 は切り上げ）。母数が 0 のときは None（0% にも 100% にもしない）。整数だけで計算する。"""
    if whole == 0:
        return None
    return (part * 200 + whole) // (whole * 2)


def rate(part: int, whole: int) -> dict[str, Any]:
    return {"num": part, "den": whole, "pct": pct(part, whole)}


def average(values: list[int]) -> dict[str, Any]:
    n = len(values)
    return {"n": n, "avg": None if n == 0 else (sum(values) * 2 + n) // (n * 2)}


def ranking(counter: Counter[str], entries: dict[str, set[str]], top: int | None = None) -> list[dict[str, Any]]:
    """修正項目数の多い順。同数はキー順（毎回同じ並びにする）。"""
    rows = sorted(counter.items(), key=lambda item: (-item[1], item[0]))
    if top is not None:
        rows = rows[:top]
    return [{"key": key, "items": items, "entries": len(entries[key])} for key, items in rows]


# --- 修正の傾向 -----------------------------------------------------------------------------


def corrections_summary(data: Dataset, top: int) -> dict[str, Any]:
    by_kind: Counter[str] = Counter()
    by_field: Counter[str] = Counter()
    by_vendor: Counter[str] = Counter()
    by_month: Counter[str] = Counter()
    kind_entries: dict[str, set[str]] = defaultdict(set)
    field_entries: dict[str, set[str]] = defaultdict(set)
    vendor_entries: dict[str, set[str]] = defaultdict(set)
    month_entries: dict[str, set[str]] = defaultdict(set)
    per_entry: Counter[str] = Counter()
    vendor_unknown = 0

    for item in data.corrections:
        month = month_of(item.created_at)
        by_kind[item.kernel_kind] += 1
        kind_entries[item.kernel_kind].add(item.journal_entry_id)
        by_field[item.field] += 1
        field_entries[item.field].add(item.journal_entry_id)
        by_month[month] += 1
        month_entries[month].add(item.journal_entry_id)
        per_entry[item.journal_entry_id] += 1
        if item.vendor_key is None:
            vendor_unknown += 1
        else:
            by_vendor[item.vendor_key] += 1
            vendor_entries[item.vendor_key].add(item.journal_entry_id)

    distribution = Counter("3+" if n >= 3 else str(n) for n in per_entry.values())

    return {
        "items": len(data.corrections),
        "entries": len(per_entry),
        "items_per_entry": {key: distribution.get(key, 0) for key in ("1", "2", "3+")},
        "by_kernel_kind": ranking(by_kind, kind_entries),
        "by_field": ranking(by_field, field_entries),
        "by_vendor": ranking(by_vendor, vendor_entries, top),
        "vendor_unknown_items": vendor_unknown,
        "by_month": [{"month": m, "items": by_month[m], "entries": len(month_entries[m])} for m in sorted(by_month)],
    }


# --- 修正なしの初回確定率など（FirstConfirmationMetrics と同じ定義） ----------------------------


def first_confirmation_summary(rows: list[FirstConfirmation]) -> list[dict[str, Any]]:
    """月（会社の時刻）× 評価の区分（real・demo）。実際の AI とデモを混ぜない。"""
    groups: dict[tuple[str, str], list[FirstConfirmation]] = defaultdict(list)
    for row in rows:
        groups[(month_of(row.first_confirmed_at), row.evaluation_class)].append(row)

    result = []
    for (month, klass), items in sorted(groups.items()):
        ai = [r for r in items if r.kind == "ai"]
        manual = len(items) - len(ai)
        total = len(ai)
        adopted = [r for r in ai if (r.recommendation or {}).get("outcome") == "adopted"]
        rejected = sum(1 for r in ai if (r.recommendation or {}).get("outcome") == "rejected")
        held = sum(1 for r in ai if (r.recommendation or {}).get("outcome") == "held")
        with_candidate = len(adopted) + rejected + held

        result.append(
            {
                "month": month,
                "class": klass,
                "ai_first": total,
                "unchanged": rate(sum(1 for r in ai if r.unchanged), total),
                "amount_filled": rate(sum(1 for r in ai if "amount" in r.filled_fields), total),
                "description_changed": rate(sum(1 for r in ai if r.description_changed), total),
                "post_corrected": rate(sum(1 for r in ai if r.post_events > 0), total),
                "manual": rate(manual, total + manual),
                "changed_items": sum(len(r.changed_fields) for r in ai),
                "changed_entries": sum(1 for r in ai if r.changed_fields),
                "recommendation": {
                    "with_candidate": with_candidate,
                    "adopted": rate(len(adopted), with_candidate),
                    "rejected": rate(rejected, with_candidate),
                    "held": rate(held, with_candidate),
                    "adopted_then_corrected": sum(1 for r in adopted if r.post_events > 0),
                    "expired": sum(1 for r in ai if (r.recommendation or {}).get("expired") is True),
                    "no_candidate": total - with_candidate,
                },
            }
        )

    return result


# --- L3（検算）の誤差：同じ ai_run の input.lines と output だけを比べる ------------------------


def _l3_input(run: AiRun) -> list[dict[str, Any]] | None:
    """評価できる入力（行の並び）。ない・形が違うなら None。"""
    lines = (run.input_features or {}).get("lines")
    if not isinstance(lines, list):
        return None
    return [
        {"side": line.get("side"), "amount": line.get("amount_yen")} if isinstance(line, dict) else {"side": None, "amount": None}
        for line in lines
    ]


def _l3_output(run: AiRun) -> dict[str, Any] | None:
    out = run.output_features
    if not isinstance(out, dict) or not isinstance(out.get("balanced"), bool):
        return None
    return out


def l3_summary(data: Dataset) -> dict[str, Any]:
    runs = [r for r in data.runs if r.task == L3_TASK]

    non_real: Counter[str] = Counter()
    failed = missing_input = missing_output = 0
    candidates: list[AiRun] = []

    for run in runs:
        if run.driver != REAL_DRIVER:
            non_real[run.driver or "unknown"] += 1
        elif not run.valid or run.has_error:
            failed += 1
        elif _l3_input(run) is None:
            missing_input += 1
        elif _l3_output(run) is None:
            missing_output += 1
        else:
            candidates.append(run)

    # 同じ対象に実行が複数あるとき（再試行）は、保存された最後の1件だけを採る。
    latest: dict[str, AiRun] = {}
    adopted: list[AiRun] = []
    for run in sorted(candidates, key=lambda r: (r.created_at, r.id)):
        if run.subject_key is None:
            adopted.append(run)
        else:
            latest[run.subject_key] = run
    adopted.extend(latest.values())
    superseded = len(candidates) - len(adopted)

    retried_subjects = len(
        {
            key
            for key, n in Counter(r.subject_key for r in runs if r.driver == REAL_DRIVER and r.subject_key is not None).items()
            if n > 1
        }
    )

    groups: dict[tuple[str, str, str], list[AiRun]] = defaultdict(list)
    for run in adopted:
        groups[(run.driver or "", run.model or "", run.prompt_version or "")].append(run)

    return {
        "scope": "task=check_entry・driver=claude・valid・保存済みの入力と出力だけで評価する（AI への再質問はしない）",
        "runs": len(runs),
        "evaluated": len(adopted),
        "excluded": {
            "non_real_driver": dict(sorted(non_real.items())),
            "failed": failed,
            "missing_input": missing_input,
            "missing_output": missing_output,
            "superseded_retry": superseded,
        },
        "retried_subjects": retried_subjects,
        "adoption": "同じ対象に複数の実行があれば、created_at が最後の1件を採る",
        "groups": [
            {"driver": d, "model": m, "prompt_version": p, **_l3_group(items)}
            for (d, m, p), items in sorted(groups.items())
        ],
    }


def _l3_group(items: list[AiRun]) -> dict[str, Any]:
    matrix = {"rule_balanced_ai_balanced": 0, "rule_balanced_ai_unbalanced": 0, "rule_unbalanced_ai_balanced": 0, "rule_unbalanced_ai_unbalanced": 0}
    problems: Counter[str] = Counter()
    compared = matching = ai_missing = skipped = 0
    abs_diffs: list[int] = []

    for run in items:
        rule = check_balance(_l3_input(run) or [])
        out = _l3_output(run) or {}
        ai_balanced = out["balanced"]

        matrix[f"rule_{'balanced' if rule.balanced else 'unbalanced'}_ai_{'balanced' if ai_balanced else 'unbalanced'}"] += 1
        if rule.problem is not None:
            problems[rule.problem] += 1

        # 合計は整数で比べる。範囲外は上限で打ち切った値なので比べない。
        debit, credit = out.get("debit_total"), out.get("credit_total")
        if rule.problem == "out_of_range":
            skipped += 1
        elif not is_int(debit) or not is_int(credit):
            ai_missing += 1
        else:
            compared += 1
            gap = abs(debit - rule.debit_total) + abs(credit - rule.credit_total)
            abs_diffs.append(gap)
            matching += gap == 0

    n = len(items)
    rule_balanced = matrix["rule_balanced_ai_balanced"] + matrix["rule_balanced_ai_unbalanced"]
    rule_unbalanced = n - rule_balanced

    return {
        "evaluated": n,
        "rule_balanced": rate(rule_balanced, n),
        "rule_problems": dict(sorted(problems.items())),
        "matrix": matrix,
        "balanced_agreement": rate(matrix["rule_balanced_ai_balanced"] + matrix["rule_unbalanced_ai_unbalanced"], n),
        "ai_false_match": rate(matrix["rule_unbalanced_ai_balanced"], rule_unbalanced),
        "ai_false_mismatch": rate(matrix["rule_balanced_ai_unbalanced"], rule_balanced),
        "totals": {
            "compared": compared,
            "same": rate(matching, compared),
            "differing": compared - matching,
            "max_abs_gap_yen": max(abs_diffs) if abs_diffs else None,
            "ai_totals_missing": ai_missing,
            "skipped_out_of_range": skipped,
        },
        "input_tokens": average([r.input_tokens for r in items if r.input_tokens is not None]),
        "output_tokens": average([r.output_tokens for r in items if r.output_tokens is not None]),
        "latency_ms": average([r.latency_ms for r in items if r.latency_ms is not None]),
    }


# --- 全体と出力 -----------------------------------------------------------------------------


def analyze(data: Dataset, top: int = 10) -> dict[str, Any]:
    kinds = Counter(issue.kind for issue in data.issues)

    return {
        "company_key": data.company_key,
        "inputs": data.inputs,
        "issues": {
            "total": len(data.issues),
            "by_kind": dict(sorted(kinds.items())),
            "sample": [
                {"file": i.file, "line": i.line, "kind": i.kind, "field": i.field} for i in data.issues[:ISSUE_SAMPLE]
            ],
        },
        "corrections": corrections_summary(data, top),
        "first_confirmations": first_confirmation_summary(data.first_confirmations),
        "l3": l3_summary(data),
    }


def _fmt(r: dict[str, Any]) -> str:
    if r["pct"] is None:
        return "—（母数0）"
    return f"{r['pct']}%（{r['num']}/{r['den']}）"


def render_text(report: dict[str, Any]) -> str:
    out: list[str] = []
    add = out.append

    add("# 分析レポート（仮名化した機密データから作った集計。公開しない）")
    for item in report["inputs"]:
        add(f"- 入力：月={item['month']} schema_version={item['schema_version']} metric_version={item['metric_version']}")
    issues = report["issues"]
    add(f"- 入力の問題：{issues['total']}件 {issues['by_kind'] or ''}".rstrip())
    for sample in issues["sample"]:
        add(f"  - {sample['file']}:{sample['line']} {sample['kind']} {sample['field'] or ''}".rstrip())

    c = report["corrections"]
    add("\n## 修正の傾向（修正項目数＝直した箇所の数、仕訳数＝直した仕訳の数。1仕訳の複数修正は別々に数える）")
    add(f"- 修正項目 {c['items']}件／仕訳 {c['entries']}件（1箇所 {c['items_per_entry']['1']}・2箇所 {c['items_per_entry']['2']}・3箇所以上 {c['items_per_entry']['3+']}）")
    for title, rows in (("修正の型", c["by_kernel_kind"]), ("直した欄", c["by_field"]), ("店（仮名）", c["by_vendor"])):
        add(f"- {title}（多い順）")
        for row in rows:
            add(f"  - {row['key']}：項目 {row['items']}・仕訳 {row['entries']}")
    add(f"- 店が不明な修正項目：{c['vendor_unknown_items']}件")
    add("- 月別（会社の時刻）")
    for row in c["by_month"]:
        add(f"  - {row['month']}：項目 {row['items']}・仕訳 {row['entries']}")

    add("\n## 初回確定（実際のAIとデモは分ける。正解率ではなく、手を入れたかという行動）")
    for row in report["first_confirmations"]:
        rec = row["recommendation"]
        add(f"- {row['month']} [{row['class']}] AI由来 {row['ai_first']}件")
        add(f"  - 手を入れず確定 {_fmt(row['unchanged'])}／金額を人が補完 {_fmt(row['amount_filled'])}／摘要を直した {_fmt(row['description_changed'])}")
        add(f"  - 確定後の事後修正 {_fmt(row['post_corrected'])}／人が最初から入力 {_fmt(row['manual'])}")
        add(f"  - 直した項目 {row['changed_items']}・直した仕訳 {row['changed_entries']}")
        add(f"  - 履歴の候補 {rec['with_candidate']}件：採用 {_fmt(rec['adopted'])}・別を選んだ {_fmt(rec['rejected'])}・保留 {_fmt(rec['held'])}（候補なし {rec['no_candidate']}・失効 {rec['expired']}）")

    l3 = report["l3"]
    add("\n## L3（検算）：保存済みの入力を整数で再計算し、AIの答えと比べる")
    add(f"- 対象：{l3['scope']}")
    ex = l3["excluded"]
    add(f"- check_entry の実行 {l3['runs']}件 → 評価 {l3['evaluated']}件")
    add(f"- 除外：実AI以外 {sum(ex['non_real_driver'].values())}{dict(ex['non_real_driver']) or ''}・失敗 {ex['failed']}・入力欠損 {ex['missing_input']}・出力欠損 {ex['missing_output']}・再試行で置き換え {ex['superseded_retry']}（再試行のあった対象 {l3['retried_subjects']}）")
    add(f"- 採用：{l3['adoption']}")
    for g in l3["groups"]:
        m = g["matrix"]
        t = g["totals"]
        add(f"- driver={g['driver']} model={g['model']} prompt={g['prompt_version']}：評価 {g['evaluated']}件")
        add(f"  - 規則で貸借一致 {_fmt(g['rule_balanced'])}（問題の内訳 {g['rule_problems'] or 'なし'}）")
        add(f"  - 一致/不一致の判定が規則と同じ {_fmt(g['balanced_agreement'])}")
        add(f"  - 規則：一致×AI：一致 {m['rule_balanced_ai_balanced']}／規則：一致×AI：不一致 {m['rule_balanced_ai_unbalanced']}／規則：不一致×AI：一致 {m['rule_unbalanced_ai_balanced']}／規則：不一致×AI：不一致 {m['rule_unbalanced_ai_unbalanced']}")
        add(f"  - AIの誤った一致判定 {_fmt(g['ai_false_match'])}・誤った不一致判定 {_fmt(g['ai_false_mismatch'])}")
        add(f"  - 合計が規則と同じ {_fmt(t['same'])}（食い違い {t['differing']}件・最大のずれ {t['max_abs_gap_yen']}円・AIの合計なし {t['ai_totals_missing']}・範囲外で比較せず {t['skipped_out_of_range']}）")
        add(f"  - 平均：入力 {g['input_tokens']['avg']}トークン・出力 {g['output_tokens']['avg']}トークン・{g['latency_ms']['avg']}ms")
    add("\n※ 貸借の算術一致率は、仕訳全体の正解率ではない（科目・税率・取引内容の正しさは保証しない）。")

    return "\n".join(out) + "\n"


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="分析用の書き出しを集計する（ファイルだけを読む）")
    parser.add_argument("inputs", nargs="+", type=Path, help="書き出しのフォルダか zip（複数月は並べる。会社は同じものだけ）")
    parser.add_argument("--format", choices=("text", "json"), default="text")
    parser.add_argument("--top", type=int, default=10, help="店ごとの修正ランキングの件数")
    parser.add_argument("--strict", action="store_true", help="入力に問題が1件でもあれば終了コード1にする")
    args = parser.parse_args(argv)

    try:
        report = analyze(load_inputs(args.inputs), max(args.top, 1))
    except FatalInputError as error:
        print(f"エラー：{error}", file=sys.stderr)
        return 2

    if args.format == "json":
        sys.stdout.write(json.dumps(report, ensure_ascii=False, indent=2, sort_keys=True) + "\n")
    else:
        sys.stdout.write(render_text(report))

    return 1 if args.strict and report["issues"]["total"] else 0


if __name__ == "__main__":
    sys.exit(main())
