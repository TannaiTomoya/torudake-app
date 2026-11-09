<?php

use App\Enums\AccountMovement;
use App\Enums\AccountType;
use App\Enums\EntryStatus;
use App\Enums\LineRole;
use App\Enums\Role;
use App\Enums\Side;
use App\Enums\TransactionKind;
use App\Models\Company;
use App\Models\JournalLine;
use App\Services\JournalLineWriter;
use App\Support\AccountSideRule;
use App\Support\DirectionResult;
use App\Support\JournalBalanceChecker;

/**
 * @return array{side: string, type: string, role: string, amount: int}
 */
function dirLine(string $side, AccountType $type, LineRole $role, int $amount = 1000): array
{
    return ['side' => $side, 'type' => $type->value, 'role' => $role->value, 'amount' => $amount];
}

/**
 * 実際の行の組み立て（JournalLineWriter）で作った仕訳を、向きの判定の入力にする。行の役は分類の勘定科目かどうかで決める。
 *
 * @return list<array{side: Side, type: AccountType, role: LineRole}>
 */
function writtenLines(Company $company, string $category, string $bank): array
{
    $entry = putEntry($company, now()->toDateString(), $category, 3_000, EntryStatus::Draft);
    $categoryModel = $company->categories()->where('name', $category)->firstOrFail();
    app(JournalLineWriter::class)->rewrite($entry, $categoryModel, $company->bankAccounts()->where('name', $bank)->firstOrFail(), 3_000);

    return $entry->lines()->with('account')->get()->map(fn (JournalLine $line): array => [
        'side' => $line->side,
        'type' => $line->account->type,
        'role' => $line->account_id === $categoryModel->account_id ? LineRole::Counterpart : LineRole::Payment,
    ])->values()->all();
}

test('5要素×増減の10通り：資産・費用の増加は借方、負債・純資産・収益の増加は貸方、減少は逆。不明は判定しない', function (AccountType $type, AccountMovement $movement, ?Side $side): void {
    expect(AccountSideRule::expectedSide($type, $movement))->toBe($side);
})->with([
    '資産の増加' => [AccountType::Asset, AccountMovement::Increase, Side::Debit],
    '資産の減少' => [AccountType::Asset, AccountMovement::Decrease, Side::Credit],
    '費用の増加' => [AccountType::Expense, AccountMovement::Increase, Side::Debit],
    '費用の減少' => [AccountType::Expense, AccountMovement::Decrease, Side::Credit],
    '負債の増加' => [AccountType::Liability, AccountMovement::Increase, Side::Credit],
    '負債の減少' => [AccountType::Liability, AccountMovement::Decrease, Side::Debit],
    '純資産の増加' => [AccountType::Equity, AccountMovement::Increase, Side::Credit],
    '純資産の減少' => [AccountType::Equity, AccountMovement::Decrease, Side::Debit],
    '収益の増加' => [AccountType::Revenue, AccountMovement::Increase, Side::Credit],
    '収益の減少' => [AccountType::Revenue, AccountMovement::Decrease, Side::Debit],
    '資産・不明' => [AccountType::Asset, AccountMovement::Unknown, null],
    '収益・不明' => [AccountType::Revenue, AccountMovement::Unknown, null],
]);

test('実際の組み立てで作った現金購入・カード払い・社長の立替・売上の入金は、向きの警告が出ない', function (): void {
    ['company' => $company] = createCompanyWithMember(Role::Editor);

    foreach (['現金', '事業用カード', '社長の立替', '〇〇銀行（普通預金）'] as $bank) {
        expect(AccountSideRule::check(TransactionKind::Purchase, writtenLines($company, '消耗品', $bank))->status)
            ->toBe(DirectionResult::CONSISTENT, $bank);
    }

    expect(AccountSideRule::check(TransactionKind::Sale, writtenLines($company, '売上', '現金'))->status)->toBe(DirectionResult::CONSISTENT)
        ->and(AccountSideRule::check(TransactionKind::Sale, writtenLines($company, '売上', '〇〇銀行（普通預金）'))->status)->toBe(DirectionResult::CONSISTENT);
});

