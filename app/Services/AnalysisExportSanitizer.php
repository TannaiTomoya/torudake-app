<?php

namespace App\Services;

use App\Enums\AiTask;
use App\Enums\ConcernFlag;
use App\Enums\CorrectionReason;
use App\Enums\Side;
use App\Models\AiCorrection;
use App\Models\AiRun;
use App\Models\Company;
use App\Models\EntryPostEvent;
use App\Models\FirstConfirmation;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Support\DuplicateCandidateMatcher;
use App\Support\EntryRecommendation;
use Carbon\CarbonInterface;

/**
 * 分析用の書き出し（#31）。**許可した項目だけを、型を確かめて**行にする。
 * 自由文（OCR全文・宛名・摘要・理由・例外メッセージ・kernel の文章・会社が付けた名前）は、どの階層からも写さない。
 * 店・分類・口座・人・原資料・仕訳は、会社ごとに違う仮名のキーにする（会社をまたいで結べない）。
 * 仮名化しただけで匿名化ではない。出力は機密データとして扱う（docs/12-analysis-export-spec.md）。
 */
class AnalysisExportSanitizer
{
    public const SCHEMA_VERSION = 5;

    /** 項目の意味（どの値をどう数えるか）を変えたら上げる。分析側が版をそろえて読むため */
    public const METRIC_VERSION = 'analysis-metrics.v2';

    private const CORRECTION_FIELDS = ['date', 'vendor', 'category', 'credit_account', 'amount'];

    /** @var array<string, string>|null */
    private ?array $categoryIds = null;

    /** @var array<string, string>|null */
    private ?array $bankAccountIds = null;

    public function __construct(private readonly Company $company) {}

    /**
     * 会社の中で安定し、会社をまたいでは結べない仮名のキー。種類の頭文字：
     * r=AI実行 s=原資料 e=仕訳 v=店 c=分類 b=支払い元 u=人 k=会社
     */
    public function key(string $kind, string|int|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $kind.'_'.substr(hash_hmac('sha256', "torudake-analysis.v1|{$kind}|{$this->company->id}|{$value}", (string) config('app.key')), 0, 16);
    }

    public function companyKey(): string
    {
        return (string) $this->key('k', $this->company->id);
    }

    public function vendorKey(?string $vendor): ?string
    {
        $normalized = DuplicateCandidateMatcher::normalizeVendor($vendor);

        return $normalized === '' ? null : $this->key('v', $normalized);
    }

