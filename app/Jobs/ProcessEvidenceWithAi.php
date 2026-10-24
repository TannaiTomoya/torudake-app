<?php

namespace App\Jobs;

use App\Enums\AiStatus;
use App\Enums\SourceStatus;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\SourceRecord;
use App\Services\Ai\AiException;
use App\Services\Ai\AiTaskRunner;
use App\Services\Ai\Tasks\CheckEntry;
use App\Services\Ai\Tasks\Journalize;
use App\Services\Ai\Tasks\ReadReceipt;
use App\Services\AiDraftBuilder;
use App\Services\ConcernRules;
use App\Services\FailEvidenceAi;
use App\Support\ReadingChecks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * 送った直後に裏で L1 読み取り → L2 仕訳 → L3 検算 を行い、下書きを作る。確定は経理の保存だけ。
 */
class ProcessEvidenceWithAi implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public string $sourceRecordId) {}

    public function handle(
        AiTaskRunner $runner,
        ReadReceipt $readReceipt,
        Journalize $journalize,
        CheckEntry $checkEntry,
        AiDraftBuilder $draftBuilder,
        ConcernRules $concernRules,
        FailEvidenceAi $failEvidenceAi,
    ): void {
        $source = SourceRecord::query()->with(['company', 'evidence'])->find($this->sourceRecordId);

        if ($source === null || $source->company === null || $source->evidence === null) {
            return;
        }

        if ($source->ai_status === AiStatus::Done || $source->ai_status === AiStatus::Failed || $source->status !== SourceStatus::New) {
            return;
        }

        $company = $source->company;
        $evidence = $source->evidence;

        try {
            $runner->assertWithinDailyLimit($company);

            if (! $this->stage($source->id, AiStatus::Reading, '読み取り中')) {
                return;
            }

            if ($evidence->storage_key === null) {
                throw new AiException('写真がもうありません。');
            }

            $contents = Storage::disk((string) config('torudake.evidence.disk'))->get($evidence->storage_key);

            if ($contents === null) {
                throw new AiException('写真を読めませんでした。');
            }

            $reading = $readReceipt->run($company, $source, $evidence->mime, base64_encode($contents))->output;

            if (! $this->whileUnconfirmed($source->id, function (SourceRecord $lockedSource) use ($reading): void {
                $lockedSource->evidence?->forceFill([
                    'extracted_text' => is_string($reading['full_text'] ?? null) ? $reading['full_text'] : null,
                ])->save();

                $lockedSource->forceFill([
                    'vendor' => is_string($reading['vendor'] ?? null) ? mb_substr($reading['vendor'], 0, 100) : null,
                    'amount_yen' => is_int($reading['total_yen'] ?? null) ? $reading['total_yen'] : null,
                    'occurred_on' => ReadingChecks::validReceiptDate($reading['date'] ?? null)?->toDateString(),
                    'ai_stage' => '合計',
                ])->save();
            })) {
                return;
            }

            if (! $this->stage($source->id, AiStatus::Reading, '分類')) {
                return;
            }

            $draft = $journalize->run($company, $source, $reading);

            if (! $this->isProcessing($source->id)) {
                return;
            }

            $entry = $draftBuilder->build($company, $source->id, $reading, $draft->output, $draft->run);

            if ($entry === null) {
                return;
            }

            $amountYen = $entry->amountYen();
            $check = [];

            if ($amountYen !== null) {
                if (! $this->stage($source->id, AiStatus::Reading, '仕訳')) {
                    return;
                }

                $check = $checkEntry->run($company, $entry, $evidence->sha256)->output;
            }

            $aiCheck = [
                ...($entry->ai_check ?? []),
                ...$check,
                'amount_missing' => $amountYen === null,
                'payment_method' => $reading['payment_method'] ?? null,
                'remembered' => ($draft->output['remembered'] ?? false) === true,
                'reason' => $draft->output['reason'] ?? null,
                'confidence' => $draft->output['confidence'] ?? null,
                'reading_confidence' => $reading['confidence'] ?? null,
                'addressee' => is_string($reading['addressee'] ?? null) ? mb_substr($reading['addressee'], 0, 100) : null,
                'addressee_kind' => ReadingChecks::addresseeKind(
                    is_string($reading['addressee'] ?? null) ? $reading['addressee'] : null,
                    is_string($reading['addressee_kind'] ?? null) ? $reading['addressee_kind'] : null,
                    $company->name,
                ),
            ];

            if (! $this->whileUnconfirmed($source->id, function (SourceRecord $lockedSource) use ($entry, $aiCheck, $concernRules, $company, $amountYen): void {
                $lockedEntry = $lockedSource->journalEntry;
                if ($lockedEntry === null || $lockedEntry->id !== $entry->id) {
                    return;
                }

                $lockedEntry->forceFill([
                    'ai_check' => $aiCheck,
                    'concern_flags' => $concernRules->for($company, $amountYen, $aiCheck, false, $amountYen === null),
                ])->save();
            })) {
                return;
            }

            if (! $this->whileUnconfirmed($source->id, function (SourceRecord $lockedSource): void {
                $lockedSource->forceFill([
                    'ai_status' => AiStatus::Done,
                    'ai_stage' => '仕訳',
                    'ai_error' => null,
                ])->save();
            })) {
                return;
            }

            AuditLog::record(
                user: null,
                action: 'evidence.ai_read',
                subject: $source,
                after: ['journal_entry_id' => $entry->id],
                company: $company,
            );
        } catch (Throwable $e) {
            $failEvidenceAi->markFailed($company, $source->id, $e);

            if (! $e instanceof AiException) {
                report($e);
            }
        }
    }

    public function failed(Throwable $e): void
    {
        $companyId = SourceRecord::query()->whereKey($this->sourceRecordId)->value('company_id');

        if (is_string($companyId)) {
            $company = Company::query()->find($companyId);
            if ($company !== null) {
                app(FailEvidenceAi::class)->markFailed($company, $this->sourceRecordId, $e);
            }
        }

        report($e);
    }

    private function stage(string $sourceId, AiStatus $status, string $stage): bool
    {
        return $this->whileUnconfirmed($sourceId, function (SourceRecord $source) use ($status, $stage): void {
            $source->forceFill(['ai_status' => $status, 'ai_stage' => $stage])->save();
        });
    }

    /** @param callable(SourceRecord): void $callback */
    private function whileUnconfirmed(string $sourceId, callable $callback): bool
    {
        return DB::transaction(function () use ($sourceId, $callback): bool {
            $source = SourceRecord::query()->lockForUpdate()->find($sourceId);

            if ($source === null
                || ! in_array($source->status, [SourceStatus::New, SourceStatus::Drafted], true)
                || in_array($source->ai_status, [AiStatus::Done, AiStatus::Failed], true)) {
                return false;
            }

            $callback($source->loadMissing('evidence', 'journalEntry'));

            return true;
        });
    }

    /** @phpstan-impure */
    private function isProcessing(string $sourceId): bool
    {
        return SourceRecord::query()
            ->whereKey($sourceId)
            ->whereIn('status', [SourceStatus::New, SourceStatus::Drafted])
            ->where('ai_status', AiStatus::Reading)
            ->exists();
    }
}
