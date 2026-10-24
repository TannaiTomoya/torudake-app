<?php

namespace App\Enums;

enum EntryStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'AIの下書き',
            self::Confirmed => '確定',
            self::Returned => '社長から戻し',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'warn',
            self::Confirmed => 'ok',
            self::Returned => 'ng',
        };
    }

    public function isOpen(): bool
    {
        return $this !== self::Confirmed;
    }
}
