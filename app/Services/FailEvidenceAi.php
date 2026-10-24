<?php

namespace App\Services;

use App\Enums\AiStatus;
use App\Enums\SourceStatus;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\SourceRecord;
use App\Services\Ai\AiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * AI ジョブの最終失敗・停滞を、会社スコープと行ロックの中で失敗状態へ収束させる。
 */
class FailEvidenceAi
{
    public const STALE_AFTER_MINUTES = 10;

    public function markFailed(Company $company, string $sourceId, Throwable $exception, ?CarbonImmutable $staleBefore = null): bool
    {
        return DB::transaction(function () use ($company, $sourceId, $exception, $staleBefore): bool {
            /** @var SourceRecord|null $source */
            $source = $company->sourceRecords()->lockForUpdate()->find($sourceId);

            if ($source === null
                || ! in_array($source->status, [SourceStatus::New, SourceStatus::Drafted], true)
                || ! in_array($source->ai_status, [AiStatus::Waiting, AiStatus::Reading], true)
                || ($staleBefore !== null && $source->updated_at?->gt($staleBefore))) {
                return false;
            }

            $message = $exception instanceof AiException
                ? $exception->getMessage()
                : 'AIの処理に失敗しました。';
            $message = mb_substr($message, 0, 200);

            $source->forceFill([
                'ai_status' => AiStatus::Failed,
                'ai_error' => $message,
            ])->save();

            AuditLog::record(
                user: null,
                action: 'evidence.ai_failed',
                subject: $source,
                after: ['error' => $message],
                company: $company,
            );

            return true;
        });
    }

    public function failStale(): int
    {
        $cutoff = now()->subMinutes(self::STALE_AFTER_MINUTES);
        $failedCount = 0;

        Company::query()->orderBy('id')->chunkById(100, function ($companies) use ($cutoff, &$failedCount): void {
            foreach ($companies as $company) {
                $company->sourceRecords()
                    ->whereIn('status', [SourceStatus::New, SourceStatus::Drafted])
                    ->whereIn('ai_status', [AiStatus::Waiting, AiStatus::Reading])
                    ->where('updated_at', '<=', $cutoff)
                    ->orderBy('id')
                    ->chunkById(100, function ($sources) use ($company, $cutoff, &$failedCount): void {
                        foreach ($sources as $source) {
                            $failedCount += (int) $this->markFailed(
                                $company,
                                $source->id,
                                new AiException('AIの処理が10分以上止まったため、手入力に切り替えてください。'),
                                $cutoff,
                            );
                        }
                    });
            }
        });

        return $failedCount;
    }
}
