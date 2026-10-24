<?php

namespace App\Console\Commands;

use App\Services\FailEvidenceAi;
use Illuminate\Console\Command;

class FailStaleEvidenceAiCommand extends Command
{
    protected $signature = 'torudake:fail-stale-evidence-ai';

    protected $description = '10分以上止まっているAI読み取りを失敗にして、経理の手入力へ戻す';

    public function handle(FailEvidenceAi $failEvidenceAi): int
    {
        $count = $failEvidenceAi->failStale();

        $this->info("停滞していたAI処理を{$count}件、失敗にしました。");

        return self::SUCCESS;
    }
}
