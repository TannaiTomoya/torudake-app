<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * 「定期処理」と「キューの常駐」が今も動いているかの印。毎分、動いた時刻を書き込む。
 * 秘密や件数は入れない。時刻だけ。
 */
final class Heartbeat
{
    public const SCHEDULER = 'scheduler';

    public const QUEUE = 'queue';

    /** サーバーの外へのバックアップが最後に成功した時刻（#35）。毎分ではなく、バックアップのたびに書く */
    public const BACKUP = 'backup';

    public static function touch(string $name): void
    {
        Cache::forever(self::key($name), now()->getTimestamp());
    }

    /**
     * 最後に動いてからの秒数。一度も動いていなければ null。
     */
    public static function ageSeconds(string $name): ?int
    {
        $at = Cache::get(self::key($name));

        return is_int($at) ? max(0, now()->getTimestamp() - $at) : null;
    }

    private static function key(string $name): string
    {
        return "heartbeat:{$name}";
    }
}