test('対応する取引でわざと逆にした行だけを「向きを確認」にする', function (): void {
    $result = AccountSideRule::check(TransactionKind::Purchase, [
        dirLine('credit', AccountType::Expense, LineRole::Counterpart),
        dirLine('credit', AccountType::Asset, LineRole::Payment),
    ]);

    expect($result->status)->toBe(DirectionResult::REVERSED)
        ->and($result->reversedLines())->toBe([0])
        ->and($result->lines[0]['expected'])->toBe(Side::Debit)
        ->and($result->lines[1]['verdict'])->toBe(DirectionResult::CONSISTENT)
        ->and($result->message())->toContain('向きを確認');

    $sale = AccountSideRule::check(TransactionKind::Sale, [
        dirLine('debit', AccountType::Revenue, LineRole::Counterpart),
        dirLine('credit', AccountType::Asset, LineRole::Payment),
    ]);
    expect($sale->status)->toBe(DirectionResult::REVERSED)->and($sale->reversedLines())->toBe([0, 1]);
});

test('返金・借入返済・振替は意味を指定すれば正しく判定し、意味が分からなければ判定保留にする（誤り扱いしない）', function (): void {
    // 経理の返金：現金が増え、費用が減る
    $refund = [dirLine('debit', AccountType::Asset, LineRole::Payment), dirLine('credit', AccountType::Expense, LineRole::Counterpart)];
    expect(AccountSideRule::check(TransactionKind::ExpenseRefund, $refund)->status)->toBe(DirectionResult::CONSISTENT)
        ->and(AccountSideRule::check(TransactionKind::Unknown, $refund)->status)->toBe(DirectionResult::UNDETERMINED);

    // カード払いの返金：未払金（負債）が減り、費用が減る
    $cardRefund = [dirLine('debit', AccountType::Liability, LineRole::Payment), dirLine('credit', AccountType::Expense, LineRole::Counterpart)];
    expect(AccountSideRule::check(TransactionKind::ExpenseRefund, $cardRefund)->status)->toBe(DirectionResult::CONSISTENT);

    // 借入の返済：負債が減り、預金が減る
    $repay = [dirLine('debit', AccountType::Liability, LineRole::Counterpart), dirLine('credit', AccountType::Asset, LineRole::Payment)];
    expect(AccountSideRule::check(TransactionKind::LoanRepayment, $repay)->status)->toBe(DirectionResult::CONSISTENT)
        ->and(AccountSideRule::check(TransactionKind::Purchase, $repay)->status)->toBe(DirectionResult::UNDETERMINED);

    // 振替：振替先の資産が増え、振替元の資産が減る。資産以外が混ざれば保留
    $transfer = [dirLine('debit', AccountType::Asset, LineRole::Counterpart), dirLine('credit', AccountType::Asset, LineRole::Payment)];
    expect(AccountSideRule::check(TransactionKind::Transfer, $transfer)->status)->toBe(DirectionResult::CONSISTENT)
        ->and(AccountSideRule::check(TransactionKind::Transfer, [dirLine('debit', AccountType::Equity, LineRole::Counterpart), dirLine('credit', AccountType::Asset, LineRole::Payment)])->status)
        ->toBe(DirectionResult::UNDETERMINED);
});

test('複合仕訳は行ごとに判定し、表にない勘定が混ざれば保留（逆とは言わない）', function (): void {
    $composite = [
        dirLine('debit', AccountType::Expense, LineRole::Counterpart, 700),
        dirLine('debit', AccountType::Expense, LineRole::Counterpart, 300),
        dirLine('credit', AccountType::Asset, LineRole::Payment, 1000),
    ];
    expect(AccountSideRule::check(TransactionKind::Purchase, $composite)->status)->toBe(DirectionResult::CONSISTENT);

    $withEquity = [...$composite, dirLine('credit', AccountType::Equity, LineRole::Payment, 0)];
    $result = AccountSideRule::check(TransactionKind::Purchase, $withEquity);
    expect($result->status)->toBe(DirectionResult::UNDETERMINED)
        ->and($result->reversedLines())->toBe([])
        ->and($result->lines[3]['verdict'])->toBe(DirectionResult::UNDETERMINED);
});

