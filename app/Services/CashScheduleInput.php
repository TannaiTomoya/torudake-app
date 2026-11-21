<?php

namespace App\Services;

use App\Enums\ScheduleCategory;
use App\Enums\ScheduleDirection;
use Carbon\CarbonImmutable;

/**
 * 入金・支払いの予定の入力（#67）。確かめるのは CashScheduleService。
 */
final readonly class CashScheduleInput
{
    public function __construct(
        public ScheduleDirection $direction,
        public ScheduleCategory $category,
        public ?string $partnerId,
        public string $bankAccountId,
        public int $amountYen,
        public CarbonImmutable $dueDate,
    ) {}
}
