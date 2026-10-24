<?php

namespace App\Console\Commands;

use App\Services\PurgeExpiredEvidence;
use Illuminate\Console\Command;

class PurgeExpiredEvidenceCommand extends Command
{
    protected $signature = 'torudake:purge-evidence';

    protected $description = '保存期間を過ぎた写真のファイルを消す（行と読み取った全文は残す）';

    public function handle(PurgeExpiredEvidence $purge): int
    {
        $count = $purge->run();

        $this->info("写真を{$count}枚消しました。");

        return self::SUCCESS;
    }
}
