<?php

namespace App\Services;

use App\Enums\CorrectionReason;

/**
 * 経理が「直して確定」の画面で入れた値。
 */
final readonly class ConfirmEntryInput
{
    /**
     * @param  list<string>  $readingConfirmed  経理が「確かめた」と答えた読み取り欄（date など）
     */
    public function __construct(
        public string $entryDate,
        public ?string $vendor,
        public ?string $description,
        public string $categoryId,
        public string $bankAccountId,
        public ?int $amountYen,
        public ?CorrectionReason $reason = null,
        public array $readingConfirmed = [],
    ) {}
}
