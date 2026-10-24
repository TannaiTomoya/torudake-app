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
    public const PROMPT_VERSION = 'check_entry.v2';

    public function __construct(private readonly AiTaskRunner $runner) {}

    public function run(Company $company, JournalEntry $entry, ?string $photoSha256 = null): AiOutcome
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
                'recent_entries' => self::recentEntries($company, $entry),
            ],
            schema: self::schema(),
            effort: 'low',
            maxTokens: 1024,
            photoSha256: $photoSha256,
        ), self::PROMPT_VERSION);
    }

    /**
     * 重複の疑いを見るための、この会社の近い日付の仕訳（自分は除く）。
     *
     * @return list<array{entry_date: string, vendor: string|null, amount_yen: int|null}>
     */
    private static function recentEntries(Company $company, JournalEntry $entry): array
    {
        return array_values($company->journalEntries()
            ->with('lines')
            ->whereKeyNot($entry->id)
            ->whereBetween('entry_date', [$entry->entry_date->copy()->subDays(14), $entry->entry_date->copy()->addDays(14)])
            ->latest('entry_date')
            ->limit(30)
            ->get()
            ->map(fn (JournalEntry $other): array => [
                'entry_date' => $other->entry_date->toDateString(),
                'vendor' => $other->vendor,
                'amount_yen' => $other->amountYen(),
            ])
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['debit_total', 'credit_total', 'balanced', 'duplicate_suspected', 'private_suspected', 'concerns'],
            'properties' => [
                'debit_total' => ['type' => 'integer'],
                'credit_total' => ['type' => 'integer'],
                'balanced' => ['type' => 'boolean'],
                'duplicate_suspected' => ['type' => 'boolean'],
                'private_suspected' => ['type' => 'boolean'],
                'concerns' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }
}
