"""CashFlowForecast と共通の架空ベクトル。"""

from __future__ import annotations

import json
import unittest
from pathlib import Path

from cashflow import project

ROOT = Path(__file__).resolve().parents[3]
VECTORS = json.loads((ROOT / "tests" / "fixtures" / "cashflow_vectors.json").read_text(encoding="utf-8"))


class CashFlowVectorTest(unittest.TestCase):
    def test_project_vectors(self) -> None:
        for case in VECTORS["project"]:
            with self.subTest(case["name"]):
                self.assertEqual(
                    project(case["opening"], case["from"], case["to"], case["items"]),
                    case["expected"],
                    case["name"],
                )

    def test_rejects_zero_amount(self) -> None:
        with self.assertRaises(ValueError):
            project(0, "2026-11-21", "2026-11-21", [{"due_date": "2026-11-21", "direction": "in", "amount_yen": 0}])

    def test_rejects_bool_amount(self) -> None:
        with self.assertRaises(ValueError):
            project(0, "2026-11-21", "2026-11-21", [{"due_date": "2026-11-21", "direction": "out", "amount_yen": True}])


if __name__ == "__main__":
    unittest.main()
