<?php

namespace App\Services\Ai\Tasks;

use App\Enums\AiTask;
use App\Models\Account;
use App\Models\AiCorrection;
use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Company;
use App\Models\SourceRecord;
use App\Services\Ai\AiOutcome;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AiTaskRunner;

/**
 * L2：L1 の結果と、会社の分類・勘定科目・口座・直した例から、分類と借方・貸方の行を作る。
 */
class Journalize
{
    public const PROMPT_VERSION = 'journalize.v1';

    public function __construct(private readonly AiTaskRunner $runner) {}

    /**
     * @param  array<string, mixed>  $reading  L1 の出力
     */
    public function run(Company $company, SourceRecord $source, array $reading): AiOutcome
    {
        return $this->runner->run($company, $source, new AiRequest(
            task: AiTask::Journalize,
            system: AiTaskRunner::prompt(AiTask::Journalize),
            input: self::buildInput($company, $reading),
            schema: self::schema(),
            effort: 'medium',
        ), self::PROMPT_VERSION);
    }

    /**
     * 直した例は、必ずその会社のものだけを入れる。
     *
     * @param  array<string, mixed>  $reading
     * @return array<string, mixed>
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

        $accounts = $company->accounts()
            ->whereNull('archived_at')
            ->orderBy('code')
            ->get()
            ->map(fn (Account $a): array => [
                'code' => $a->code,
                'name' => $a->name,
                'type' => $a->type->value,
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

        $corrections = $company->aiCorrections()
            ->latest('created_at')
            ->limit((int) config('torudake.ai.corrections_in_prompt'))
            ->get()
            ->map(fn (AiCorrection $c): array => [
                'vendor' => $c->vendor,
                'field' => $c->field,
                'before' => $c->before,
                'after' => $c->after,
                'reason' => $c->reason->value,
                'kernel' => $c->kernel,
            ])
            ->values()
            ->all();

        return [
            'reading' => $reading,
            'categories' => $categories,
            'accounts' => $accounts,
            'bank_accounts' => $bankAccounts,
            'corrections' => $corrections,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['category', 'description', 'vendor', 'lines', 'reason', 'confidence', 'remembered'],
            'properties' => [
                'category' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'vendor' => ['type' => ['string', 'null']],
                'lines' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['side', 'account_code', 'amount_yen'],
                        'properties' => [
                            'side' => ['type' => 'string', 'enum' => ['debit', 'credit']],
                            'account_code' => ['type' => 'string'],
                            'amount_yen' => ['type' => 'integer'],
                        ],
                    ],
                ],
                'reason' => ['type' => 'string'],
                'confidence' => ['type' => 'number'],
                'remembered' => ['type' => 'boolean'],
            ],
        ];
    }
}
