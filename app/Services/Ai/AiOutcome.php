<?php

namespace App\Services\Ai;

use App\Models\AiRun;

final readonly class AiOutcome
{
    /**
     * @param  array<string, mixed>  $output
     */
    public function __construct(
        public array $output,
        public AiRun $run,
    ) {}
}
