import io
import json
import socket
import tempfile
import unittest
from contextlib import redirect_stderr, redirect_stdout
from pathlib import Path

import analyze
from analyze import pct
from models import load_inputs
from tests import fixture_data as fx

from tests.test_models import line, write_export


def report(top: int = 10) -> dict:
    return analyze.analyze(load_inputs([fx.FIXTURE_DIR]), top)


class RoundingTest(unittest.TestCase):
    def test_php_と同じ丸め_および母数0はNone(self):
        self.assertEqual([pct(1, 8), pct(5, 8), pct(1, 3), pct(2, 3), pct(0, 5), pct(5, 5)], [13, 63, 33, 67, 0, 100])
        self.assertIsNone(pct(0, 0))


class CorrectionsTest(unittest.TestCase):
    """手計算：8項目・5仕訳（e1 が3箇所、e4 が2箇所、残り1箇所）。10月分が1項目（日本時間 10/31 23:59）。"""

    def test_項目数と仕訳数を分けて数える(self):
        c = report()["corrections"]
        self.assertEqual((c["items"], c["entries"]), (8, 5))
        self.assertEqual(c["items_per_entry"], {"1": 3, "2": 1, "3+": 1})

    def test_修正の型と欄は多い順_同数はキー順(self):
        c = report()["corrections"]
        self.assertEqual(
            [(r["key"], r["items"], r["entries"]) for r in c["by_kernel_kind"]],
            [("vendor_to_category", 3, 3), ("amount_change", 1, 1), ("date_change", 1, 1), ("other", 1, 1), ("vendor_change", 1, 1), ("vendor_to_payment", 1, 1)],
        )
        self.assertEqual(
            [(r["key"], r["items"], r["entries"]) for r in c["by_field"]],
            [("category", 3, 3), ("amount", 1, 1), ("credit_account", 1, 1), ("date", 1, 1), ("other", 1, 1), ("vendor", 1, 1)],
        )

    def test_店ごとの順位は上位だけ_店不明は別に数える(self):
        c = report(top=2)["corrections"]
        self.assertEqual([(r["key"], r["items"], r["entries"]) for r in c["by_vendor"]], [(fx.key("v", 1), 4, 2), (fx.key("v", 2), 2, 2)])
        self.assertEqual(c["vendor_unknown_items"], 1)

    def test_月別は会社の時刻で分ける(self):
        c = report()["corrections"]
        self.assertEqual(c["by_month"], [{"month": "2026-10", "items": 1, "entries": 1}, {"month": "2026-11", "items": 7, "entries": 4}])

    def test_0件でも落ちない(self):
        with tempfile.TemporaryDirectory() as tmp:
            write_export(Path(tmp) / "e", {})
            result = analyze.analyze(load_inputs([Path(tmp) / "e"]))
        self.assertEqual(result["corrections"]["items"], 0)
        self.assertEqual(result["first_confirmations"], [])
        self.assertEqual((result["l3"]["runs"], result["l3"]["groups"]), (0, []))


