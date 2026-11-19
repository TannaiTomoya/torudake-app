import json
import unittest
from pathlib import Path

from sales_forecast import compute

VECTORS = Path(__file__).resolve().parents[3] / "tests" / "fixtures" / "forecast_vectors.json"


class SalesForecastVectorTest(unittest.TestCase):
    def test_vectors_match_php_rules(self):
        vectors = json.loads(VECTORS.read_text(encoding="utf-8"))
        self.assertTrue(vectors["cases"])
        for case in vectors["cases"]:
            with self.subTest(case["name"]):
                self.assertEqual(case["expected"], compute(case["target_year_month"], case["sales"], case["busy"]))

    def test_rejects_bad_month(self):
        for month in ("2026-13", "2026-1", "", None):
            with self.subTest(month=month), self.assertRaises(ValueError):
                compute(month, {}, False)


if __name__ == "__main__":
    unittest.main()
