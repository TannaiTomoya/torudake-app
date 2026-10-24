<?php

namespace App\Jobs;

use App\Enums\AiStatus;
use App\Models\AuditLog;
use App\Models\SourceRecord;
use App\Services\Ai\AiException;
use App\Services\Ai\AiTaskRunner;
use App\Services\Ai\Tasks\CheckEntry;
use App\Services\Ai\Tasks\Journalize;
use App\Services\Ai\Tasks\ReadReceipt;
use App\Services\AiDraftBuilder;
use App\Services\ConcernRules;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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
    ): void {
        $source = SourceRecord::query()->with(['company', 'evidence'])->find($this->sourceRecordId);

        if ($source === null || $source->company === null || $source->evidence === null) {
            return;
        }

        if ($source->ai_status === AiStatus::Done) {
            return;
        }

        $company = $source->company;
        $evidence = $source->evidence;

        try {
            $runner->assertWithinDailyLimit($company);

            $this->stage($source, AiStatus::Reading, '読み取り中');

            if ($evidence->storage_key === null) {
                throw new AiException('写真がもうありません。');
            }

            $contents = Storage::disk((string) config('torudake.evidence.disk'))->get($evidence->storage_key);

            if ($contents === null) {
                throw new AiException('写真を読めませんでした。');
            }

            $reading = $readReceipt->run($company, $source, $evidence->mime, base64_encode($contents))->output;

            $evidence->forceFill([
                'extracted_text' => is_string($reading['full_text'] ?? null) ? $reading['full_text'] : null,
            ])->save();

            $source->forceFill([
                'vendor' => is_string($reading['vendor'] ?? null) ? mb_substr($reading['vendor'], 0, 100) : null,
                'amount_yen' => is_int($reading['total_yen'] ?? null) ? $reading['total_yen'] : null,
                'occurred_on' => is_string($reading['date'] ?? null) ? $reading['date'] : null,
                'ai_stage' => '合計',
            ])->save();

            $this->stage($source, AiStatus::Reading, '分類');

            $draft = $journalize->run($company, $source, $reading);

            $entry = $draftBuilder->build($company, $source->id, $reading, $draft->output, $draft->run);

            if ($entry !== null) {
                $amountYen = $entry->amountYen();
                $check = [];

                if ($amountYen !== null) {
                    $this->stage($source, AiStatus::Reading, '仕訳');
                    $check = $checkEntry->run($company, $entry)->output;
                }

                $aiCheck = [
                    ...$check,
                    'amount_missing' => $amountYen === null,
                    'payment_method' => $reading['payment_method'] ?? null,
                    'remembered' => ($draft->output['remembered'] ?? false) === true,
                    'reason' => $draft->output['reason'] ?? null,
                    'confidence' => $draft->output['confidence'] ?? null,
                    'reading_confidence' => $reading['confidence'] ?? null,
                ];

                $entry->forceFill([
                    'ai_check' => $aiCheck,
                    'concern_flags' => $concernRules->for($company, $amountYen, $aiCheck, false, $amountYen === null),
                ])->save();
            }

            $source->refresh()->forceFill([
                'ai_status' => AiStatus::Done,
                'ai_stage' => '仕訳',
                'ai_error' => null,
            ])->save();

            AuditLog::record(
                user: null,
                action: 'evidence.ai_read',
                subject: $source,
                after: ['journal_entry_id' => $entry?->id],
                company: $company,
            );
        } catch (Throwable $e) {
            $source->refresh()->forceFill([
                'ai_status' => AiStatus::Failed,
                'ai_error' => mb_substr($e instanceof AiException ? $e->getMessage() : 'AIの処理に失敗しました。', 0, 200),
            ])->save();

            AuditLog::record(
                user: null,
                action: 'evidence.ai_failed',
                subject: $source,
                after: ['error' => $source->ai_error],
                company: $company,
            );

            if (! $e instanceof AiException) {
                report($e);
            }
        }
    }

    private function stage(SourceRecord $source, AiStatus $status, string $stage): void
    {
        $source->forceFill(['ai_status' => $status, 'ai_stage' => $stage])->save();
    }
}