test('増減は実際の借方・貸方から逆算しない：同じ行でも、取引の意味が変われば答えが変わる', function (): void {
    $lines = [dirLine('debit', AccountType::Asset, LineRole::Payment), dirLine('credit', AccountType::Expense, LineRole::Counterpart)];

    expect(AccountSideRule::check(TransactionKind::ExpenseRefund, $lines)->status)->toBe(DirectionResult::CONSISTENT)
        ->and(AccountSideRule::check(TransactionKind::Purchase, $lines)->status)->toBe(DirectionResult::REVERSED);
});

test('行がない・借貸や勘定の種類・行の役が読めない入力は、向きを判定しない（invalid）', function (array $lines): void {
    $result = AccountSideRule::check(TransactionKind::Purchase, $lines);

    expect($result->status)->toBe(DirectionResult::INVALID)
        ->and($result->reversedLines())->toBe([]);
})->with([
    '行がない' => [[]],
    '借貸が不正' => [[['side' => 'both', 'type' => 'expense', 'role' => 'counterpart'], dirLine('credit', AccountType::Asset, LineRole::Payment)]],
    '借貸が null' => [[['side' => null, 'type' => 'expense', 'role' => 'counterpart'], dirLine('credit', AccountType::Asset, LineRole::Payment)]],
    '勘定の種類が不正' => [[['side' => 'debit', 'type' => 'cost', 'role' => 'counterpart'], dirLine('credit', AccountType::Asset, LineRole::Payment)]],
    '行の役がない' => [[['side' => 'debit', 'type' => 'expense'], dirLine('credit', AccountType::Asset, LineRole::Payment)]],
    '真偽値' => [[['side' => true, 'type' => true, 'role' => true]]],
]);

test('貸借の一致と向きの一致は別の軸：4通りに加え、判定保留・未入力・無効がそれぞれ独立に出る', function (array $lines, TransactionKind $kind, bool $balanced, string $direction): void {
    $balance = app(JournalBalanceChecker::class)->check(array_map(fn (array $l): array => ['side' => $l['side'], 'amount' => $l['amount'] ?? null], $lines));

    expect($balance->balanced())->toBe($balanced)
        ->and(AccountSideRule::check($kind, $lines)->status)->toBe($direction);
})->with([
    '一致×向き一致' => [[dirLine('debit', AccountType::Expense, LineRole::Counterpart), dirLine('credit', AccountType::Asset, LineRole::Payment)], TransactionKind::Purchase, true, DirectionResult::CONSISTENT],
    '一致×向きが逆' => [[dirLine('credit', AccountType::Expense, LineRole::Counterpart), dirLine('debit', AccountType::Asset, LineRole::Payment)], TransactionKind::Purchase, true, DirectionResult::REVERSED],
    '不一致×向き一致' => [[dirLine('debit', AccountType::Expense, LineRole::Counterpart, 1000), dirLine('credit', AccountType::Asset, LineRole::Payment, 999)], TransactionKind::Purchase, false, DirectionResult::CONSISTENT],
    '不一致×向きが逆' => [[dirLine('credit', AccountType::Expense, LineRole::Counterpart, 1000), dirLine('debit', AccountType::Asset, LineRole::Payment, 999)], TransactionKind::Purchase, false, DirectionResult::REVERSED],
    '一致×判定保留' => [[dirLine('debit', AccountType::Expense, LineRole::Counterpart), dirLine('credit', AccountType::Asset, LineRole::Payment)], TransactionKind::Unknown, true, DirectionResult::UNDETERMINED],
    '未入力（行なし）' => [[], TransactionKind::Purchase, false, DirectionResult::INVALID],
    '一致×無効（勘定の種類が読めない）' => [[['side' => 'debit', 'type' => 'x', 'role' => 'counterpart', 'amount' => 1000], dirLine('credit', AccountType::Asset, LineRole::Payment)], TransactionKind::Purchase, true, DirectionResult::INVALID],
]);
