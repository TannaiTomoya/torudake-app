<?php

namespace App\Console\Commands;

use App\Support\Heartbeat;
use Illuminate\Console\Command;

class BackupRecordedCommand extends Command
{
    protected $signature = 'torudake:backup-recorded';

    protected $description = 'サーバーの外へのバックアップが成功した時刻を書く（バックアップのスクリプトが、送り終えて確かめたあとに呼ぶ）';

    public function handle(): int
    {
        Heartbeat::touch(Heartbeat::BACKUP);

        $this->info('バックアップの成功を記録しました。');

        return self::SUCCESS;
    }
}
