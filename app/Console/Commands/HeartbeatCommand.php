<?php

namespace App\Console\Commands;

use App\Jobs\QueueHeartbeat;
use App\Support\Heartbeat;
use Illuminate\Console\Command;

class HeartbeatCommand extends Command
{
    protected $signature = 'torudake:heartbeat';

    protected $description = '定期処理が動いている印を書き、キューの常駐が動いている印の仕事を流す（毎分）';

    public function handle(): int
    {
        Heartbeat::touch(Heartbeat::SCHEDULER);
        QueueHeartbeat::dispatch();

        return self::SUCCESS;
    }
}
