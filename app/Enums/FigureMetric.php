<?php

namespace App\Enums;

enum FigureMetric: string
{
    case Sales = 'sales';

    public function label(): string
    {
        return match ($this) {
            self::Sales => '売上高',
        };
    }
}
