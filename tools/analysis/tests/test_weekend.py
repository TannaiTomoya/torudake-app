"""WeekendCalendar と共通の架空ベクトル。"""

from __future__ import annotations

import json
import unittest
from pathlib import Path

from weekend import is_weekend, month_ends_on_weekend_in_range

ROOT = Path(__file__).resolve().parents[3]
VECTORS = json.loads((ROOT / "tests" / "fixtures" / "cashflow_vectors.json").read_text(encoding="utf-8"))


class WeekendVectorTest(unittest.TestCase):
    def test_weekend_vectors(self) -> None:
        for case in VECTORS["weekend"]:
            with self.subTest(case["name"]):
                self.assertEqual(is_weekend(case["date"]), case["expected"], case["name"])

    def test_month_end_weekend_vectors(self) -> None:
        for case in VECTORS["month_end_weekend"]:
            with self.subTest(case["name"]):
                self.assertEqual(
                    month_ends_on_weekend_in_range(case["from"], case["to"]),
                    case["expected"],
                    case["name"],
                )

    def test_bad_date(self) -> None:
        with self.assertRaises(ValueError):
            is_weekend("2026-02-30")


if __name__ == "__main__":
    unittest.main()
