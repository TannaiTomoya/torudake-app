<?php

namespace App\Support;

use App\Enums\AccountType;
use App\Enums\EntryKind;
use App\Enums\EntryStatus;
use App\Enums\Side;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * 月の試算表（#65）。DB を見ない純粋な規則で、同じ入力なら同じ答えにする。AI は使わない。
 * Python の参照実装（tools/analysis/trial_balance.py）と、tests/fixtures/trial_balance_vectors.json で同じ答えになることを確かめる。
 *
 * - 足すのは確定（confirmed）の仕訳だけ。下書き・社長から戻した仕訳は入れない
 * - 借方・貸方：その月の通常の仕訳の合計。開始仕訳は月の動きに入れず、「前月まで」に入れる
 * - 前月まで：資産・負債・純資産は、開始仕訳と月より前のすべての仕訳。収益・費用は、その期（FiscalYear）の初めから前月まで
 * - 開始仕訳があれば、その日付より前の通常の仕訳は数えない（開始仕訳の残高に含まれているため）
 * - 残高は科目の向きで符号を付ける（資産・費用は借方が＋、負債・純資産・収益は貸方が＋）
 * - その月の借方合計と貸方合計が違えば「合っていない」（unbalanced）
 */
final class TrialBalance
{
    /**
     * @param  list<array{id: string, code: string, name: string, type: string}>  $accounts
     * @param  list<array{date: string, kind: string, status: string, lines: list<array{account_id: mixed, side: mixed, amount: mixed}>}>  $entries
     * @return array{month: string, fiscal_year_start: string, has_opening: bool, rows: list<array{account_id: string, code: string, name: string, type: string, carried: int, debit: int, credit: int, closing: int}>, debit_total: int, credit_total: int, balanced: bool, problem: string|null}
     */
    public static function compute(array $accounts, array $entries, string $month, int $fiscalYearEndMonth): array
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw new InvalidArgumentException('月は YYYY-MM で渡してください。');
        }
        if ($fiscalYearEndMonth < 1 || $fiscalYearEndMonth > 12) {
            throw new InvalidArgumentException('決算月は1〜12です。');
        }

        $monthStart = CarbonImmutable::createFromFormat('!Y-m-d', $month.'-01');
        $monthStartText = $monthStart->toDateString();
        $monthEndText = $monthStart->endOfMonth()->toDateString();
        $fiscalStartText = FiscalYear::containing($monthStart, $fiscalYearEndMonth)->start->toDateString();

        /** @var array<string, array{account_id: string, code: string, name: string, type: string, carried_debit: int, carried_credit: int, debit: int, credit: int}> $sums */
        $sums = [];
        foreach ($accounts as $account) {
            $sums[$account['id']] = [
                'account_id' => $account['id'], 'code' => $account['code'], 'name' => $account['name'], 'type' => $account['type'],
                'carried_debit' => 0, 'carried_credit' => 0, 'debit' => 0, 'credit' => 0,
            ];
        }

        // 開始仕訳があれば、その日より前の通常の仕訳は開始仕訳の残高に含まれているので数えない
        $openingDate = null;
        foreach ($entries as $entry) {
            if ($entry['kind'] === EntryKind::Opening->value && $entry['status'] === EntryStatus::Confirmed->value && $entry['date'] <= $monthEndText) {
                $openingDate = $openingDate === null ? $entry['date'] : min($openingDate, $entry['date']);
            }
        }

        $hasOpening = false;
        $problem = null;
        $debitTotal = 0;
        $creditTotal = 0;

        foreach ($entries as $entry) {
            $date = $entry['date'];
            if ($entry['status'] !== EntryStatus::Confirmed->value || $date > $monthEndText) {
                continue;
            }
            $isOpening = $entry['kind'] === EntryKind::Opening->value;
            if ($isOpening) {
                $hasOpening = true;
            } elseif ($openingDate !== null && $date < $openingDate) {
                continue;
            }
            $inMonth = ! $isOpening && $date >= $monthStartText;

            foreach ($entry['lines'] as $line) {
                $accountId = $line['account_id'];
                $side = is_string($line['side']) ? Side::tryFrom($line['side']) : null;
                $amount = $line['amount'];
                if (! is_string($accountId) || ! isset($sums[$accountId]) || $side === null || ! is_int($amount) || $amount < 1 || $amount > JournalBalanceChecker::MAX_LINE_YEN) {
                    $problem ??= 'invalid_line';

                    continue;
                }

                if ($inMonth) {
                    if ($side === Side::Debit) {
                        $sums[$accountId]['debit'] += $amount;
                        $debitTotal += $amount;
                    } else {
                        $sums[$accountId]['credit'] += $amount;
                        $creditTotal += $amount;
                    }

                    continue;
                }

                if (self::isProfitAndLoss($sums[$accountId]['type']) && $date < $fiscalStartText) {
                    continue;
                }
                $sums[$accountId][$side === Side::Debit ? 'carried_debit' : 'carried_credit'] += $amount;
            }
        }

        if ($problem === null && $debitTotal !== $creditTotal) {
            $problem = 'unbalanced';
        }

        $rows = [];
        foreach ($sums as $sum) {
            $sign = self::debitIsPlus($sum['type']) ? 1 : -1;
            $carried = $sign * ($sum['carried_debit'] - $sum['carried_credit']);
            $closing = $carried + $sign * ($sum['debit'] - $sum['credit']);
            if ($carried === 0 && $sum['debit'] === 0 && $sum['credit'] === 0 && $sum['carried_debit'] === 0) {
                continue;
            }
            $rows[] = [
                'account_id' => $sum['account_id'], 'code' => $sum['code'], 'name' => $sum['name'], 'type' => $sum['type'],
                'carried' => $carried, 'debit' => $sum['debit'], 'credit' => $sum['credit'], 'closing' => $closing,
            ];
        }
        // 科目コードは数ではなく文字として並べる（Python と同じ順）
        usort($rows, fn (array $a, array $b): int => strcmp($a['code'], $b['code']) ?: strcmp($a['account_id'], $b['account_id']));

        return [
            'month' => $month,
            'fiscal_year_start' => $fiscalStartText,
            'has_opening' => $hasOpening,
            'rows' => $rows,
            'debit_total' => $debitTotal,
            'credit_total' => $creditTotal,
            'balanced' => $problem === null,
            'problem' => $problem,
        ];
    }

    private static function debitIsPlus(string $type): bool
    {
        return in_array($type, [AccountType::Asset->value, AccountType::Expense->value], true);
    }

    private static function isProfitAndLoss(string $type): bool
    {
        return in_array($type, [AccountType::Revenue->value, AccountType::Expense->value], true);
    }
}