class FirstConfirmationTest(unittest.TestCase):
    """手計算：11月 real は AI 由来8件。手を入れず5件（62.5%→63%）、金額補完・摘要・事後修正は各1件（12.5%→13%）。"""

    def find(self, month: str, klass: str) -> dict:
        return next(r for r in report()["first_confirmations"] if (r["month"], r["class"]) == (month, klass))

    def test_率の母数_分子_丸め(self):
        r = self.find("2026-11", "real")
        self.assertEqual(r["ai_first"], 8)
        self.assertEqual(r["unchanged"], {"num": 5, "den": 8, "pct": 63})
        self.assertEqual(r["amount_filled"], {"num": 1, "den": 8, "pct": 13})
        self.assertEqual(r["description_changed"], {"num": 1, "den": 8, "pct": 13})
        self.assertEqual(r["post_corrected"], {"num": 1, "den": 8, "pct": 13})
        self.assertEqual(r["manual"], {"num": 1, "den": 9, "pct": 11})
        self.assertEqual((r["changed_items"], r["changed_entries"]), (3, 2))

    def test_履歴の候補(self):
        rec = self.find("2026-11", "real")["recommendation"]
        self.assertEqual(rec["with_candidate"], 5)
        self.assertEqual([rec[k]["pct"] for k in ("adopted", "rejected", "held")], [40, 20, 40])
        self.assertEqual((rec["adopted_then_corrected"], rec["expired"], rec["no_candidate"]), (1, 1, 3))

    def test_デモと実際は混ぜず_月は会社の時刻で分ける(self):
        self.assertEqual(self.find("2026-11", "demo")["unchanged"], {"num": 1, "den": 1, "pct": 100})
        self.assertEqual(self.find("2026-10", "real")["unchanged"], {"num": 0, "den": 1, "pct": 0})
        keys = [(r["month"], r["class"]) for r in report()["first_confirmations"]]
        self.assertEqual(keys, [("2026-10", "real"), ("2026-11", "demo"), ("2026-11", "real")])

    def test_AI由来が0件の月は率がNone(self):
        with tempfile.TemporaryDirectory() as tmp:
            manual = fx.first(1, 1, "2026-11-10T01:00:00Z", kind="manual", unchanged=False)
            write_export(Path(tmp) / "e", {"first_confirmations.jsonl": line(manual)})
            row = analyze.analyze(load_inputs([Path(tmp) / "e"]))["first_confirmations"][0]
        self.assertEqual(row["unchanged"], {"num": 0, "den": 0, "pct": None})
        self.assertEqual(row["manual"], {"num": 1, "den": 1, "pct": 100})


class L3Test(unittest.TestCase):
    def l3(self) -> dict:
        return report()["l3"]

    def group(self, prompt: str) -> dict:
        return next(g for g in self.l3()["groups"] if g["prompt_version"] == prompt)

    def test_対象と除外の数(self):
        l3 = self.l3()
        self.assertEqual((l3["runs"], l3["evaluated"]), (15, 9))
        self.assertEqual(
            l3["excluded"],
            {"non_real_driver": {"fake": 2}, "failed": 1, "missing_input": 1, "missing_output": 1, "superseded_retry": 1},
        )
        self.assertEqual(l3["retried_subjects"], 1)

    def test_貸借一致と不一致_AIの判定との組み合わせ(self):
        g = self.group("check_entry.v1")
        self.assertEqual(g["evaluated"], 8)
        self.assertEqual(
            g["matrix"],
            {"rule_balanced_ai_balanced": 2, "rule_balanced_ai_unbalanced": 1, "rule_unbalanced_ai_balanced": 1, "rule_unbalanced_ai_unbalanced": 4},
        )
        self.assertEqual(g["rule_problems"], {"empty": 1, "mismatch": 2, "non_positive": 1, "out_of_range": 1})
        self.assertEqual(g["rule_balanced"], {"num": 3, "den": 8, "pct": 38})
        self.assertEqual(g["balanced_agreement"], {"num": 6, "den": 8, "pct": 75})
        self.assertEqual(g["ai_false_match"], {"num": 1, "den": 5, "pct": 20})
        self.assertEqual(g["ai_false_mismatch"], {"num": 1, "den": 3, "pct": 33})

    def test_合計の差額は整数で比べ_範囲外は比べない(self):
        t = self.group("check_entry.v1")["totals"]
        self.assertEqual(t["compared"], 7)
        self.assertEqual(t["same"], {"num": 5, "den": 7, "pct": 71})
        self.assertEqual((t["differing"], t["max_abs_gap_yen"], t["ai_totals_missing"], t["skipped_out_of_range"]), (2, 1, 0, 1))

    def test_再試行は最後の有効な1件だけを採る(self):
        # e8 は 100/90（古い有効）→ 失敗 → 100/100（最後の有効）。採るのは最後で、規則では一致。
        g = self.group("check_entry.v1")
        self.assertEqual(g["matrix"]["rule_balanced_ai_balanced"], 2)  # e1 と e8
        self.assertEqual(g["latency_ms"], {"n": 8, "avg": 450})  # 古い実行の 999ms は入らない

    def test_平均はnullを除き四捨五入(self):
        g = self.group("check_entry.v1")
        self.assertEqual(g["input_tokens"], {"n": 8, "avg": 113})  # 900/8=112.5
        self.assertEqual(g["output_tokens"], {"n": 7, "avg": 50})

    def test_版5の答えなし_null_は出力欠損に数え_行列に入れない(self):
        answered = fx.run(1, 1, "2026-11-05T01:00:01Z", lines=fx.pair(10, 10), out=fx.l3_out(True, 10, 10))
        silent = fx.run(2, 2, "2026-11-05T01:00:02Z", lines=fx.pair(10, 9), out={**fx.l3_out(False, 10, 9), "balanced": None})
        with tempfile.TemporaryDirectory() as tmp:
            write_export(Path(tmp) / "e", {"ai_runs.jsonl": line(answered) + line(silent)}, schema_version=5)
            l3 = analyze.analyze(load_inputs([Path(tmp) / "e"]))["l3"]

        self.assertEqual((l3["evaluated"], l3["excluded"]["missing_output"]), (1, 1))
        self.assertEqual(sum(l3["groups"][0]["matrix"].values()), 1)
        self.assertEqual(l3["groups"][0]["matrix"]["rule_balanced_ai_balanced"], 1)

    def test_prompt_versionごとに分ける(self):
        self.assertEqual([g["prompt_version"] for g in self.l3()["groups"]], ["check_entry.v1", "check_entry.v2"])
        self.assertEqual(self.group("check_entry.v2")["evaluated"], 1)

    def test_現在の仕訳ではなく_保存済みの入力と出力だけを使う(self):
        # journal_entries.jsonl は読まない（読み込み対象に入っていない）
        from models import REQUIRED_FILES

        self.assertNotIn("journal_entries.jsonl", REQUIRED_FILES)


