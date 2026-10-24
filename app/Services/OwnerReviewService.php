<?php

namespace App\Services;

use App\Enums\EntryStatus;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * 社長が事後に見る（03 §4.4）。確定した仕訳を理由を付けて経理に戻す・気になる仕訳に「見た」の印を付ける。
 * エラーのキーは社長の画面のプロパティ名に合わせている。
 */
class OwnerReviewService
{
    public function __construct(private readonly WorkbookService $workbook) {}

    public function returnToBookkeeper(Company $company, User $user, string $entryId, string $reason): JournalEntry
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw ValidationException::withMessages(['returnReason' => '戻す理由を200文字までで入れてください。']);
        }

        $entry = DB::transaction(function () use ($company, $user, $entryId, $reason): JournalEntry {
            /** @var JournalEntry $entry */
            $entry = $company->journalEntries()->lockForUpdate()->findOrFail($entryId);

            if ($entry->status === EntryStatus::Returned) {
                return $entry;
            }

            if ($entry->status !== EntryStatus::Confirmed) {
                throw ValidationException::withMessages(['returnReason' => '確定した仕訳だけを戻せます。']);
            }

            $entry->forceFill([
                'status' => EntryStatus::Returned,
                'returned_by' => $user->id,
                'returned_at' => now(),
                'returned_reason' => $reason,
            ])->save();

            AuditLog::record(
                user: $user,
                action: 'entry.return',
                subject: $entry,
                before: ['status' => EntryStatus::Confirmed->value],
                after: ['status' => EntryStatus::Returned->value, 'reason' => $reason],
                company: $company,
            );

            return $entry;
        });

        try {
            $this->workbook->upsertRow($company, $entry->id);
        } catch (Throwable $e) {
            // 戻しは済んでいる。行の状態は、経理が直して保存したときに書き換わる。
            report($e);
        }

        return $entry->refresh();
    }

    public function markSeen(Company $company, string $entryId): JournalEntry
    {
        return DB::transaction(function () use ($company, $entryId): JournalEntry {
            /** @var JournalEntry $entry */
            $entry = $company->journalEntries()->lockForUpdate()->findOrFail($entryId);

            if ($entry->owner_seen_at === null) {
                $entry->forceFill(['owner_seen_at' => now()])->save();
            }

            return $entry;
        });
    }
}
