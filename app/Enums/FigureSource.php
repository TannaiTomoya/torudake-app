<?php

namespace App\Enums;

enum FigureSource: string
{
    case Zaimu = 'zaimu';
    case Hanbai = 'hanbai';
    case Manual = 'manual';
    case Demo = 'demo';

    public function label(): string
    {
        return match ($this) {
            self::Zaimu => '財務王',
            self::Hanbai => '販売王',
            self::Manual => '手入力',
            self::Demo => 'デモ用の架空の数字',
        };
    }
}
