<?php

namespace App\Services;

use App\Enums\EntryStatus;
use App\Models\AiCorrection;
use App\Models\AiRun;
use App\Models\Company;
use App\Models\JournalEntry;
use Carbon\CarbonImmutable;
use RuntimeException;
use ZipArchive;

/**
 * 分析用データ（1か月分）を JSONL 3つと manifest.json の zip にする。
 * 中身は AnalysisExportSanitizer が許可した項目だけ（#31）。写真・自由文・名前・メールは入れない。仕様は docs/12-analysis-export-spec.md。
 */
class TrainingDataExporter
{
    /**
     * @return array{contents: string, counts: array{ai_runs: int, ai_corrections: int, journal_entries: int}, schema_version: int}
     */
    public function export(Company $company, CarbonImmutable $month): array
    {
        $from = $month->startOfMonth();
        $to = $from->addMonth();
        $sanitizer = new AnalysisExportSanitizer($company);

        $runs = array_values($company->aiRuns()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (AiRun $run): array => $sanitizer->run($run))
            ->all());

        $corrections = array_values($company->aiCorrections()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (AiCorrection $correction): array => $sanitizer->correction($correction))
            ->all());

        $corrected = array_fill_keys(
            $company->aiCorrections()->whereNotNull('journal_entry_id')->distinct()->pluck('journal_entry_id')->all(),
            true,
        );

        $entries = array_values($company->journalEntries()
            ->whereIn('status', [EntryStatus::Confirmed, EntryStatus::Returned])
            ->where('confirmed_at', '>=', $from)
            ->where('confirmed_at', '<', $to)
            ->with(['lines.account'])
            ->orderBy('confirmed_at')
            ->orderBy('id')
            ->get()
            ->map(fn (JournalEntry $entry): array => $sanitizer->entry($entry, $corrected))
            ->all());

        $files = [
            'ai_runs.jsonl' => $this->jsonl($runs),
            'ai_corrections.jsonl' => $this->jsonl($corrections),
            'journal_entries.jsonl' => $this->jsonl($entries),
        ];
        $counts = [
            'ai_runs' => count($runs),
            'ai_corrections' => count($corrections),
            'journal_entries' => count($entries),
        ];

        $manifest = $this->manifest($sanitizer, $month, $from, $to, $files, $counts, $runs, $corrections, $entries);

        $path = tempnam(sys_get_temp_dir(), 'torudake-training-');
        if ($path === false) {
            throw new RuntimeException('一時ファイルを作れませんでした。');
        }

        try {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('zip を作れませんでした。');
            }
            foreach ($files as $name => $text) {
                $zip->addFromString($name, $text);
            }
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
            $zip->close();

            $contents = (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }

        return [
            'contents' => $contents,
            'counts' => $counts,
            'schema_version' => AnalysisExportSanitizer::SCHEMA_VERSION,
        ];
    }

    /**
     * @param  array<string, string>  $files
     * @param  array{ai_runs: int, ai_corrections: int, journal_entries: int}  $counts
     * @param  list<array<string, mixed>>  $runs
     * @param  list<array<string, mixed>>  $corrections
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    private function manifest(
        AnalysisExportSanitizer $sanitizer,
        CarbonImmutable $month,
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $files,
        array $counts,
        array $runs,
        array $corrections,
        array $entries,
    ): array {
        $runIds = array_fill_keys(array_column($runs, 'id'), true);
        $entryIds = array_fill_keys(array_column($entries, 'id'), true);

        $outside = fn (array $rows, string $column, array $known): int => count(array_filter(
            $rows,
            fn (array $row): bool => $row[$column] !== null && ! isset($known[$row[$column]]),
        ));

        return [
            'schema_version' => AnalysisExportSanitizer::SCHEMA_VERSION,
            'metric_version' => AnalysisExportSanitizer::METRIC_VERSION,
            'sensitivity' => '仮名化した機密データ（匿名化ではない）。公開リポジトリ・Issue・Actions のログや成果物に上げない',
            'company_key' => $sanitizer->companyKey(),
            'month' => $month->format('Y-m'),
            'period' => [
                'basis' => 'ai_runs と ai_corrections は created_at、journal_entries は confirmed_at が期間内（from 以上、to 未満）',
                'timezone' => $from->getTimezone()->getName(),
                'from_local' => $from->format('Y-m-d\TH:i:sP'),
                'to_local' => $to->format('Y-m-d\TH:i:sP'),
                'from_utc' => $sanitizer->time($from),
                'to_utc' => $sanitizer->time($to),
            ],
            'time_format' => '時刻（*_at）はすべて UTC（末尾 Z）。entry_date は日付だけ（会社の暦どおり）',
            'generated_at' => $sanitizer->time(CarbonImmutable::now()),
            'files' => array_map(fn (string $name): array => [
                'rows' => (int) match ($name) {
                    'ai_runs.jsonl' => $counts['ai_runs'],
                    'ai_corrections.jsonl' => $counts['ai_corrections'],
                    default => $counts['journal_entries'],
                },
                'sha256' => hash('sha256', $files[$name]),
            ], array_combine(array_keys($files), array_keys($files))),
            'links' => [
                ['from' => 'ai_corrections.ai_run_id', 'to' => 'ai_runs.id'],
                ['from' => 'ai_corrections.journal_entry_id', 'to' => 'journal_entries.id'],
                ['from' => 'journal_entries.ai_run_id', 'to' => 'ai_runs.id'],
                ['from' => 'ai_runs.subject_key', 'to' => 'journal_entries.id（subject_kind が JournalEntry のとき）'],
            ],
            'links_outside_period' => [
                'note' => '期間をまたぐ関連。相手が別の月にあるため、この zip の中では結べない件数',
                'ai_corrections.ai_run_id' => $outside($corrections, 'ai_run_id', $runIds),
                'ai_corrections.journal_entry_id' => $outside($corrections, 'journal_entry_id', $entryIds),
                'journal_entries.ai_run_id' => $outside($entries, 'ai_run_id', $runIds),
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
}
