<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Evidence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * 保存期間（purge_after）を過ぎた写真のファイルを消す。行・読み取った全文・SHA-256 は残す。
 */
class PurgeExpiredEvidence
{
    public function run(): int
    {
        $purged = 0;

        Evidence::query()
            ->whereNull('purged_at')
            ->where('purge_after', '<=', now())
            ->select('id')
            ->chunkById(100, function ($chunk) use (&$purged): void {
                foreach ($chunk as $row) {
                    if ($this->purge((string) $row->id)) {
                        $purged++;
                    }
                }
            });

        return $purged;
    }

    private function purge(string $evidenceId): bool
    {
        return DB::transaction(function () use ($evidenceId): bool {
            $evidence = Evidence::query()->lockForUpdate()->find($evidenceId);

            if ($evidence === null || $evidence->purged_at !== null || $evidence->purge_after->isFuture()) {
                return false;
            }

            if ($evidence->storage_key !== null) {
                Storage::disk((string) config('torudake.evidence.disk'))->delete($evidence->storage_key);
            }

            $evidence->forceFill(['storage_key' => null, 'purged_at' => now()])->save();

            AuditLog::record(
                null,
                'evidence.purge',
                $evidence,
                ['purge_after' => $evidence->purge_after->toIso8601String()],
                ['purged_at' => $evidence->purged_at?->toIso8601String()],
            );

            return true;
        });
    }
}
