<?php

namespace App\Console\Commands;

use App\Services\BackupManifest;
use Illuminate\Console\Command;

class BackupManifestCommand extends Command
{
    protected $signature = 'torudake:backup-manifest';

    protected $description = 'バックアップの控え（件数と貸借の合計だけ）を JSON で出す。復元したあとに比べるために使う';

    public function handle(BackupManifest $manifest): int
    {
        $this->line((string) json_encode($manifest->build(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
