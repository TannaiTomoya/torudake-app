<?php

namespace App\Services;

use App\Support\Heartbeat;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 稼働の確認。DB につながるか、定期処理とキューの「生きている印」が新しいか。
 * バックアップを見る設定のときは、サーバーの外へのバックアップが最近成功しているか（#35）。
 * どれが悪いかは公開の応答には出さない（ログにだけ残す）。
 */
class HealthCheck
{
    public function database(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 問題のある項目の名前（database・scheduler・queue・backup）。なければ空。
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        if (! $this->database()) {
            return ['database'];
        }

        foreach ([
            'scheduler' => [Heartbeat::SCHEDULER, (int) config('torudake.health.scheduler_max_age_seconds')],
            'queue' => [Heartbeat::QUEUE, (int) config('torudake.health.queue_max_age_seconds')],
        ] as $label => [$name, $max]) {
            $age = Heartbeat::ageSeconds($name);

            if ($age === null || $age > $max) {
                $problems[] = $label;
            }
        }

        $backupMaxHours = (int) config('torudake.health.backup_max_age_hours');

        if ($backupMaxHours > 0) {
            $age = Heartbeat::ageSeconds(Heartbeat::BACKUP);

            if ($age === null || $age > $backupMaxHours * 3600) {
                $problems[] = 'backup';
            }
        }

        return $problems;
    }
}
