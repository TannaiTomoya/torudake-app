import json
import unittest
from pathlib import Path

from trial_balance import compute

VECTORS = Path(__file__).resolve().parents[3] / "tests" / "fixtures" / "trial_balance_vectors.json"


class TrialBalanceVectorTest(unittest.TestCase):
    def test_vectors_match_php_rules(self):
        vectors = json.loads(VECTORS.read_text(encoding="utf-8"))
        self.assertTrue(vectors["cases"])
        for case in vectors["cases"]:
            with self.subTest(case["name"]):
                result = compute(vectors["accounts"], case["entries"], case["month"], case["fiscal_year_end_month"])
                self.assertEqual({**case["expected"], "month": case["month"]}, result)

    def test_rejects_bad_month_and_end_month(self):
        for month in ("2026-13", "2026-9", "", None):
            with self.subTest(month=month), self.assertRaises(ValueError):
                compute([], [], month, 7)
        for end_month in (0, 13, True, "7"):
            with self.subTest(end_month=end_month), self.assertRaises(ValueError):
                compute([], [], "2026-09", end_month)

    def test_bool_amount_is_not_counted(self):
        accounts = [{"id": "a", "code": "1", "name": "現金", "type": "asset"}]
        entries = [{"date": "2026-09-01", "kind": "normal", "status": "confirmed", "lines": [{"account_id": "a", "side": "debit", "amount": True}]}]
        result = compute(accounts, entries, "2026-09", 7)
        self.assertEqual("invalid_line", result["problem"])
        self.assertEqual([], result["rows"])


if __name__ == "__main__":
    unittest.main()
