<?php

namespace App\Services;

use App\Enums\CategoryDirection;
use App\Enums\Side;
use App\Models\BankAccount;
use App\Models\Category;
use App\Models\JournalEntry;
use App\Models\JournalLine;

/**
 * 分類・支払い元・金額から借方・貸方の2行を組み直す。
 * 支出：借方＝分類の勘定科目、貸方＝口座の勘定科目。収入はその逆。
 * 呼ぶ側のトランザクションの中で使う。
 */
class JournalLineWriter
{
    public function rewrite(JournalEntry $entry, Category $category, BankAccount $bankAccount, int $amountYen): void
    {
        $entry->lines()->get()->each(fn (JournalLine $line) => $line->delete());

        [$debitAccountId, $creditAccountId] = $category->direction === CategoryDirection::Income
            ? [$bankAccount->account_id, $category->account_id]
            : [$category->account_id, $bankAccount->account_id];

        foreach ([[Side::Debit, $debitAccountId], [Side::Credit, $creditAccountId]] as $index => [$side, $accountId]) {
            $line = new JournalLine;
            $line->forceFill([
                'company_id' => $entry->company_id,
                'journal_entry_id' => $entry->id,
                'line_no' => $index + 1,
                'side' => $side,
                'account_id' => $accountId,
                'amount_yen' => $amountYen,
            ])->save();
        }

        $entry->unsetRelation('lines');
    }
}