class CliTest(unittest.TestCase):
    def run_cli(self, *args: str) -> tuple[int, str, str]:
        out, err = io.StringIO(), io.StringIO()
        with redirect_stdout(out), redirect_stderr(err):
            code = analyze.main([str(a) for a in args])
        return code, out.getvalue(), err.getvalue()

    def test_2回実行して同じ結果_ネットワークが止まっていても動く(self):
        real_socket = socket.socket

        def blocked(*_a, **_k):
            raise OSError("network is disabled in this test")

        socket.socket = blocked  # type: ignore[assignment]
        try:
            first = self.run_cli(fx.FIXTURE_DIR)
            second = self.run_cli(fx.FIXTURE_DIR)
            json_first = self.run_cli(fx.FIXTURE_DIR, "--format", "json")
            json_second = self.run_cli(fx.FIXTURE_DIR, "--format", "json")
        finally:
            socket.socket = real_socket  # type: ignore[assignment]

        self.assertEqual(first[0], 0)
        self.assertEqual(first, second)
        self.assertEqual(json_first, json_second)
        self.assertEqual(json.loads(json_first[1])["corrections"]["items"], 8)
        self.assertIn("貸借の算術一致率は、仕訳全体の正解率ではない", first[1])

    def test_入力を書き換えない(self):
        before = {p.name: p.read_bytes() for p in fx.FIXTURE_DIR.iterdir()}
        self.run_cli(fx.FIXTURE_DIR)
        self.assertEqual(before, {p.name: p.read_bytes() for p in fx.FIXTURE_DIR.iterdir()})

    def test_入力に問題があるとstrictで終了コード1_致命的なら2(self):
        with tempfile.TemporaryDirectory() as tmp:
            write_export(Path(tmp) / "e", {"ai_runs.jsonl": "{broken\n"})
            self.assertEqual(self.run_cli(Path(tmp) / "e")[0], 0)
            code, out, _ = self.run_cli(Path(tmp) / "e", "--strict")
            self.assertEqual(code, 1)
            self.assertNotIn("broken", out)

            code, _, err = self.run_cli(Path(tmp) / "missing")
            self.assertEqual(code, 2)
            self.assertIn("エラー", err)

    def test_fixtureは生成スクリプトと一致している(self):
        with tempfile.TemporaryDirectory() as tmp:
            fx.build(Path(tmp))
            for path in fx.FIXTURE_DIR.iterdir():
                self.assertEqual(path.read_bytes(), (Path(tmp) / path.name).read_bytes(), path.name)


if __name__ == "__main__":
    unittest.main()
