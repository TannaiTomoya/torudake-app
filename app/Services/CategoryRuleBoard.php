<?php

namespace App\Services;

use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\Company;
use App\Support\CategoryRuleMatcher;
use App\Support\VendorKey;
use Illuminate\Support\Collection;

/**
 * 分類のルールを読む・候補を出す（#69）。書くのは CategoryRuleService。権限は呼ぶ側が確かめる。
 *
 * @phpstan-import-type Match from CategoryRuleMatcher
 *
 * @phpstan-type Candidate array{keyword: string, category_id: string, category_name: string, count: int}
 */
class CategoryRuleBoard
{
    public const CANDIDATE_LIMIT = 20;

    /**
     * 表の上から（position 昇順）。
     *
     * @return Collection<int, CategoryRule>
     */
    public function rules(Company $company): Collection
    {
        return $company->categoryRules()
            ->with('category')
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /**
     * 有効なルールだけを Matcher 用に並べる（archived の分類は除く）。
     *
     * @return list<array{id: string, keyword: string, category_id: string, label: string}>
     */
    public function activeRuleInputs(Company $company): array
    {
        $inputs = [];
        foreach ($this->rules($company) as $rule) {
            if ($rule->category->archived_at !== null) {
                continue;
            }
            $inputs[] = [
                'id' => $rule->id,
                'keyword' => $rule->keyword,
                'category_id' => $rule->category_id,
                'label' => $rule->keyword,
            ];
        }

        return $inputs;
    }

    /**
     * @return Match|null
     */
    public function matchVendor(Company $company, ?string $vendor): ?array
    {
        return CategoryRuleMatcher::match($vendor, $this->activeRuleInputs($company));
    }

    /**
     * 直した記録（分類の修正）から、まだルールになっていない候補を出す。採用は人が決める。
     *
     * @return list<Candidate>
     */
    public function candidates(Company $company): array
    {
        $existingKeys = [];
        foreach ($this->rules($company) as $rule) {
            $key = VendorKey::of($rule->keyword);
            if ($key !== null) {
                $existingKeys[$key] = true;
            }
        }

        $categories = $company->categories()
            ->whereNull('archived_at')
            ->get(['id', 'name'])
            ->keyBy('id');

        /** @var array<string, array{keyword: string, category_id: string, category_name: string, count: int}> $buckets */
        $buckets = [];

        $corrections = $company->aiCorrections()
            ->where('field', 'category')
            ->whereNotNull('vendor')
            ->whereNotNull('after')
            ->orderByDesc('created_at')
            ->limit(500)
            ->get(['vendor', 'after']);

        foreach ($corrections as $correction) {
            $vendor = is_string($correction->vendor) ? $correction->vendor : null;
            $afterName = is_string($correction->after) ? $correction->after : null;
            if ($vendor === null || $afterName === null) {
                continue;
            }

            $keyword = mb_substr(trim($vendor), 0, CategoryRule::KEYWORD_MAX);
            $keywordKey = VendorKey::of($keyword);
            if ($keywordKey === null || isset($existingKeys[$keywordKey])) {
                continue;
            }

            /** @var Category|null $category */
            $category = $categories->first(fn (Category $row): bool => $row->name === $afterName);
            if ($category === null) {
                continue;
            }

            $bucketKey = $keywordKey.'|'.$category->id;
            if (! isset($buckets[$bucketKey])) {
                $buckets[$bucketKey] = [
                    'keyword' => $keyword,
                    'category_id' => $category->id,
                    'category_name' => $category->name,
                    'count' => 0,
                ];
            }
            $buckets[$bucketKey]['count']++;
        }

        $list = array_values($buckets);
        usort($list, fn (array $a, array $b): int => [$b['count'], $a['keyword']] <=> [$a['count'], $b['keyword']]);

        return array_slice($list, 0, self::CANDIDATE_LIMIT);
    }
}
