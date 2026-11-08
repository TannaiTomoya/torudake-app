<?php

namespace App\Services;

use App\Models\SourceRecord;

/**
 * duplicate が true のとき、source は「もう届いている原資料のうち、この人が読めるもの」。読めなければ null。
 */
final readonly class SubmitResult
{
    public function __construct(
        public bool $duplicate,
        public ?SourceRecord $source,
        public int $monthCount,
    ) {}
}
