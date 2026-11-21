import json
import unittest
from pathlib import Path

from schedule import judge, summarize

VECTORS = Path(__file__).resolve().parents[3] / "tests" / "fixtures" / "schedule_vectors.json"


class ScheduleVectorTest(unittest.TestCase):
    def test_judge_vectors_match_php_rules(self):
        vectors = json.loads(VECTORS.read_text(encoding="utf-8"))
        self.assertTrue(vectors["judgements"])
        for case in vectors["judgements"]:
            with self.subTest(case["name"]):
                self.assertEqual(case["expected"], judge(case["due_date"], case["settled_on"], case["today"]))

    def test_summary_vectors_match_php_rules(self):
        vectors = json.loads(VECTORS.read_text(encoding="utf-8"))
        self.assertTrue(vectors["summaries"])
        for case in vectors["summaries"]:
            with self.subTest(case["name"]):
                self.assertEqual(case["expected"], summarize(case["items"], case["today"]))

    def test_rejects_bad_dates(self):
        for bad in ("2026-02-30", "2026/11/21", "2026-11-21 ", "2026-11-21\n", "20261121", 20261121, None, ""):
            with self.subTest(bad=bad), self.assertRaises(ValueError):
                judge(bad, None, "2026-11-21")
            with self.subTest(today=bad), self.assertRaises(ValueError):
                judge("2026-11-21", None, bad)
        for bad in ("2026-02-30", "2026/11/21", 20261121, ""):
            with self.subTest(settled=bad), self.assertRaises(ValueError):
                judge("2026-11-21", bad, "2026-11-21")

    def test_rejects_bad_items(self):
        base = {"direction": "in", "amount_yen": 1000, "due_date": "2026-11-01", "settled_on": None}
        for change in (
            {"direction": "both"},
            {"direction": None},
            {"amount_yen": 0},
            {"amount_yen": -1},
            {"amount_yen": 1.5},
            {"amount_yen": "1000"},
            {"amount_yen": True},
            {"due_date": "2026-13-01"},
        ):
            with self.subTest(change=change), self.assertRaises(ValueError):
                summarize([{**base, **change}], "2026-11-21")
        with self.assertRaises(ValueError):
            summarize(["2026-11-01"], "2026-11-21")
        with self.assertRaises(ValueError):
            summarize([], "きょう")


if __name__ == "__main__":
    unittest.main()
