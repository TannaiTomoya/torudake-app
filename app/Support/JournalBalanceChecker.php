<?php

namespace App\Support;

use App\Enums\BalanceProblem;
use App\Enums\Side;
use App\Models\JournalEntry;
use App\Models\JournalLine;

/**
 * 仕訳の貸借を整数の規則で検算する。AI の答え（ai_check.balanced）は使わない。
 * 空・片側だけ・不正な借貸・1円未満・範囲外は「一致」にしない。float は使わない。
 * 貸借一致は、科目・税率・取引内容の正しさを保証しない。
 */
class JournalBalanceChecker
{
    /** 1行の上限（円）。1000億円。 */
    public const MAX_LINE_YEN = 100_000_000_000;

    /** 借方・貸方それぞれの合計の上限（円）。1兆円。 */
    public const MAX_TOTAL_YEN = 1_000_000_000_000;

    /**
     * 保存されている最新の行で検算する。
     */
    public function forEntry(JournalEntry $entry): BalanceResult
    {
        $rows = [];
        foreach ($entry->lines()->get() as $line) {
            /** @var JournalLine $line */
            $rows[] = ['side' => $line->side, 'amount' => $line->amount_yen];
        }

        return $this->check($rows);
    }

    /**
     * @param  list<array{side: mixed, amount: mixed}>  $rows
     */
    public function check(array $rows): BalanceResult
    {
        if ($rows === []) {
            return new BalanceResult(0, 0, BalanceProblem::Empty);
        }

        $debit = 0;
        $credit = 0;
        $hasDebit = false;
        $hasCredit = false;
        $problem = null;

        foreach ($rows as $row) {
            $side = $row['side'];
            $side = $side instanceof Side ? $side : (is_string($side) ? Side::tryFrom($side) : null);
            $amount = $row['amount'];

            if ($side === null) {
                $problem ??= BalanceProblem::InvalidSide;

                continue;
            }

            if (! is_int($amount) || $amount < 1) {
                $problem ??= BalanceProblem::NonPositive;

                continue;
            }

            if ($amount > self::MAX_LINE_YEN) {
                $problem ??= BalanceProblem::OutOfRange;

                continue;
            }

            if ($side === Side::Debit) {
                $hasDebit = true;
                $debit += $amount;
            } else {
                $hasCredit = true;
                $credit += $amount;
            }

            if ($debit > self::MAX_TOTAL_YEN || $credit > self::MAX_TOTAL_YEN) {
                $problem ??= BalanceProblem::OutOfRange;
                $debit = min($debit, self::MAX_TOTAL_YEN + 1);
                $credit = min($credit, self::MAX_TOTAL_YEN + 1);
            }
        }

        $problem ??= match (true) {
            ! $hasDebit || ! $hasCredit => BalanceProblem::OneSided,
            $debit !== $credit => BalanceProblem::Mismatch,
            default => null,
        };

        return new BalanceResult($debit, $credit, $problem);
    }
}
