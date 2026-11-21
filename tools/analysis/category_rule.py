"""分類ルールの照合（#69）の参照実装。PHP の App\\Support\\CategoryRuleMatcher と同じ規則。

- 標準ライブラリだけ。店名と、表の上から並んだルール（keyword・category_id）だけを使う。
- tests/fixtures/category_rule_vectors.json の共通ベクトルで、PHP と同じ答えになることを確かめる。
- キーワードも店名も VendorKey と同じ正規化（小文字・空白除去・全角半角）。空なら当てない。
- 上から見て最初に当たった行を採用。ほかに当たった行は other。
"""

from __future__ import annotations

import re
import unicodedata
from typing import Any

_WS = re.compile(r"\s+", re.UNICODE)


def vendor_key(vendor: Any) -> str | None:
    if vendor is None:
        return None
    if not isinstance(vendor, str):
        raise ValueError("店名は文字で渡してください。")
    # PHP の mb_convert_kana('asKV') に近い：全角英数・カナを半角寄りにそろえ、空白を除き小文字化
    normalized = unicodedata.normalize("NFKC", vendor)
    key = _WS.sub("", normalized).lower()
    if key == "":
        return None
    return key[:100]


def match(vendor: Any, rules: list[Any]) -> dict[str, Any] | None:
    key = vendor_key(vendor)
    if key is None:
        return None

    matched: list[dict[str, Any]] = []
    for rule in rules:
        if not isinstance(rule, dict):
            raise ValueError("ルールは、キーワードと分類の組で渡してください。")

        keyword = rule.get("keyword")
        category_id = rule.get("category_id")
        if not isinstance(keyword, str) or not isinstance(category_id, str) or category_id == "":
            raise ValueError("ルールは、キーワードと分類の組で渡してください。")

        keyword_key = vendor_key(keyword)
        if keyword_key is None:
            raise ValueError("キーワードが空です。")

        if keyword_key not in key:
            continue

        rule_id = rule.get("id")
        label = rule.get("label")
        matched.append(
            {
                "category_id": category_id,
                "keyword": keyword,
                "id": rule_id if isinstance(rule_id, str) else None,
                "label": label if isinstance(label, str) else keyword,
            }
        )

    if not matched:
        return None

    first = matched[0]
    return {
        "category_id": first["category_id"],
        "keyword": first["keyword"],
        "id": first["id"],
        "label": first["label"],
        "other": matched[1:],
    }
