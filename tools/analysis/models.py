"""分析用 JSONL（docs/12-analysis-export-spec.md）を読み、型と必須項目を確かめて取り込む。

- 標準ライブラリだけ。ネットワーク・DB には触れず、入力ファイルを書き換えない。
- エラーは「ファイル名・行番号・種別・項目名」だけを残す。行の値（金額・店の仮名キーなど）は出さない。
- 貸借の検算は PHP の App\\Support\\JournalBalanceChecker と同じ規則（整数、float は使わない）。
  tests/fixtures/balance_vectors.json の共通ベクトルで両方が同じ答えになることを確かめる。
"""

from __future__ import annotations

import hashlib
import json
import re
import zipfile
from dataclasses import dataclass, field
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Iterable

SUPPORTED_SCHEMA_VERSIONS = (3, 4, 5)

REQUIRED_FILES = ("ai_runs.jsonl", "ai_corrections.jsonl", "first_confirmations.jsonl")

MAX_LINE_YEN = 100_000_000_000
MAX_TOTAL_YEN = 1_000_000_000_000

CORRECTION_FIELDS = ("date", "vendor", "category", "credit_account", "amount", "other")
KERNEL_KINDS = (
    "vendor_to_category",
    "vendor_to_payment",
    "amount_change",
    "date_change",
    "vendor_change",
    "other",
)

JST = timezone(timedelta(hours=9))
_KEY = re.compile(r"^[a-z]_[0-9a-f]{16}$")
_TIME = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$")


class FatalInputError(Exception):
    """入力の形そのものが違う（manifest がない・版が未対応など）。集計せずに止める。"""


# --- 貸借の検算（PHP の JournalBalanceChecker と同じ規則） ------------------------------------


@dataclass(frozen=True)
class BalanceResult:
    debit_total: int
    credit_total: int
    problem: str | None

    @property
    def balanced(self) -> bool:
        return self.problem is None


def is_int(value: Any) -> bool:
    """真偽値は整数として扱わない（Python では bool が int の子クラスのため）。"""
    return isinstance(value, int) and not isinstance(value, bool)


def check_balance(rows: Iterable[Any]) -> BalanceResult:
    """行（side・amount）を整数で検算する。空・片側だけ・不正な借貸・1円未満・範囲外は「一致」にしない。

    問題が複数あっても最初に見つけた問題を返す。一致は、科目・税率・取引内容の正しさを保証しない。
    """
    rows = list(rows)
    if not rows:
        return BalanceResult(0, 0, "empty")

    debit = credit = 0
    has_debit = has_credit = False
    problem: str | None = None

    for row in rows:
        side = row.get("side") if isinstance(row, dict) else None
        amount = row.get("amount") if isinstance(row, dict) else None

        if side not in ("debit", "credit") or not isinstance(side, str):
            problem = problem or "invalid_side"
            continue
        if not is_int(amount) or amount < 1:
            problem = problem or "non_positive"
            continue
        if amount > MAX_LINE_YEN:
            problem = problem or "out_of_range"
            continue

        if side == "debit":
            has_debit = True
            debit += amount
        else:
            has_credit = True
            credit += amount

        if debit > MAX_TOTAL_YEN or credit > MAX_TOTAL_YEN:
            problem = problem or "out_of_range"
            debit = min(debit, MAX_TOTAL_YEN + 1)
            credit = min(credit, MAX_TOTAL_YEN + 1)

    if problem is None:
        if not has_debit or not has_credit:
            problem = "one_sided"
        elif debit != credit:
            problem = "mismatch"

    return BalanceResult(debit, credit, problem)


# --- 行のエラー ---------------------------------------------------------------------------


@dataclass(frozen=True)
class Issue:
    """入力の問題。値そのものは持たない（ファイル・行・種別・項目名だけ）。"""

    file: str
    line: int
    kind: str
    field: str | None = None


