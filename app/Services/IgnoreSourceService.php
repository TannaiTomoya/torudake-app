<?php

namespace App\Services;

use App\Enums\EntryStatus;
use App\Enums\SourceStatus;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\SourceRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 原資料を「対象外」にする。原資料は消さず、つながった下書きだけを消す。
 */
class IgnoreSourceService
{
    public function ignore(Company $company, User $user, string $sourceId, string $reason): SourceRecord
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw ValidationException::withMessages(['ignoreReason' => '対象外にする理由を200文字までで入れてください。']);
        }

        return DB::transaction(function () use ($company, $user, $sourceId, $reason): SourceRecord {
            /** @var SourceRecord $source */
            $source = $company->sourceRecords()->lockForUpdate()->findOrFail($sourceId);

            if ($source->status === SourceStatus::Ignored) {
                return $source;
            }

            if ($source->status === SourceStatus::Confirmed) {
                throw ValidationException::withMessages(['ignoreReason' => '確定した原資料は対象外にできません。']);
            }

            $before = ['status' => $source->status->value, 'journal_entry_id' => $source->journal_entry_id];

            $entry = $source->journal_entry_id !== null
                ? $company->journalEntries()->lockForUpdate()->find($source->journal_entry_id)
                : null;

            $source->forceFill([
                'status' => SourceStatus::Ignored,
                'journal_entry_id' => null,
                'ignored_reason' => $reason,
                'ignored_by' => $user->id,
            ])->save();

            if ($entry !== null && $entry->status === EntryStatus::Draft) {
                $entry->delete();
            }

            AuditLog::record(
                user: $user,
                action: 'source.ignore',
                subject: $source,
                before: $before,
                after: ['status' => SourceStatus::Ignored->value, 'reason' => $reason],
                company: $company,
            );

            return $source;
        });
    }
}
