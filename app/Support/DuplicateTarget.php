<?php

namespace App\Support;

use App\Enums\BankAccountKind;
use App\Enums\CategoryDirection;
use Carbon\CarbonImmutable;

/**
 * 候補を探す対象（今回の原資料・仕訳）の値。保存前は入力中の値、画面では保存されている値から作る。
 */
final readonly class DuplicateTarget
{
    public function __construct(
        public string $sourceId,
        public ?string $entryId,
        public CarbonImmutable $date,
        public ?string $vendor,
        public ?int $amountYen,
        public ?CategoryDirection $direction,
        public ?BankAccountKind $bankKind,
        /** AI が読めず仮に入れた日付で、まだ確かめていない */
        public bool $dateUncertain,
    ) {}
}