class RowError(Exception):
    def __init__(self, kind: str, field_name: str | None = None) -> None:
        super().__init__(kind)
        self.kind = kind
        self.field = field_name


# --- 行の型 ---------------------------------------------------------------------------------


@dataclass(frozen=True)
class AiRun:
    id: str
    task: str
    subject_key: str | None
    model: str | None
    prompt_version: str | None
    driver: str | None
    valid: bool
    has_error: bool
    input_tokens: int | None
    output_tokens: int | None
    latency_ms: int | None
    created_at: datetime
    input_features: dict[str, Any] | None
    output_features: dict[str, Any] | None


@dataclass(frozen=True)
class Correction:
    id: str
    ai_run_id: str | None
    journal_entry_id: str
    field: str
    kernel_kind: str
    vendor_key: str | None
    created_at: datetime


@dataclass(frozen=True)
class FirstConfirmation:
    id: str
    journal_entry_id: str
    kind: str
    evaluation_class: str
    required_complete: bool
    unchanged: bool
    changed_fields: tuple[str, ...]
    filled_fields: tuple[str, ...]
    description_changed: bool
    recommendation: dict[str, Any] | None
    post_events: int
    first_confirmed_at: datetime


# --- 型を確かめる小さな道具 ---------------------------------------------------------------


def _need(obj: dict[str, Any], name: str) -> Any:
    if name not in obj:
        raise RowError("missing_field", name)
    return obj[name]


def _key(value: Any, name: str, nullable: bool = False) -> str | None:
    if value is None and nullable:
        return None
    if not isinstance(value, str) or not _KEY.match(value):
        raise RowError("bad_value", name)
    return value


def _str(value: Any, name: str, nullable: bool = False, limit: int = 80) -> str | None:
    if value is None and nullable:
        return None
    if not isinstance(value, str) or len(value) > limit:
        raise RowError("wrong_type", name)
    return value


def _bool(value: Any, name: str) -> bool:
    if not isinstance(value, bool):
        raise RowError("wrong_type", name)
    return value


def _count(value: Any, name: str, nullable: bool = True) -> int | None:
    if value is None and nullable:
        return None
    if not is_int(value) or value < 0:
        raise RowError("wrong_type", name)
    return value


def _enum(value: Any, name: str, allowed: tuple[str, ...]) -> str:
    if not isinstance(value, str) or value not in allowed:
        raise RowError("bad_value", name)
    return value


def _dict(value: Any, name: str) -> dict[str, Any] | None:
    if value is None:
        return None
    if not isinstance(value, dict):
        raise RowError("wrong_type", name)
    return value


def _names(value: Any, name: str) -> tuple[str, ...]:
    if not isinstance(value, list) or not all(isinstance(v, str) and len(v) <= 40 for v in value):
        raise RowError("wrong_type", name)
    return tuple(value)


