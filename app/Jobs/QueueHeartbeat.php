<?php

namespace App\Jobs;

use App\Support\Heartbeat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * キューの常駐が生きている印。キューが空でも、毎分これが流れて処理されれば生きていると分かる。
 */
class QueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        Heartbeat::touch(Heartbeat::QUEUE);
    }
}
