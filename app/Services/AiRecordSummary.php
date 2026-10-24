<?php

namespace App\Services;

use App\Enums\AiTask;
use App\Enums\CorrectionReason;
use App\Enums\EntryStatus;
use App\Models\AiRun;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * AIの記録の数字（1か月分）。仕訳は確定した日、AIの実行と直しは記録した日でその月に入れる。
 */
class AiRecordSummary
{
    public const FIELD_LABELS = [
        'date' => '日付',
        'vendor' => '店名',
        'category' => '分類',
        'credit_account' => '支払い元',
        'amount' => '金額',
    ];

    /**
     * @return array{
     *     ai_entries: int,
     *     unchanged: int,
     *     unchanged_pct: int|null,
     *     corrections: int,
     *     top_reason: array{label: string, count: int}|null,
     *     reasons: list<array{label: string, count: int}>,
     *     fields: list<array{label: string, count: int}>,
     *     kernels: list<array{kernel: string, label: string, count: int}>,
     *     tasks: list<array{label: string, runs: int, failed: int, avg_seconds: float, tokens: int}>,
     *     runs: int,
     *     recent: Collection<int, AiRun>,
     * }
     */
    public function for(Company $company, CarbonImmutable $month): array
    {
        $from = $month->startOfMonth();
        $to = $from->addMonth();

        $entries = fn () => $company->journalEntries()
            ->whereNotNull('ai_run_id')
            ->whereIn('status', [EntryStatus::Confirmed, EntryStatus::Returned])
            ->where('confirmed_at', '>=', $from)
            ->where('confirmed_at', '<', $to);

        $aiEntries = $entries()->count();
        $unchanged = $entries()
            ->whereNotIn('id', $company->aiCorrections()->whereNotNull('journal_entry_id')->select('journal_entry_id'))
            ->count();

        $corrections = fn () => $company->aiCorrections()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to);

        $reasons = $corrections()->toBase()
            ->selectRaw('reason, count(*) as total')
            ->groupBy('reason')
            ->orderByDesc('total')
            ->get()
            ->map(fn (object $row): array => [
                'label' => CorrectionReason::tryFrom((string) $row->reason)?->label() ?? (string) $row->reason,
                'count' => (int) $row->total,
            ])->values()->all();

        $fields = $corrections()->toBase()
            ->selectRaw('field, count(*) as total')
            ->groupBy('field')
            ->orderByDesc('total')
            ->get()
            ->map(fn (object $row): array => [
                'label' => self::FIELD_LABELS[(string) $row->field] ?? (string) $row->field,
                'count' => (int) $row->total,
            ])->values()->all();

        $kernels = $corrections()->toBase()
            ->whereNotNull('kernel')
            ->selectRaw('kernel, count(*) as total')
            ->groupBy('kernel')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn (object $row): array => [
                'kernel' => (string) $row->kernel,
                'label' => self::kernelLabel((string) $row->kernel),
                'count' => (int) $row->total,
            ])
            ->values()->all();

        $runs = fn () => $company->aiRuns()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to);

        $tasks = $runs()->toBase()
            ->selectRaw('task, count(*) as total, sum(case when valid then 0 else 1 end) as failed, avg(latency_ms) as avg_ms, sum(input_tokens + output_tokens) as tokens')
            ->groupBy('task')
            ->orderByDesc('total')
            ->get()
            ->map(fn (object $row): array => [
                'label' => AiTask::tryFrom((string) $row->task)?->label() ?? (string) $row->task,
                'runs' => (int) $row->total,
                'failed' => (int) $row->failed,
                'avg_seconds' => round(((float) $row->avg_ms) / 1000, 1),
                'tokens' => (int) $row->tokens,
            ])->values()->all();

        return [
            'ai_entries' => $aiEntries,
            'unchanged' => $unchanged,
            'unchanged_pct' => $aiEntries === 0 ? null : (int) round($unchanged / $aiEntries * 100),
            'corrections' => $corrections()->count(),
            'top_reason' => $reasons[0] ?? null,
            'reasons' => array_values($reasons),
            'fields' => array_values($fields),
            'kernels' => array_values($kernels),
            'tasks' => array_values($tasks),
            'runs' => array_sum(array_column($tasks, 'runs')),
            'recent' => $runs()->latest('created_at')->limit(10)->get(),
        ];
    }

    /**
     * kernel（ConfirmEntryService が作る機械向けの文字列）を、画面に出す日本語にする。
     */
    public static function kernelLabel(string $kernel): string
    {
        if (preg_match('/^vendor:(.*) → (category|payment):(.*)$/u', $kernel, $m) === 1) {
            return $m[1].' → '.($m[2] === 'category' ? '分類' : '支払い元').'を『'.$m[3].'』に';
        }

        if (preg_match('/^(\w+):(.*) → (.*)$/u', $kernel, $m) === 1) {
            return (self::FIELD_LABELS[$m[1]] ?? $m[1]).'を『'.$m[2].'』から『'.$m[3].'』に';
        }

        return $kernel;
    }
}
