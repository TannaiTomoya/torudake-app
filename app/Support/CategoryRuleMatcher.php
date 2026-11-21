<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * 店名にキーワードを含む分類ルールの照合（#69）。規則だけで判定し、AI は使わない。同じ入力なら同じ答え。
 * Python の参照実装（tools/analysis/category_rule.py）と、tests/fixtures/category_rule_vectors.json で同じ答えになることを確かめる。
 *
 * - 渡すのは店名と、表の上から並んだルール（keyword・category_id・任意の id／label）だけ
 * - キーワードも店名も VendorKey と同じ正規化で比べる（空なら当てない）
 * - 上から見て、最初に「店名にキーワードが含まれる」行を採用。ほかに当たった行は other に残す
 *
 * @phpstan-type MatchedRule array{category_id: string, keyword: string, id: string|null, label: string|null}
 * @phpstan-type Match array{category_id: string, keyword: string, id: string|null, label: string|null, other: list<MatchedRule>}
 */
final class CategoryRuleMatcher
{
    /**
     * @param  array<array-key, mixed>  $rules  表の上から（position 昇順）。それぞれ keyword・category_id
     * @return Match|null 1件も当たらなければ null
     */
    public static function match(mixed $vendor, array $rules): ?array
    {
        if ($vendor !== null && ! is_string($vendor)) {
            throw new InvalidArgumentException('店名は文字で渡してください。');
        }

        $vendorKey = VendorKey::of($vendor);
        if ($vendorKey === null) {
            return null;
        }

        /** @var list<MatchedRule> $matched */
        $matched = [];
        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                throw new InvalidArgumentException('ルールは、キーワードと分類の組で渡してください。');
            }

            $keyword = $rule['keyword'] ?? null;
            $categoryId = $rule['category_id'] ?? null;
            if (! is_string($keyword) || ! is_string($categoryId) || $categoryId === '') {
                throw new InvalidArgumentException('ルールは、キーワードと分類の組で渡してください。');
            }

            $keywordKey = VendorKey::of($keyword);
            if ($keywordKey === null) {
                throw new InvalidArgumentException('キーワードが空です。');
            }

            if (! str_contains($vendorKey, $keywordKey)) {
                continue;
            }

            $matched[] = [
                'category_id' => $categoryId,
                'keyword' => $keyword,
                'id' => is_string($rule['id'] ?? null) ? $rule['id'] : null,
                'label' => is_string($rule['label'] ?? null) ? $rule['label'] : $keyword,
            ];
        }

        if ($matched === []) {
            return null;
        }

        $first = $matched[0];
        $other = [];
        foreach ($matched as $index => $row) {
            if ($index === 0) {
                continue;
            }
            $other[] = $row;
        }

        return [
            'category_id' => $first['category_id'],
            'keyword' => $first['keyword'],
            'id' => $first['id'],
            'label' => $first['label'],
            'other' => $other,
        ];
    }
}
