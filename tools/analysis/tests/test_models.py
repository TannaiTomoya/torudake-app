import hashlib
import json
import tempfile
import unittest
import zipfile
from pathlib import Path

from models import FatalInputError, check_balance, load_inputs
from tests import fixture_data as fx

VECTORS = Path(__file__).resolve().parents[3] / "tests" / "fixtures" / "balance_vectors.json"


def write_export(directory: Path, files: dict[str, str], *, schema_version=4, company=fx.COMPANY, declare=True) -> Path:
    directory.mkdir(parents=True, exist_ok=True)
    meta = {}
    for name in ("ai_runs.jsonl", "ai_corrections.jsonl", "first_confirmations.jsonl"):
        body = files.get(name, "").encode()
        (directory / name).write_bytes(body)
        meta[name] = {"rows": sum(1 for line in body.split(b"\n") if line.strip()), "sha256": hashlib.sha256(body).hexdigest()}
    manifest = {"schema_version": schema_version, "company_key": company, "month": "2026-11", "files": meta if declare else {}}
    (directory / "manifest.json").write_text(json.dumps(manifest))
    return directory


def line(obj) -> str:
    return json.dumps(obj) + "\n"


class BalanceVectorTest(unittest.TestCase):
    def test_php_と共通の架空ベクトルで同じ答えになる(self):
        cases = json.loads(VECTORS.read_text())["cases"]
        self.assertGreaterEqual(len(cases), 20)
        for case in cases:
            with self.subTest(case["name"]):
                result = check_balance(case["rows"])
                self.assertEqual(result.balanced, case["balanced"])
                self.assertEqual(result.problem, case["problem"])
                self.assertEqual(result.debit_total, case["debit_total"])
                self.assertEqual(result.credit_total, case["credit_total"])

    def test_真偽値は整数として扱わない(self):
        self.assertEqual(check_balance([{"side": "debit", "amount": True}, {"side": "credit", "amount": 1}]).problem, "non_positive")


class LoadTest(unittest.TestCase):
    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.tmp = Path(self._tmp.name)

    def tearDown(self):
        self._tmp.cleanup()

    def test_架空fixtureは問題なく読める(self):
        data = load_inputs([fx.FIXTURE_DIR])
        self.assertEqual(data.issues, [])
        self.assertEqual((len(data.runs), len(data.corrections), len(data.first_confirmations)), (16, 8, 11))

    def test_zipも読める(self):
        archive = self.tmp / "export.zip"
        with zipfile.ZipFile(archive, "w") as z:
            for path in fx.FIXTURE_DIR.iterdir():
                z.write(path, path.name)
        self.assertEqual(load_inputs([archive]).issues, [])

    def test_壊れた行は行番号と種別だけを残し_値は出さない(self):
        secret = "SECRET-VALUE-9999"
        good = fx.runs()[0]
        bad_type = {**good, "id": fx.key("r", 2), "valid": secret}
        bad_json = '{"id": "' + secret + '"'
        body = line(good) + bad_json + "\n" + line([secret]) + line(bad_type) + line({**good, "id": fx.key("r", 3), "created_at": secret})
        write_export(self.tmp / "e", {"ai_runs.jsonl": body})

        data = load_inputs([self.tmp / "e"])

        self.assertEqual(len(data.runs), 1)
        found = [(i.file, i.line, i.kind, i.field) for i in data.issues]
        self.assertEqual(
            found,
            [
                ("ai_runs.jsonl", 2, "invalid_json", None),
                ("ai_runs.jsonl", 3, "not_object", None),
                ("ai_runs.jsonl", 4, "wrong_type", "valid"),
                ("ai_runs.jsonl", 5, "bad_time", "created_at"),
            ],
        )
        self.assertNotIn(secret, repr(data.issues))

    def test_必須項目の欠け_重複ID_空行(self):
        good = fx.runs()[0]
        no_valid = {k: v for k, v in good.items() if k != "valid"}
        body = line(good) + "\n" + line({**no_valid, "id": fx.key("r", 2)}) + line(good)
        write_export(self.tmp / "e", {"ai_runs.jsonl": body})

        data = load_inputs([self.tmp / "e"])

        self.assertEqual([(i.line, i.kind, i.field) for i in data.issues], [(3, "missing_field", "valid"), (4, "duplicate_id", "id")])
        self.assertEqual(len(data.runs), 1)

    def test_初回確定は1仕訳に1行_同じ仕訳の2行目は重複(self):
        first = fx.firsts()[0]
        again = {**first, "id": fx.key("f", 99)}
        write_export(self.tmp / "e", {"first_confirmations.jsonl": line(first) + line(again)})

        data = load_inputs([self.tmp / "e"])

        self.assertEqual([(i.line, i.kind, i.field) for i in data.issues], [(2, "duplicate_id", "journal_entry_id")])

    def test_ハッシュと行数が合わなければ知らせる(self):
        out = write_export(self.tmp / "e", {"ai_runs.jsonl": line(fx.runs()[0])})
        (out / "ai_runs.jsonl").write_text(line(fx.runs()[0]) + line(fx.runs()[1]))

        kinds = {(i.file, i.kind) for i in load_inputs([out]).issues}

        self.assertEqual(kinds, {("ai_runs.jsonl", "hash_mismatch"), ("ai_runs.jsonl", "row_count_mismatch")})

    def test_空のファイルと0件はエラーにしない(self):
        write_export(self.tmp / "e", {})

        data = load_inputs([self.tmp / "e"])

        self.assertEqual((data.issues, data.runs, data.corrections, data.first_confirmations), ([], [], [], []))

    def test_未対応の版_別の会社_ファイル欠けは止める(self):
        write_export(self.tmp / "old", {}, schema_version=2)
        with self.assertRaises(FatalInputError):
            load_inputs([self.tmp / "old"])

        write_export(self.tmp / "a", {})
        write_export(self.tmp / "b", {}, company="k_0000000000000002")
        with self.assertRaises(FatalInputError):
            load_inputs([self.tmp / "a", self.tmp / "b"])

        (self.tmp / "a" / "ai_runs.jsonl").unlink()
        with self.assertRaises(FatalInputError):
            load_inputs([self.tmp / "a"])

    def test_複数月は同じIDを二重に数えない(self):
        write_export(self.tmp / "a", {"ai_runs.jsonl": line(fx.runs()[0])})
        write_export(self.tmp / "b", {"ai_runs.jsonl": line(fx.runs()[0]) + line(fx.runs()[1])})

        data = load_inputs([self.tmp / "a", self.tmp / "b"])

        self.assertEqual(len(data.runs), 2)
        self.assertEqual([(i.kind) for i in data.issues], ["duplicate_id"])


if __name__ == "__main__":
    unittest.main()
