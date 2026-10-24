<?php

namespace App\Services;

use App\Enums\EntryStatus;
use App\Models\AiCorrection;
use App\Models\AiRun;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Carbon\CarbonImmutable;
use RuntimeException;
use ZipArchive;

/**
 * 学習データ（1か月分）を JSONL 3つの zip にする。写真・名前・メールは入れず、人は伏せた記号で出す。
 */
class TrainingDataExporter
{
    /**
     * @return array{contents: string, counts: array{ai_runs: int, ai_corrections: int, journal_entries: int}}
     */
    public function export(Company $company, CarbonImmutable $month): array
    {
        $from = $month->startOfMonth();
        $to = $from->addMonth();

        $runs = $company->aiRuns()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->orderBy('created_at')
            ->get()
            ->map(fn (AiRun $run): array => [
                'id' => $run->id,
                'task' => $run->task->value,
                'subject_type' => $run->subject_type === null ? null : class_basename($run->subject_type),
                'subject_id' => $run->subject_id,
                'model' => $run->model,
                'prompt_version' => $run->prompt_version,
                'driver' => $run->driver,
                'input' => $run->input,
                'output' => $run->output,
                'valid' => $run->valid,
                'input_tokens' => $run->input_tokens,
                'output_tokens' => $run->output_tokens,
                'latency_ms' => $run->latency_ms,
                'error' => $run->error,
                'created_at' => $run->created_at?->toIso8601String(),
            ]);

        $corrections = $company->aiCorrections()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->orderBy('created_at')
            ->get()
            ->map(fn (AiCorrection $correction): array => [
                'id' => $correction->id,
                'ai_run_id' => $correction->ai_run_id,
                'journal_entry_id' => $correction->journal_entry_id,
                'field' => $correction->field,
                'before' => $correction->before,
                'after' => $correction->after,
                'reason' => $correction->reason->value,
                'kernel' => $correction->kernel,
                'vendor' => $correction->vendor,
                'corrected_by' => $this->person($correction->corrected_by),
                'created_at' => $correction->created_at?->toIso8601String(),
            ]);

        $corrected = $company->aiCorrections()->whereNotNull('journal_entry_id')->distinct()->pluck('journal_entry_id')->flip();

        $entries = $company->journalEntries()
            ->whereIn('status', [EntryStatus::Confirmed, EntryStatus::Returned])
            ->where('confirmed_at', '>=', $from)
            ->where('confirmed_at', '<', $to)
            ->with(['category', 'bankAccount', 'lines.account'])
            ->orderBy('confirmed_at')
            ->get()
            ->map(fn (JournalEntry $entry): array => [
                'id' => $entry->id,
                'entry_date' => $entry->entry_date->toDateString(),
                'status' => $entry->status->value,
                'vendor' => $entry->vendor,
                'description' => $entry->description,
                'category' => $entry->category?->name,
                'payment' => $entry->bankAccount?->name,
                'amount_yen' => $entry->amountYen(),
                'lines' => $entry->lines->sortBy('line_no')->map(fn (JournalLine $line): array => [
                    'side' => $line->side->value,
                    'account' => $line->account?->name,
                    'amount_yen' => $line->amount_yen,
                ])->values()->all(),
                'ai_run_id' => $entry->ai_run_id,
                'origin' => match (true) {
                    $entry->ai_run_id === null => 'manual',
                    $corrected->has($entry->id) => 'corrected',
                    default => 'ai_unchanged',
                },
                'concern_flags' => $entry->concern_flags ?? [],
                'confirmed_by' => $this->person($entry->confirmed_by),
                'confirmed_at' => $entry->confirmed_at?->toIso8601String(),
            ]);

        $path = tempnam(sys_get_temp_dir(), 'torudake-training-');
        if ($path === false) {
            throw new RuntimeException('一時ファイルを作れませんでした。');
        }

        try {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('zip を作れませんでした。');
            }
            $zip->addFromString('ai_runs.jsonl', $this->jsonl($runs));
            $zip->addFromString('ai_corrections.jsonl', $this->jsonl($corrections));
            $zip->addFromString('journal_entries.jsonl', $this->jsonl($entries));
            $zip->close();

            $contents = (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }

        return [
            'contents' => $contents,
            'counts' => [
                'ai_runs' => $runs->count(),
                'ai_corrections' => $corrections->count(),
                'journal_entries' => $entries->count(),
            ],
        ];
    }

    /**
     * @param  iterable<array<string, mixed>>  $rows
     */
    private function jsonl(iterable $rows): string
    {
        $text = '';

        foreach ($rows as $row) {
            $text .= json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        }

        return $text;
    }

    private function person(?int $userId): ?string
    {
        return $userId === null ? null : 'u_'.substr(hash_hmac('sha256', (string) $userId, (string) config('app.key')), 0, 12);
    }
}
