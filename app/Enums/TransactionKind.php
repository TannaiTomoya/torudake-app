<?php

namespace App\Enums;

/**
 * 増減を決めるための取引の種類。分類の「支出・収入」から推し量らず、明示されたときだけ使う（docs/03 §4.3b）。
 * 返金・返済・振替は、支出・収入の向きだけでは決まらないので別の種類にしている。
 */
enum TransactionKind: string
{
    case Purchase = 'purchase';
    case Sale = 'sale';
    case ExpenseRefund = 'expense_refund';
    case LoanRepayment = 'loan_repayment';
    case Transfer = 'transfer';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => '通常の購入・経費の支払い',
            self::Sale => '通常の売上の入金',
            self::ExpenseRefund => '経費の返金',
            self::LoanRepayment => '借入の返済',
            self::Transfer => '口座の間の振替',
            self::Unknown => '取引の意味が分からない',
        };
    }
}