def parse_utc(value: Any, name: str) -> datetime:
    if not isinstance(value, str) or not _TIME.match(value):
        raise RowError("bad_time", name)
    try:
        return datetime.strptime(value, "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=timezone.utc)
    except ValueError:
        raise RowError("bad_time", name) from None


def month_of(moment: datetime) -> str:
    """会社の時刻（Asia/Tokyo）での月（YYYY-MM）。"""
    return moment.astimezone(JST).strftime("%Y-%m")


def parse_run(obj: dict[str, Any]) -> AiRun:
    return AiRun(
        id=_key(_need(obj, "id"), "id"),  # type: ignore[arg-type]
        task=_str(_need(obj, "task"), "task", limit=40),  # type: ignore[arg-type]
        subject_key=_key(obj.get("subject_key"), "subject_key", nullable=True),
        model=_str(obj.get("model"), "model", nullable=True),
        prompt_version=_str(obj.get("prompt_version"), "prompt_version", nullable=True),
        driver=_str(obj.get("driver"), "driver", nullable=True, limit=40),
        valid=_bool(_need(obj, "valid"), "valid"),
        has_error=_bool(_need(obj, "has_error"), "has_error"),
        input_tokens=_count(obj.get("input_tokens"), "input_tokens"),
        output_tokens=_count(obj.get("output_tokens"), "output_tokens"),
        latency_ms=_count(obj.get("latency_ms"), "latency_ms"),
        created_at=parse_utc(_need(obj, "created_at"), "created_at"),
        input_features=_dict(obj.get("input_features"), "input_features"),
        output_features=_dict(obj.get("output_features"), "output_features"),
    )


def parse_correction(obj: dict[str, Any]) -> Correction:
    return Correction(
        id=_key(_need(obj, "id"), "id"),  # type: ignore[arg-type]
        ai_run_id=_key(obj.get("ai_run_id"), "ai_run_id", nullable=True),
        journal_entry_id=_key(_need(obj, "journal_entry_id"), "journal_entry_id"),  # type: ignore[arg-type]
        field=_enum(_need(obj, "field"), "field", CORRECTION_FIELDS),
        kernel_kind=_enum(_need(obj, "kernel_kind"), "kernel_kind", KERNEL_KINDS),
        vendor_key=_key(obj.get("vendor_key"), "vendor_key", nullable=True),
        created_at=parse_utc(_need(obj, "created_at"), "created_at"),
    )


def parse_first_confirmation(obj: dict[str, Any]) -> FirstConfirmation:
    events = obj.get("post_events", [])
    if not isinstance(events, list) or not all(isinstance(e, dict) for e in events):
        raise RowError("wrong_type", "post_events")

    return FirstConfirmation(
        id=_key(_need(obj, "id"), "id"),  # type: ignore[arg-type]
        journal_entry_id=_key(_need(obj, "journal_entry_id"), "journal_entry_id"),  # type: ignore[arg-type]
        kind=_enum(_need(obj, "kind"), "kind", ("ai", "manual")),
        evaluation_class=_enum(_need(obj, "evaluation_class"), "evaluation_class", ("real", "demo")),
        required_complete=_bool(_need(obj, "required_complete"), "required_complete"),
        unchanged=_bool(_need(obj, "unchanged"), "unchanged"),
        changed_fields=_names(_need(obj, "changed_fields"), "changed_fields"),
        filled_fields=_names(_need(obj, "filled_fields"), "filled_fields"),
        description_changed=_bool(_need(obj, "description_changed"), "description_changed"),
        recommendation=_dict(obj.get("recommendation"), "recommendation"),
        post_events=len(events),
        first_confirmed_at=parse_utc(_need(obj, "first_confirmed_at"), "first_confirmed_at"),
    )


# --- 読み込み -------------------------------------------------------------------------------


@dataclass
class Dataset:
    company_key: str | None = None
    inputs: list[dict[str, Any]] = field(default_factory=list)
    runs: list[AiRun] = field(default_factory=list)
    corrections: list[Correction] = field(default_factory=list)
    first_confirmations: list[FirstConfirmation] = field(default_factory=list)
    issues: list[Issue] = field(default_factory=list)


class _Source:
    """展開済みのフォルダか zip の中身を、名前で読む。"""

    def __init__(self, path: Path) -> None:
        self.path = path
        self._zip: zipfile.ZipFile | None = None
        if path.is_dir():
            return
        if path.is_file() and zipfile.is_zipfile(path):
            self._zip = zipfile.ZipFile(path)
            return
        raise FatalInputError("入力はフォルダか zip ファイルで指定してください")

    def read(self, name: str) -> bytes:
        try:
            if self._zip is not None:
                return self._zip.read(name)
            return (self.path / name).read_bytes()
        except (KeyError, FileNotFoundError):
            raise FatalInputError(f"{name} が見つかりません") from None

    def close(self) -> None:
        if self._zip is not None:
            self._zip.close()


def _read_manifest(source: _Source) -> dict[str, Any]:
    try:
        manifest = json.loads(source.read("manifest.json"))
    except json.JSONDecodeError:
        raise FatalInputError("manifest.json を読めません") from None
    if not isinstance(manifest, dict):
        raise FatalInputError("manifest.json の形が違います")

    version = manifest.get("schema_version")
    if not is_int(version) or version not in SUPPORTED_SCHEMA_VERSIONS:
        raise FatalInputError(f"未対応の schema_version です（対応：{', '.join(map(str, SUPPORTED_SCHEMA_VERSIONS))}）")
    if not isinstance(manifest.get("company_key"), str):
        raise FatalInputError("manifest.json に company_key がありません")

    return manifest


def _read_rows(
    source: _Source,
    name: str,
    parse,
    seen_ids: set[str],
    issues: list[Issue],
    unique_entry: set[str] | None = None,
) -> tuple[list[Any], int, str]:
    """1ファイルを読む。返り値は（取り込めた行, 空行を除いた行数, sha256）。"""
    raw = source.read(name)
    digest = hashlib.sha256(raw).hexdigest()
    rows: list[Any] = []
    count = 0

    for number, chunk in enumerate(raw.split(b"\n"), start=1):
        if not chunk.strip():
            continue
        count += 1
        try:
            obj = json.loads(chunk.decode("utf-8"))
        except (UnicodeDecodeError, json.JSONDecodeError, RecursionError):
            issues.append(Issue(name, number, "invalid_json"))
            continue
        if not isinstance(obj, dict):
            issues.append(Issue(name, number, "not_object"))
            continue
        try:
            row = parse(obj)
        except RowError as error:
            issues.append(Issue(name, number, error.kind, error.field))
            continue

        if row.id in seen_ids:
            issues.append(Issue(name, number, "duplicate_id", "id"))
            continue
        if unique_entry is not None:
            if row.journal_entry_id in unique_entry:
                issues.append(Issue(name, number, "duplicate_id", "journal_entry_id"))
                continue
            unique_entry.add(row.journal_entry_id)
        seen_ids.add(row.id)
        rows.append(row)

    return rows, count, digest


def load_inputs(paths: Iterable[Path]) -> Dataset:
    """1か月分以上の書き出し（フォルダか zip）を読む。会社が違うものは混ぜない。

    同じ ID の行が複数の入力に出たら、最初のものだけを採る（重複は issues に残す）。
    """
    data = Dataset()
    seen = {name: set() for name in REQUIRED_FILES}
    seen_entries: set[str] = set()
    parsers = {
        "ai_runs.jsonl": (parse_run, data.runs, None),
        "ai_corrections.jsonl": (parse_correction, data.corrections, None),
        "first_confirmations.jsonl": (parse_first_confirmation, data.first_confirmations, seen_entries),
    }

    for path in paths:
        source = _Source(Path(path))
        try:
            manifest = _read_manifest(source)
            if data.company_key is not None and data.company_key != manifest["company_key"]:
                raise FatalInputError("会社の違う書き出しは一緒に集計できません")
            data.company_key = manifest["company_key"]
            data.inputs.append(
                {
                    "month": manifest.get("month") if isinstance(manifest.get("month"), str) else None,
                    "schema_version": manifest["schema_version"],
                    "metric_version": manifest.get("metric_version") if isinstance(manifest.get("metric_version"), str) else None,
                }
            )
            files = manifest.get("files") if isinstance(manifest.get("files"), dict) else {}

            for name, (parse, target, unique) in parsers.items():
                rows, count, digest = _read_rows(source, name, parse, seen[name], data.issues, unique)
                target.extend(rows)

                declared = files.get(name) if isinstance(files.get(name), dict) else {}
                if declared.get("sha256") != digest:
                    data.issues.append(Issue(name, 0, "hash_mismatch"))
                if declared.get("rows") != count:
                    data.issues.append(Issue(name, 0, "row_count_mismatch"))
        finally:
            source.close()

    return data
