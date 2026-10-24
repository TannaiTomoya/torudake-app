<?php

namespace App\Services\Ai\Tasks;

use App\Enums\AiTask;
use App\Models\AiCorrection;
use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Company;
use App\Models\SourceRecord;
use App\Services\Ai\AiOutcome;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AiTaskRunner;

/**
 * L2：L1 の結果と、会社の分類・口座・直した例から、分類と支払い元を選ぶ。借方・貸方の行はコードが組む（AiDraftBuilder）。
 */
class Journalize
{
    public const PROMPT_VERSION = 'journalize.v3';

    public function __construct(private readonly AiTaskRunner $runner) {}

    /**
     * @param  array<string, mixed>  $reading  L1 の出力
     */
    public function run(Company $company, SourceRecord $source, array $reading): AiOutcome
    {
        $input = self::buildInput($company, $reading);

        return $this->runner->run($company, $source, new AiRequest(
            task: AiTask::Journalize,
            system: AiTaskRunner::prompt(AiTask::Journalize),
            input: $input,
            schema: self::schema(
                array_map(fn (array $category): string => $category['name'], $input['categories']),
                array_map(fn (array $bankAccount): string => $bankAccount['name'], $input['bank_accounts']),
            ),
            effort: 'medium',
            photoSha256: $source->evidence?->sha256,
        ), self::PROMPT_VERSION);
    }

    /**
     * 直した例は、必ずその会社のものだけを入れる。
     *
     * @param  array<string, mixed>  $reading
     * @return array{
     *     reading: array<string, mixed>,
     *     categories: list<array{name: string, direction: string, account_code: string|null, account_name: string|null}>,
     *     bank_accounts: list<array{name: string, kind: string, account_code: string|null, default: bool}>,
     *     corrections: list<array{vendor: string|null, field: string, before: string|null, after: string|null, reason: string, kernel: string|null}>
     * }
     */
    public static function buildInput(Company $company, array $reading): array
    {
        $categories = $company->categories()
            ->with('account')
            ->whereNull('archived_at')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Category $c): array => [
                'name' => $c->name,
                'direction' => $c->direction->value,
                'account_code' => $c->account?->code,
                'account_name' => $c->account?->name,
            ])
            ->values()
            ->all();

        $bankAccounts = $company->bankAccounts()
            ->with('account')
            ->whereNull('archived_at')
            ->get()
            ->map(fn (BankAccount $b): array => [
                'name' => $b->name,
                'kind' => $b->kind->value,
                'account_code' => $b->account?->code,
                'default' => $b->is_default_for_receipts,
            ])
            ->values()
            ->all();

        $categoryNames = array_column($categories, 'name');
        $bankAccountNames = array_column($bankAccounts, 'name');

        $corrections = $company->aiCorrections()
            ->latest('created_at')
            ->where(function ($query) use ($categoryNames, $bankAccountNames): void {
                $query->whereNotIn('field', ['category', 'credit_account'])
                    ->orWhere(fn ($query) => $query->where('field', 'category')->whereIn('after', $categoryNames))
                    ->orWhere(fn ($query) => $query->where('field', 'credit_account')->whereIn('after', $bankAccountNames));
            })
            ->limit((int) config('torudake.ai.corrections_in_prompt'))
            ->get()
            ->map(function (AiCorrection $c) use ($categoryNames, $bankAccountNames): array {
                $choices = match ($c->field) {
                    'category' => $categoryNames,
                    'credit_account' => $bankAccountNames,
                    default => null,
                };
                $beforeIsCurrent = $choices === null || $c->before === null || in_array($c->before, $choices, true);

                return [
                    'vendor' => $c->vendor,
                    'field' => $c->field,
                    'before' => $beforeIsCurrent ? $c->before : null,
                    'after' => $c->after,
                    'reason' => $c->reason->value,
                    'kernel' => $beforeIsCurrent ? $c->kernel : null,
                ];
            })
            ->values()
            ->all();

        return [
            'reading' => $reading,
            'categories' => array_values($categories),
            'bank_accounts' => array_values($bankAccounts),
            'corrections' => array_values($corrections),
        ];
    }

    /**
     * @param  list<string>  $categoryNames
     * @param  list<string>  $bankAccountNames
     * @return array<string, mixed>
     */
    public static function schema(array $categoryNames, array $bankAccountNames): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['category', 'bank_account', 'description', 'vendor', 'reason', 'confidence', 'remembered'],
            'properties' => [
                'category' => ['type' => 'string', 'enum' => $categoryNames],
                'bank_account' => ['type' => 'string', 'enum' => $bankAccountNames],
                'description' => ['type' => 'string'],
                'vendor' => ['type' => ['string', 'null']],
                'reason' => ['type' => 'string'],
                'confidence' => ['type' => 'number'],
                'remembered' => ['type' => 'boolean'],
            ],
        ];
    }
}
