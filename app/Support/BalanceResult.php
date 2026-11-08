<?php

namespace App\Support;

use App\Enums\BalanceProblem;

/**
 * 貸借の検算の結果（円の整数）。problem が null のときだけ一致。
 */
final readonly class BalanceResult
{
    public function __construct(
        public int $debitTotal,
        public int $creditTotal,
        public ?BalanceProblem $problem,
    ) {}

    public function balanced(): bool
    {
        return $this->problem === null;
    }

    /**
     * 借方 − 貸方。
     */
    public function difference(): int
    {
        return $this->debitTotal - $this->creditTotal;
    }

    /**
     * 画面・エラーに出す一文。
     */
    public function message(): string
    {
        if ($this->problem === null) {
            return '借方と貸方の合計が一致しています';
        }

        if ($this->problem === BalanceProblem::Mismatch) {
            return sprintf('借方 %s円・貸方 %s円で、%s円ずれています', number_format($this->debitTotal), number_format($this->creditTotal), number_format(abs($this->difference())));
        }

        return $this->problem->label();
    }
}
