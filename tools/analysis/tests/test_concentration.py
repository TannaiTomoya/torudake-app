import json
import unittest
from pathlib import Path

from concentration import compute, share_pct

VECTORS = Path(__file__).resolve().parents[3] / "tests" / "fixtures" / "concentration_vectors.json"


class ConcentrationVectorTest(unittest.TestCase):
    def test_vectors_match_php_rules(self):
        vectors = json.loads(VECTORS.read_text(encoding="utf-8"))
        self.assertTrue(vectors["cases"])
        for case in vectors["cases"]:
            with self.subTest(case["name"]):
                self.assertEqual(case["expected"], compute(case["amounts"], case["alert_pct"]))

    def test_share_vectors_match_php_rules(self):
        vectors = json.loads(VECTORS.read_text(encoding="utf-8"))
        self.assertTrue(vectors["shares"])
        for case in vectors["shares"]:
            with self.subTest(case["name"]):
                self.assertEqual(case["expected"], share_pct(case["amount"], case["total"]))

    def test_rejects_bad_alert_pct(self):
        for pct in (0, 101, -1, 70.0, "70", None, True):
            with self.subTest(pct=pct), self.assertRaises(ValueError):
                compute([100], pct)

    def test_rejects_non_integer_amount(self):
        for amount in (1.5, "100", None, True):
            with self.subTest(amount=amount), self.assertRaises(ValueError):
                compute([100, amount], 70)


if __name__ == "__main__":
    unittest.main()
