<?php

namespace App\Services\Ai;

final readonly class AiResult
{
    /**
     * @param  array<string, mixed>|null  $output  JSON として読めなかったときは null
     */
    public function __construct(
        public ?array $output,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
    ) {}
}
