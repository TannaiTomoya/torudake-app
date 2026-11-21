<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CategoryRule;
use App\Models\Company;
use App\Models\User;
use App\Support\VendorKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 分類のルールを足す・直す・消す・並べ替える（#69）。AI は使わない。
 * 既存の下書きは書き換えない（これから作る下書きだけに効く）。権限は呼ぶ側が確かめる。
 */
class CategoryRuleService
{
    public function add(Company $company, User $user, string $keyword, string $categoryId): CategoryRule
    {
        $keyword = $this->normalizeKeyword($keyword);

        return DB::transaction(function () use ($company, $user, $keyword, $categoryId): CategoryRule {
            $company->lockForUpdate()->findOrFail($company->id);
            $this->assertCategory($company, $categoryId);
            $this->assertUniqueKeyword($company, $keyword);

            $company->categoryRules()->lockForUpdate()->orderBy('position')->get()
                ->each(function (CategoryRule $rule): void {
                    $rule->forceFill(['position' => $rule->position + 1])->save();
                });

            $rule = new CategoryRule;
            $rule->forceFill([
                'company_id' => $company->id,
                'keyword' => $keyword,
                'category_id' => $categoryId,
                'position' => 0,
                'created_by' => $user->id,
            ])->save();

            AuditLog::record(
                user: $user,
                action: 'category_rule.create',
                subject: $rule,
                after: $this->snapshot($rule),
                company: $company,
            );

            return $rule->fresh(['category']) ?? $rule;
        });
    }

    public function update(Company $company, User $user, string $ruleId, string $keyword, string $categoryId): CategoryRule
    {
        $keyword = $this->normalizeKeyword($keyword);

        return DB::transaction(function () use ($company, $user, $ruleId, $keyword, $categoryId): CategoryRule {
            /** @var CategoryRule $rule */
            $rule = $company->categoryRules()->lockForUpdate()->findOrFail($ruleId);
            $this->assertCategory($company, $categoryId);
            $this->assertUniqueKeyword($company, $keyword, $rule->id);

            $before = $this->snapshot($rule);
            if ($rule->keyword === $keyword && $rule->category_id === $categoryId) {
                return $rule->load('category');
            }

            $rule->forceFill([
                'keyword' => $keyword,
                'category_id' => $categoryId,
            ])->save();

            AuditLog::record(
                user: $user,
                action: 'category_rule.update',
                subject: $rule,
                before: $before,
                after: $this->snapshot($rule->fresh() ?? $rule),
                company: $company,
            );

            return $rule->fresh(['category']) ?? $rule;
        });
    }

    public function remove(Company $company, User $user, string $ruleId): void
    {
        DB::transaction(function () use ($company, $user, $ruleId): void {
            /** @var CategoryRule|null $rule */
            $rule = $company->categoryRules()->lockForUpdate()->find($ruleId);
            if ($rule === null) {
                return;
            }

            $before = $this->snapshot($rule);
            $removedPosition = $rule->position;
            $rule->delete();

            $company->categoryRules()->lockForUpdate()
                ->where('position', '>', $removedPosition)
                ->orderBy('position')
                ->get()
                ->each(function (CategoryRule $row): void {
                    $row->forceFill(['position' => $row->position - 1])->save();
                });

            AuditLog::record(
                user: $user,
                action: 'category_rule.delete',
                subject: $rule,
                before: $before,
                company: $company,
            );
        });
    }

    /** 1つ上へ（表の優先を上げる） */
    public function moveUp(Company $company, User $user, string $ruleId): void
    {
        $this->swapWithNeighbor($company, $user, $ruleId, -1);
    }

    /** 1つ下へ */
    public function moveDown(Company $company, User $user, string $ruleId): void
    {
        $this->swapWithNeighbor($company, $user, $ruleId, 1);
    }

    private function swapWithNeighbor(Company $company, User $user, string $ruleId, int $delta): void
    {
        DB::transaction(function () use ($company, $user, $ruleId, $delta): void {
            /** @var CategoryRule $rule */
            $rule = $company->categoryRules()->lockForUpdate()->findOrFail($ruleId);
            $neighborPosition = $rule->position + $delta;
            /** @var CategoryRule|null $neighbor */
            $neighbor = $company->categoryRules()->lockForUpdate()->where('position', $neighborPosition)->first();
            if ($neighbor === null) {
                return;
            }

            $before = [
                'rules' => [
                    $this->snapshot($rule),
                    $this->snapshot($neighbor),
                ],
            ];

            $rulePos = $rule->position;
            $rule->forceFill(['position' => $neighbor->position])->save();
            $neighbor->forceFill(['position' => $rulePos])->save();

            AuditLog::record(
                user: $user,
                action: 'category_rule.reorder',
                subject: $rule,
                before: $before,
                after: [
                    'rules' => [
                        $this->snapshot($rule->fresh() ?? $rule),
                        $this->snapshot($neighbor->fresh() ?? $neighbor),
                    ],
                ],
                company: $company,
            );
        });
    }

    private function normalizeKeyword(string $keyword): string
    {
        $trimmed = trim($keyword);
        if (VendorKey::of($trimmed) === null || mb_strlen($trimmed) > CategoryRule::KEYWORD_MAX) {
            throw ValidationException::withMessages([
                'keyword' => 'キーワードは1〜'.CategoryRule::KEYWORD_MAX.'文字で入れてください。',
            ]);
        }

        return $trimmed;
    }

    private function assertCategory(Company $company, string $categoryId): void
    {
        $exists = $company->categories()
            ->whereKey($categoryId)
            ->whereNull('archived_at')
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'categoryId' => '分類を選んでください。',
            ]);
        }
    }

    private function assertUniqueKeyword(Company $company, string $keyword, ?string $exceptId = null): void
    {
        $key = VendorKey::of($keyword);
        $query = $company->categoryRules()->get(['id', 'keyword']);
        foreach ($query as $existing) {
            if ($exceptId !== null && $existing->id === $exceptId) {
                continue;
            }
            if (VendorKey::of($existing->keyword) === $key) {
                throw ValidationException::withMessages([
                    'keyword' => '同じキーワードのルールがすでにあります。',
                ]);
            }
        }
    }

    /**
     * @return array{id: string, keyword: string, category_id: string, position: int}
     */
    private function snapshot(CategoryRule $rule): array
    {
        return [
            'id' => $rule->id,
            'keyword' => $rule->keyword,
            'category_id' => $rule->category_id,
            'position' => $rule->position,
        ];
    }
}
