"""CategoryRuleMatcher と共通の架空ベクトル。"""

from __future__ import annotations

import json
import unittest
from pathlib import Path

from category_rule import match

ROOT = Path(__file__).resolve().parents[3]
VECTORS = json.loads((ROOT / "tests" / "fixtures" / "category_rule_vectors.json").read_text(encoding="utf-8"))


class CategoryRuleVectorTest(unittest.TestCase):
    def test_vectors(self) -> None:
        for case in VECTORS["cases"]:
            with self.subTest(case["name"]):
                self.assertEqual(match(case["vendor"], case["rules"]), case["expected"], case["name"])
                # 同じ入力なら同じ答え
                self.assertEqual(match(case["vendor"], case["rules"]), case["expected"], case["name"])

    def test_rejects_bad_rule(self) -> None:
        with self.assertRaises(ValueError):
            match("店", [{"keyword": "店", "category_id": ""}])
        with self.assertRaises(ValueError):
            match("店", [{"keyword": "  ", "category_id": "c1"}])


if __name__ == "__main__":
    unittest.main()