    /**
     * @return array<string, mixed>
     */
    public function run(AiRun $run): array
    {
        [$in, $out] = match ($run->task) {
            AiTask::ReadReceipt => $this->readReceipt($run),
            AiTask::Journalize => $this->journalize($run),
            AiTask::CheckEntry => $this->checkEntry($run),
            default => [null, null],
        };

        return [
            'id' => $this->key('r', $run->id),
            'task' => $run->task->value,
            'subject_kind' => $this->token($run->subject_type === null ? null : class_basename($run->subject_type), 40),
            'subject_key' => $this->subjectKey($run),
            'model' => $this->token($run->model, 80),
            'prompt_version' => $this->token($run->prompt_version, 80),
            'driver' => $this->token($run->driver, 40),
            'valid' => $run->valid,
            'has_error' => $run->error !== null && $run->error !== '',
            'input_tokens' => $run->input_tokens,
            'output_tokens' => $run->output_tokens,
            'latency_ms' => $run->latency_ms,
            'created_at' => $this->time($run->created_at),
            'input_features' => $in,
            'output_features' => $out,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function correction(AiCorrection $correction): array
    {
        $field = in_array($correction->field, self::CORRECTION_FIELDS, true) ? $correction->field : 'other';

        return [
            'id' => $this->key('x', $correction->id),
            'ai_run_id' => $this->key('r', $correction->ai_run_id),
            'journal_entry_id' => $this->key('e', $correction->journal_entry_id),
            'field' => $field,
            'before' => $this->correctionValue($field, $correction->before),
            'after' => $this->correctionValue($field, $correction->after),
            'reason' => $correction->reason->value,
            'kernel_kind' => $this->kernelKind($field),
            'vendor_key' => $this->vendorKey($correction->vendor),
            'corrected_by' => $this->key('u', $correction->corrected_by),
            'created_at' => $this->time($correction->created_at),
        ];
    }

    /**
     * @param  array<string, true>  $correctedEntryIds
     * @return array<string, mixed>
     */
    public function entry(JournalEntry $entry, array $correctedEntryIds): array
    {
        $flags = [];
        foreach ($entry->concern_flags ?? [] as $flag) {
            if (ConcernFlag::tryFrom($flag) !== null) {
                $flags[] = $flag;
            }
        }

        return [
            'id' => $this->key('e', $entry->id),
            'entry_date' => $entry->entry_date->toDateString(),
            'status' => $entry->status->value,
            'vendor_key' => $this->vendorKey($entry->vendor),
            'category_key' => $this->key('c', $entry->category_id),
            'payment_key' => $this->key('b', $entry->bank_account_id),
            'amount_yen' => $entry->amountYen(),
            'lines' => $entry->lines->sortBy('line_no')->map(fn (JournalLine $line): array => [
                'side' => $line->side->value,
                'account_code' => $this->code($line->account?->code),
                'amount_yen' => $line->amount_yen,
            ])->values()->all(),
            'ai_run_id' => $this->key('r', $entry->ai_run_id),
            'origin' => match (true) {
                $entry->ai_run_id === null => 'manual',
                isset($correctedEntryIds[$entry->id]) => 'corrected',
                default => 'ai_unchanged',
            },
            'concern_flags' => $flags,
            'confirmed_by' => $this->key('u', $entry->confirmed_by),
            'confirmed_at' => $this->time($entry->confirmed_at),
        ];
    }

    /**
     * 初回確定の記録（#32）。値は列挙・真偽・件数・仮名のキー・時刻だけ。画面の数字と同じ定義（FirstConfirmationMetrics）で数えられる。
     *
     * @param  iterable<EntryPostEvent>  $events  その仕訳の、確定後の出来事
     * @return array<string, mixed>
     */
    public function firstConfirmation(FirstConfirmation $row, iterable $events): array
    {
        $fields = ['date', 'vendor', 'category', 'credit_account', 'amount'];
        $origins = ['read', 'defaulted', 'missing', 'unknown'];
        $draft = $row->draft_origin;

        $methods = [];
        foreach (['date', 'vendor', 'amount'] as $field) {
            $method = $this->enum($row->reading_methods[$field] ?? null, ['confirmed', 'changed', 'entered', 'saved_as_is']);
            if ($method !== null) {
                $methods[$field] = $method;
            }
        }

        $post = [];
        foreach ($events as $event) {
            $post[] = [
                'kind' => $this->enum($event->kind, [EntryPostEvent::KIND_RETURNED, EntryPostEvent::KIND_RECONFIRMED]),
                'changed_fields' => $this->fieldList($event->changed_fields, [...$fields, 'description']),
                'occurred_at' => $this->time($event->occurred_at),
            ];
        }

        return [
            'id' => $this->key('f', $row->id),
            'journal_entry_id' => $this->key('e', $row->journal_entry_id),
            'ai_run_id' => $this->key('r', $row->ai_run_id),
            'kind' => $this->enum($row->kind, [FirstConfirmation::KIND_AI, FirstConfirmation::KIND_MANUAL]),
            'evaluation_class' => $this->enum($row->evaluation_class, [FirstConfirmation::CLASS_REAL, FirstConfirmation::CLASS_DEMO]),
            'driver' => $this->token($row->driver, 40),
            'prompt_version' => $this->token($row->prompt_version, 80),
            'required_complete' => $row->required_complete,
            'unchanged' => $row->isUnchanged(),
            'changed_fields' => $this->fieldList($row->changed_fields, $fields),
            'filled_fields' => $this->fieldList($row->filled_fields, $fields),
            'description_changed' => $row->description_changed,
            'draft_origin' => [
                'date' => $this->enum($draft['date'] ?? null, $origins),
                'vendor' => $this->enum($draft['vendor'] ?? null, $origins),
                'amount' => $this->enum($draft['amount'] ?? null, $origins),
                'category' => $this->enum($draft['category'] ?? null, ['ai', 'none']),
                'payment' => $this->enum($draft['payment'] ?? null, ['history', 'invoice_rule', 'ai', 'default', 'unknown', 'none']),
            ],
            'reading_methods' => $methods,
            'duplicate' => [
                'shown' => $this->int($row->duplicate['shown'] ?? null),
                'concerning' => $this->int($row->duplicate['concerning'] ?? null),
                'decided' => $this->int($row->duplicate['decided'] ?? null),
            ],
            'recommendation' => $this->recommendation($row),
            'reason' => $this->enum($row->reason, array_map(fn (CorrectionReason $reason): string => $reason->value, CorrectionReason::cases())),
            'first_confirmed_by' => $this->key('u', $row->first_confirmed_by),
            'first_confirmed_at' => $this->time($row->first_confirmed_at),
            'post_events' => $post,
        ];
    }

    /**
     * 履歴からの候補の扱い（#54）。分類・支払い元・店名は入れず、状態・理由の型・件数・扱いだけ。
     *
     * @return array<string, mixed>|null
     */
    private function recommendation(FirstConfirmation $row): ?array
    {
        $rec = $row->recommendation;
        if ($rec === null) {
            return null;
        }

        return [
            'status' => $this->enum($rec['status'] ?? null, ['confident', 'hold', 'none']),
            'reasons' => $this->fieldList($rec['reasons'] ?? null, array_keys(EntryRecommendation::REASON_LABELS)),
            'usable_count' => $this->int($rec['usable_count'] ?? null),
            'candidate_count' => $this->int($rec['candidate_count'] ?? null),
            'applied' => ($rec['applied'] ?? false) === true,
            'expired' => ($rec['expired'] ?? false) === true,
            'outcome' => $this->enum($rec['outcome'] ?? null, ['adopted', 'rejected', 'held', 'no_candidate']),
        ];
    }

    /**
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private function fieldList(mixed $value, array $allowed): array
    {
        $list = [];
        foreach (is_array($value) ? $value : [] as $item) {
            if (is_string($item) && in_array($item, $allowed, true) && ! in_array($item, $list, true)) {
                $list[] = $item;
            }
        }

        return $list;
    }

    public function time(?CarbonInterface $time): ?string
    {
        return $time?->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null}
     */
    private function readReceipt(AiRun $run): array
    {
        $out = $run->output;

        return [
            ['has_note' => is_string($run->input['note'] ?? null) && $run->input['note'] !== ''],
            $out === null ? null : [
                'date' => $this->date($out['date'] ?? null),
                'vendor_key' => $this->vendorKey(is_string($out['vendor'] ?? null) ? $out['vendor'] : null),
                'total_yen' => $this->int($out['total_yen'] ?? null),
                'amount_missing' => ($out['amount_missing'] ?? null) === true,
                'addressee_kind' => $this->enum($out['addressee_kind'] ?? null, ['own', 'other_company', 'person', 'none']),
                'payment_method' => $this->enum($out['payment_method'] ?? null, ['cash', 'card', 'invoice_later', 'unknown']),
                'tax_breakdown' => $this->taxBreakdown($out['tax_breakdown'] ?? null),
                'has_registration_number' => is_string($out['registration_number'] ?? null) && $out['registration_number'] !== '',
                'full_text_length' => is_string($out['full_text'] ?? null) ? mb_strlen($out['full_text']) : null,
                'confidence' => [
                    'date' => $this->ratio(data_get($out, 'confidence.date')),
                    'vendor' => $this->ratio(data_get($out, 'confidence.vendor')),
                    'total' => $this->ratio(data_get($out, 'confidence.total')),
                ],
            ],
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null}
     */
    private function journalize(AiRun $run): array
    {
        $out = $run->output;

        return [
            [
                'corrections_in_prompt' => is_array($run->input['corrections'] ?? null) ? count($run->input['corrections']) : null,
            ],
            $out === null ? null : [
                'category_key' => is_string($out['category'] ?? null) ? $this->key('c', $this->categoryIds()[$out['category']] ?? null) : null,
                'payment_key' => is_string($out['bank_account'] ?? null) ? $this->key('b', $this->bankAccountIds()[$out['bank_account']] ?? null) : null,
                'vendor_key' => $this->vendorKey(is_string($out['vendor'] ?? null) ? $out['vendor'] : null),
                'confidence' => $this->ratio($out['confidence'] ?? null),
                'remembered' => ($out['remembered'] ?? null) === true,
            ],
        ];
    }

    /**
     * L3 の評価に要る分だけ。同じ実行の行（side・金額）と、型を確かめた合計・一致・疑い。
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null}
     */
    private function checkEntry(AiRun $run): array
    {
        $out = $run->output;
        $lines = [];
        foreach (is_array($run->input['lines'] ?? null) ? $run->input['lines'] : [] as $line) {
            if (is_array($line)) {
                $lines[] = [
                    'side' => $this->enum($line['side'] ?? null, [Side::Debit->value, Side::Credit->value]),
                    'amount_yen' => $this->int($line['amount_yen'] ?? null),
                ];
            }
        }

        return [
            [
                'entry_date' => $this->date($run->input['entry_date'] ?? null),
                'lines' => array_slice($lines, 0, 50),
                'recent_entries_count' => is_array($run->input['recent_entries'] ?? null) ? count($run->input['recent_entries']) : null,
            ],
            $out === null ? null : [
                'debit_total' => $this->int($out['debit_total'] ?? null),
                'credit_total' => $this->int($out['credit_total'] ?? null),
                // 答えがない・型が違うときは null（false にすると「不一致と答えた」と区別できない）
                'balanced' => is_bool($out['balanced'] ?? null) ? $out['balanced'] : null,
                'duplicate_suspected' => is_bool($out['duplicate_suspected'] ?? null) ? $out['duplicate_suspected'] : null,
                'private_suspected' => is_bool($out['private_suspected'] ?? null) ? $out['private_suspected'] : null,
                'concerns_count' => is_array($out['concerns'] ?? null) ? count($out['concerns']) : null,
            ],
        ];
    }

    private function subjectKey(AiRun $run): ?string
    {
        $kind = match ($run->subject_type === null ? null : class_basename($run->subject_type)) {
            'SourceRecord' => 's',
            'JournalEntry' => 'e',
            default => null,
        };

        return $kind === null ? null : $this->key($kind, $run->subject_id);
    }

    /**
     * 直した前後の値。欄ごとに型を確かめた値だけにして、文字はそのまま出さない（店・分類・口座は仮名のキー）。
     */
    private function correctionValue(string $field, ?string $value): int|string|null
    {
        if ($value === null) {
            return null;
        }

        return match ($field) {
            'date' => $this->date($value),
            'amount' => preg_match('/^-?\d{1,13}$/', $value) === 1 ? (int) $value : null,
            'vendor' => $this->vendorKey($value),
            'category' => $this->key('c', $this->categoryIds()[$value] ?? null),
            'credit_account' => $this->key('b', $this->bankAccountIds()[$value] ?? null),
            default => null,
        };
    }

    /**
     * kernel の文章は出さず、欄から決まる型だけを出す（店名を含む文章を持ち出さない）。
     */
    private function kernelKind(string $field): string
    {
        return match ($field) {
            'category' => 'vendor_to_category',
            'credit_account' => 'vendor_to_payment',
            'amount' => 'amount_change',
            'date' => 'date_change',
            'vendor' => 'vendor_change',
            default => 'other',
        };
    }

    /**
     * @return list<array{rate: int|null, amount_yen: int|null}>
     */
    private function taxBreakdown(mixed $value): array
    {
        $rows = [];
        foreach (is_array($value) ? array_slice($value, 0, 10) : [] as $row) {
            if (is_array($row)) {
                $rows[] = ['rate' => $this->int($row['rate'] ?? null), 'amount_yen' => $this->int($row['amount_yen'] ?? null)];
            }
        }

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    private function categoryIds(): array
    {
        return $this->categoryIds ??= $this->company->categories()->orderBy('created_at')->pluck('id', 'name')->all();
    }

    /**
     * @return array<string, string>
     */
    private function bankAccountIds(): array
    {
        return $this->bankAccountIds ??= $this->company->bankAccounts()->orderBy('created_at')->pluck('id', 'name')->all();
    }

    private function int(mixed $value): ?int
    {
        return is_int($value) && abs($value) <= 9_999_999_999_999 ? $value : null;
    }

    private function ratio(mixed $value): ?float
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) ? round(min(1.0, max(0.0, (float) $value)), 4) : null;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function enum(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
    }

    /** モデル名・版・ドライバなど、記号だけで書かれた短い名前 */
    private function token(?string $value, int $max): ?string
    {
        return $value !== null && preg_match('/^[A-Za-z0-9._:\-]{1,'.$max.'}$/', $value) === 1 ? $value : null;
    }

    /** 勘定科目コード（英数字とハイフンの短いものだけ） */
    private function code(?string $value): ?string
    {
        return $this->token($value, 12);
    }
}
