<?php

namespace App\Services\Ai\Tasks;

use App\Enums\AiTask;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\Ai\AiOutcome;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AiTaskRunner;

/**
 * L3：仕訳の行の借方合計・貸方合計・一致・気になる点を出す。
 */
class CheckEntry
{
    public const PROMPT_VERSION = 'check_entry.v1';

    public function __construct(private readonly AiTaskRunner $runner) {}

    public function run(Company $company, JournalEntry $entry): AiOutcome
    {
        $lines = $entry->lines()->with('account')->get()
            ->map(fn (JournalLine $line): array => [
                'side' => $line->side->value,
                'account' => $line->account?->name,
                'amount_yen' => $line->amount_yen,
            ])
            ->values()
            ->all();

        return $this->runner->run($company, $entry, new AiRequest(
            task: AiTask::CheckEntry,
            system: AiTaskRunner::prompt(AiTask::CheckEntry),
            input: [
                'entry_date' => $entry->entry_date->toDateString(),
                'vendor' => $entry->vendor,
                'description' => $entry->description,
                'lines' => $lines,
            ],
            schema: self::schema(),
            effort: 'low',
            maxTokens: 1024,
        ), self::PROMPT_VERSION);
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['debit_total', 'credit_total', 'balanced', 'concerns'],
            'properties' => [
                'debit_total' => ['type' => 'integer'],
                'credit_total' => ['type' => 'integer'],
                'balanced' => ['type' => 'boolean'],
                'concerns' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }
}
