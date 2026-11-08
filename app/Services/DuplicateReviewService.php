<?php

namespace App\Services;

use App\Enums\DuplicateDecision;
use App\Enums\SourceStatus;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\DuplicateReview;
use App\Models\User;
use App\Support\DuplicateCandidateMatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 二重計上の候補に対する経理の判断を記録する（別の取引・重複なので今回を対象外・保留）。
 * 判断の直前にもう一度照合し、候補が変わっていたら記録しない。確定済みは取り消さない。
 * 「対象外」は既存の IgnoreSourceService をそのまま使う（確定した原資料は対象外にできない）。
 */
class DuplicateReviewService
{
    public function __construct(
        private readonly DuplicateCandidateMatcher $matcher,
        private readonly IgnoreSourceService $ignoreSource,
    ) {}

    public function decide(Company $company, User $user, string $sourceId, string $candidateEntryId, DuplicateDecision $decision, string $reason): DuplicateReview
    {
        $reason = trim($reason);

        if ($decision->requiresReason() && ($reason === '' || mb_strlen($reason) > 200)) {
            throw ValidationException::withMessages(['duplicateReason' => '理由を200文字までで入れてください。']);
        }

        if (mb_strlen($reason) > 200) {
            throw ValidationException::withMessages(['duplicateReason' => '理由は200文字までです。']);
        }

        return DB::transaction(function () use ($company, $user, $sourceId, $candidateEntryId, $decision, $reason): DuplicateReview {
            $source = $company->sourceRecords()->lockForUpdate()->findOrFail($sourceId);
            // 他社・存在しない候補は404
            $company->journalEntries()->findOrFail($candidateEntryId);

            if ($source->status === SourceStatus::Confirmed || $source->status === SourceStatus::Ignored) {
                throw ValidationException::withMessages(['duplicateReason' => '確定した原資料・対象外の原資料では、判断を記録できません。']);
            }

            $candidate = $this->matcher->forSource($company, $source)->find($candidateEntryId);

            if ($candidate === null) {
                throw ValidationException::withMessages(['duplicateReason' => '候補が変わりました。画面を開き直して、もう一度見てください。']);
            }

            // 二重クリック：同じ値で同じ判断がすでにあれば、増やさない
            if ($candidate->review !== null && $candidate->review->decision === $decision && $decision !== DuplicateDecision::Duplicate) {
                return $candidate->review;
            }

            $review = new DuplicateReview;
            $review->forceFill([
                'company_id' => $company->id,
                'source_record_id' => $source->id,
                'target_entry_id' => $source->journal_entry_id,
                'candidate_entry_id' => $candidate->entryId,
                'decision' => $decision,
                'strength' => $candidate->strength,
                'reasons' => $candidate->reasons,
                'reason' => $reason === '' ? null : $reason,
                'target_fingerprint' => $candidate->targetFingerprint,
                'candidate_fingerprint' => $candidate->candidateFingerprint,
                'decided_by' => $user->id,
                'decided_at' => now(),
            ])->save();

            AuditLog::record(
                user: $user,
                action: 'duplicate.decide',
                subject: $source,
                after: ['candidate_entry_id' => $candidate->entryId, 'decision' => $decision->value, 'strength' => $candidate->strength->value, 'reason' => $reason],
                company: $company,
            );

            if ($decision === DuplicateDecision::Duplicate) {
                $this->ignoreSource->ignore($company, $user, $source->id, mb_substr('重複：'.$reason, 0, 200));
            }

            return $review;
        });
    }
}
