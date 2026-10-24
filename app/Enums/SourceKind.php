<?php

namespace App\Enums;

enum SourceKind: string
{
    case Receipt = 'receipt';

    public function label(): string
    {
        return match ($this) {
            self::Receipt => '写真',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Receipt => 'ink',
        };
    }
}
