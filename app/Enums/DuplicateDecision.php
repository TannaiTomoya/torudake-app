<?php

namespace App\Enums;

/**
 * 候補に対する経理の判断。
 */
enum DuplicateDecision: string
{
    case Different = 'different';
    case Duplicate = 'duplicate';
    case Hold = 'hold';

    public function label(): string
    {
        return match ($this) {
            self::Different => '別の取引',
            self::Duplicate => '重複なので今回を対象外',
            self::Hold => '保留',
        };
    }

    /**
     * 理由を必ず書いてもらう判断。
     */
    public function requiresReason(): bool
    {
        return $this !== self::Hold;
    }
}
