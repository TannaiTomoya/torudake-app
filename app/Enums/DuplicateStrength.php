<?php

namespace App\Enums;

/**
 * 二重計上の候補の強さ（コードの照合）。「重複」と断定するものではない。
 */
enum DuplicateStrength: string
{
    case Strong = 'strong';
    case Likely = 'likely';
    case Weak = 'weak';

    public function label(): string
    {
        return match ($this) {
            self::Strong => '確かな一致',
            self::Likely => '近い一致',
            self::Weak => 'ゆるい一致',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Strong => 0,
            self::Likely => 1,
            self::Weak => 2,
        };
    }

    /**
     * 気になる印（社長に見てもらう）に使う強さか。ゆるい一致は表示だけ。
     */
    public function raisesConcern(): bool
    {
        return $this !== self::Weak;
    }
}
