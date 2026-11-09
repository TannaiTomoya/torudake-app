<?php

namespace App\Support;

use App\Enums\AccountMovement;
use App\Enums\AccountType;
use App\Enums\LineRole;
use App\Enums\Side;
use App\Enums\TransactionKind;

/**
 * 勘定の5要素×増減から、その行が入るべき側（借方・貸方）を決める純粋な規則（課題Eの移植。#30）。
 *
 * - 資産・費用の増加は借方、負債・純資産・収益の増加は貸方。減少はその逆。不明は判定しない（null）。
 * - 増減は、実際の借方・貸方から逆算しない。明示された取引の種類と、行の役・勘定の種類から決める（循環チェックにしない）。
 * - 支出・収入の向きだけで増減を断定しない。返金では費用が減り、返済では負債が減る。
 * - 結果は「向きを確認」の材料で、自動で反転したり保存を止めたりしない。今は保存・画面にはつないでいない（docs/03 §4.3b）。
 */
final class AccountSideRule
{
    public static function expectedSide(AccountType $type, AccountMovement $movement): ?Side
    {
        if ($movement === AccountMovement::Unknown) {
            return null;
        }

        $debitWhenIncrease = in_array($type, [AccountType::Asset, AccountType::Expense], true);
        $increase = $movement === AccountMovement::Increase;

        return $debitWhenIncrease === $increase ? Side::Debit : Side::Credit;
    }

    /**
     * 取引の種類と行の役・勘定の種類から、その行の増減を決める。表にない組み合わせは Unknown（判定保留）。
     */
    public static function movement(TransactionKind $kind, LineRole $role, AccountType $type): AccountMovement
    {
        $increase = AccountMovement::Increase;
        $decrease = AccountMovement::Decrease;

        return match ($kind) {
            // 費用や備品（資産）が増え、現金・預金が減るか、カード・立替（負債）が増える
            TransactionKind::Purchase => match ($role) {
                LineRole::Counterpart => in_array($type, [AccountType::Expense, AccountType::Asset], true) ? $increase : AccountMovement::Unknown,
                LineRole::Payment => match ($type) {
                    AccountType::Asset => $decrease,
                    AccountType::Liability => $increase,
                    default => AccountMovement::Unknown,
                },
            },
            // 売上（収益）が増え、現金・預金・売掛金（資産）が増える
            TransactionKind::Sale => match ($role) {
                LineRole::Counterpart => $type === AccountType::Revenue ? $increase : AccountMovement::Unknown,
                LineRole::Payment => $type === AccountType::Asset ? $increase : AccountMovement::Unknown,
            },
            // 費用が減り、返ってきたお金（資産）が増えるか、カードの未払い（負債）が減る
            TransactionKind::ExpenseRefund => match ($role) {
                LineRole::Counterpart => $type === AccountType::Expense ? $decrease : AccountMovement::Unknown,
                LineRole::Payment => match ($type) {
                    AccountType::Asset => $increase,
                    AccountType::Liability => $decrease,
                    default => AccountMovement::Unknown,
                },
            },
            // 借入（負債）が減り、返したお金（資産）が減る
            TransactionKind::LoanRepayment => match ($role) {
                LineRole::Counterpart => $type === AccountType::Liability ? $decrease : AccountMovement::Unknown,
                LineRole::Payment => $type === AccountType::Asset ? $decrease : AccountMovement::Unknown,
            },
            // 振替先（資産）が増え、振替元（資産）が減る
            TransactionKind::Transfer => $type === AccountType::Asset
                ? ($role === LineRole::Counterpart ? $increase : $decrease)
                : AccountMovement::Unknown,
            TransactionKind::Unknown => AccountMovement::Unknown,
        };
    }

    /**
     * 仕訳の行の向きを、取引の意味と比べる。複合仕訳も行ごとに判定する。
     *
     * @param  list<array{side: mixed, type: mixed, role: mixed}>  $lines
     */
    public static function check(TransactionKind $kind, array $lines): DirectionResult
    {
        if ($lines === []) {
            return new DirectionResult(DirectionResult::INVALID, []);
        }

        $results = [];
        $invalid = $reversed = $unknown = false;

        foreach ($lines as $line) {
            $actual = self::enum(Side::class, $line['side'] ?? null);
            $type = self::enum(AccountType::class, $line['type'] ?? null);
            $role = self::enum(LineRole::class, $line['role'] ?? null);

            if ($actual === null || $type === null || $role === null) {
                $invalid = true;
                $results[] = ['expected' => null, 'actual' => $actual, 'verdict' => DirectionResult::INVALID];

                continue;
            }

            $expected = self::expectedSide($type, self::movement($kind, $role, $type));
            $verdict = match (true) {
                $expected === null => DirectionResult::UNDETERMINED,
                $expected === $actual => DirectionResult::CONSISTENT,
                default => DirectionResult::REVERSED,
            };
            $reversed = $reversed || $verdict === DirectionResult::REVERSED;
            $unknown = $unknown || $verdict === DirectionResult::UNDETERMINED;

            $results[] = ['expected' => $expected, 'actual' => $actual, 'verdict' => $verdict];
        }

        $status = match (true) {
            $invalid => DirectionResult::INVALID,
            $reversed => DirectionResult::REVERSED,
            $unknown => DirectionResult::UNDETERMINED,
            default => DirectionResult::CONSISTENT,
        };

        return new DirectionResult($status, $results);
    }

    /**
     * @template T of Side|AccountType|LineRole
     *
     * @param  class-string<T>  $enum
     * @return T|null
     */
    private static function enum(string $enum, mixed $value): Side|AccountType|LineRole|null
    {
        if ($value instanceof $enum) {
            return $value;
        }

        return is_string($value) ? $enum::tryFrom($value) : null;
    }
}
