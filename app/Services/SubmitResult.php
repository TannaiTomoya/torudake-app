<?php

namespace App\Services;

use App\Models\SourceRecord;

final readonly class SubmitResult
{
    public function __construct(
        public bool $duplicate,
        public ?SourceRecord $source,
        public int $monthCount,
    ) {}
}
