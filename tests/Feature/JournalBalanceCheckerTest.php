<?php

use App\Enums\BalanceProblem;
use App\Enums\ConcernFlag;
use App\Enums\CorrectionReason;
use App\Enums\EntryStatus;
use App\Enums\Role;
use App\Enums\Side;
use App\Models\AiCorrection;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Category;
use App\Models\JournalEntry;
use App\Services\Ai\FakeClaudeClient;
use App\Services\ConcernRules;
use App\Services\JournalLineWriter;
use App\Services\OwnerReviewService;
use App\Support\JournalBalanceChecker;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('evidence');
});

function checkRows(array $rows)
{
    return app(JournalBalanceChecker::class)->check($rows);
}

test('貸借の検算：借方1000円・貸方1000円は一致、1円差は不一致', function (): void {
    $ok = checkRows([['side' => Side::Debit, 'amount' => 1000], ['side' => Side::Credit, 'amount' => 1000]]);
    $off = checkRows([['side' => 'debit', 'amount' => 1000], ['side' => 'credit', 'amount' => 999]]);

    expect($ok->balanced())->toBeTrue()->and($ok->problem)->toBeNull()->and($ok->difference())->toBe(0)
        ->and($off->balanced())->toBeFalse()->and($off->problem)->toBe(BalanceProblem::Mismatch)
        ->and($off->difference())->toBe(1)->and($off->message())->toContain('1円ずれています');
});

test('貸借の検算：複数行は合計で比べる', function (): void {
    $result = checkRows([
        ['side' => Side::Debit, 'amount' => 700],
        ['side' => Side::Debit, 'amount' => 300],
        ['side' => Side::Credit, 'amount' => 1000],
    ]);

    expect($result->balanced())->toBeTrue()->and($result->debitTotal)->toBe(1000);
});

test('貸借の検算：空・片側だけ・不正な借貸・1円未満・整数でない値は「一致」にしない', function (array $rows, BalanceProblem $problem): void {
    $result = checkRows($rows);

    expect($result->balanced())->toBeFalse()->and($result->problem)->toBe($problem);
})->with([
    '空の配列' => [[], BalanceProblem::Empty],
    '借方だけ' => [[['side' => Side::Debit, 'amount' => 100]], BalanceProblem::OneSided],
    '貸方だけ' => [[['side' => Side::Credit, 'amount' => 100]], BalanceProblem::OneSided],
    '不正な借貸' => [[['side' => 'both', 'amount' => 100], ['side' => Side::Credit, 'amount' => 100]], BalanceProblem::InvalidSide],
    '借貸が null' => [[['side' => null, 'amount' => 100], ['side' => Side::Credit, 'amount' => 100]], BalanceProblem::InvalidSide],
    '0円の行' => [[['side' => Side::Debit, 'amount' => 0], ['side' => Side::Credit, 'amount' => 0]], BalanceProblem::NonPositive],
    '負の行' => [[['side' => Side::Debit, 'amount' => -100], ['side' => Side::Credit, 'amount' => -100]], BalanceProblem::NonPositive],
    '小数' => [[['side' => Side::Debit, 'amount' => 100.5], ['side' => Side::Credit, 'amount' => 100.5]], BalanceProblem::NonPositive],
    '数字の文字列' => [[['side' => Side::Debit, 'amount' => '100'], ['side' => Side::Credit, 'amount' => '100']], BalanceProblem::NonPositive],
    '金額が null' => [[['side' => Side::Debit, 'amount' => null], ['side' => Side::Credit, 'amount' => 100]], BalanceProblem::NonPositive],
]);

test('貸借の検算：上限ちょうどは一致、1円超えると範囲外（足し算でも超えない）', function (): void {
    $max = JournalBalanceChecker::MAX_LINE_YEN;

    expect(checkRows([['side' => Side::Debit, 'amount' => $max], ['side' => Side::Credit, 'amount' => $max]])->balanced())->toBeTrue();

    $over = checkRows([['side' => Side::Debit, 'amount' => $max + 1], ['side' => Side::Credit, 'amount' => $max + 1]]);
    expect($over->balanced())->toBeFalse()->and($over->problem)->toBe(BalanceProblem::OutOfRange);

    $rows = [];
    for ($i = 0; $i <= (int) (JournalBalanceChecker::MAX_TOTAL_YEN / $max); $i++) {
        $rows[] = ['side' => Side::Debit, 'amount' => $max];
        $rows[] = ['side' => Side::Credit, 'amount' => $max];
    }
    $sum = checkRows($rows);
    expect($sum->balanced())->toBeFalse()->and($sum->problem)->toBe(BalanceProblem::OutOfRange);

    $huge = checkRows([['side' => Side::Debit, 'amount' => PHP_INT_MAX], ['side' => Side::Credit, 'amount' => PHP_INT_MAX]]);
    expect($huge->problem)->toBe(BalanceProblem::OutOfRange);
});

test('貸借の検算：Python（tools/analysis）と共通の架空ベクトルで同じ答えになる', function (): void {
    $vectors = json_decode((string) file_get_contents(base_path('tests/fixtures/balance_vectors.json')), true, 512, JSON_THROW_ON_ERROR);
    expect($vectors['cases'])->not->toBeEmpty();

    foreach ($vectors['cases'] as $case) {
        $result = checkRows($case['rows']);

        expect($result->balanced())->toBe($case['balanced'], $case['name'])
            ->and($result->problem?->value)->toBe($case['problem'], $case['name'])
            ->and($result->debitTotal)->toBe($case['debit_total'], $case['name'])
            ->and($result->creditTotal)->toBe($case['credit_total'], $case['name']);
    }
});

test('貸借の検算：問題が複数あっても、最初に見つけた問題を返し、一致にはならない', function (): void {
    $result = checkRows([['side' => 'x', 'amount' => 0], ['side' => Side::Debit, 'amount' => 100], ['side' => Side::Credit, 'amount' => 100]]);

    expect($result->balanced())->toBeFalse()->and($result->problem)->toBe(BalanceProblem::InvalidSide);
});

test('下書き：行は規則で検算され、AI が逆の答えを返しても気になる印は規則の結果に従う', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $source = sendReceipt($company, $editor);
    /** @var JournalEntry $entry */
    $entry = $source->journalEntry()->firstOrFail();

    // AI が「不一致」と答えても（過去の回答）、行が一致なら印は付かない
    $rules = app(ConcernRules::class);
    $balanced = app(JournalBalanceChecker::class)->forEntry($entry);
    expect($balanced->balanced())->toBeTrue()
        ->and($rules->for($company, 3850, ['balanced' => false], false, false, $balanced))->not->toContain(ConcernFlag::AiUnbalanced->value);

    // AI が「一致」と答えても、行が合っていなければ印が付く
    $entry->lines()->where('side', Side::Credit)->get()->each->forceFill(['amount_yen' => 3000])->each->save();
    $broken = app(JournalBalanceChecker::class)->forEntry($entry);
    expect($broken->balanced())->toBeFalse()
        ->and($rules->for($company, 3850, ['balanced' => true], false, false, $broken))->toContain(ConcernFlag::AiUnbalanced->value);

    // 行がない下書き（金額の記載なし）は検算していない扱いで、印も付かない
    expect($rules->for($company, null, null, false, true, null))->not->toContain(ConcernFlag::AiUnbalanced->value);
});

test('詳細画面：規則の検算とAIの回答が食い違うときは、検算の結果を正として知らせる', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $source = sendReceipt($company, $editor);
    /** @var JournalEntry $entry */
    $entry = $source->journalEntry()->firstOrFail();
    $entry->forceFill(['ai_check' => [...($entry->ai_check ?? []), 'balanced' => false, 'debit_total' => 1, 'credit_total' => 2]])->save();

    Livewire::actingAs($editor)
        ->test('pages::company.sources.show', ['company' => $company, 'source' => $source->id])
        ->assertSeeHtml('data-test="rule-check"')
        ->assertSee('借方 3,850円 ／ 貸方 3,850円')
        ->assertSee('（一致）')
        ->assertSeeHtml('data-test="ai-balance-differs"')
        ->assertDontSee('借方 1円');
});

test('詳細画面：行が合っていないと、検算の結果と「確定できません」を出す', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $source = sendReceipt($company, $editor);
    /** @var JournalEntry $entry */
    $entry = $source->journalEntry()->firstOrFail();
    $entry->lines()->where('side', Side::Credit)->get()->each->forceFill(['amount_yen' => 3849])->each->save();

    Livewire::actingAs($editor)
        ->test('pages::company.sources.show', ['company' => $company, 'source' => $source->id])
        ->assertSee('借方 3,850円 ／ 貸方 3,849円')
        ->assertSee('1円ずれています')
        ->assertSee('このままでは確定できません');
});

test('詳細画面：金額の記載がない下書きは「検算していない」と出し、不一致とは言わない', function (): void {
    app(FakeClaudeClient::class)->withReading([
        'vendor' => 'ENEOS 八幡平SS',
        'total_yen' => null,
        'amount_missing' => true,
        'payment_method' => 'invoice_later',
        'tax_breakdown' => [],
    ]);
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $source = sendReceipt($company, $editor);

    Livewire::actingAs($editor)
        ->test('pages::company.sources.show', ['company' => $company, 'source' => $source->id])
        ->assertSeeHtml('data-test="rule-unchecked"')
        ->assertDontSee('合っていません')
        ->assertDontSee('このままでは確定できません');
});

/**
 * 保存のとき、行を書いたあとで貸方を1円減らす（将来の破損を想定した偽の書き込み）。
 */
function breakLineWriter(): void
{
    app()->bind(JournalLineWriter::class, fn () => new class extends JournalLineWriter
    {
        public function rewrite(JournalEntry $entry, Category $category, BankAccount $bankAccount, int $amountYen): void
        {
            parent::rewrite($entry, $category, $bankAccount, $amountYen);

            $line = $entry->lines()->where('side', Side::Credit)->firstOrFail();
            $line->forceFill(['amount_yen' => $amountYen - 1])->save();
        }
    });
}

test('保存：最新の行が合わなければ確定せず、仕訳・原資料・直した記録・Excel・監査に何も残らない', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $source = sendReceipt($company, $editor);
    /** @var JournalEntry $entry */
    $entry = $source->journalEntry()->with('lines')->firstOrFail();
    $linesBefore = $entry->lines->map(fn ($l) => [$l->side->value, $l->amount_yen])->sortBy(0)->values()->all();
    $auditBefore = AuditLog::where('action', 'entry.confirm')->count();

    breakLineWriter();

    Livewire::actingAs($editor)
        ->test('pages::company.sources.show', ['company' => $company, 'source' => $source->id])
        ->set('categoryId', categoryId($company, '燃料'))
        ->set('reason', CorrectionReason::WrongCategory->value)
        ->call('confirm')
        ->assertHasErrors('amountYen');

    $entry->refresh();
    $linesAfter = $entry->lines()->get()->map(fn ($l) => [$l->side->value, $l->amount_yen])->sortBy(0)->values()->all();

    expect($entry->status)->toBe(EntryStatus::Draft)
        ->and($entry->confirmed_at)->toBeNull()
        ->and($entry->category?->name)->not->toBe('燃料')
        ->and($source->refresh()->status->value)->toBe('drafted')
        ->and($linesAfter)->toBe($linesBefore)
        ->and(AiCorrection::count())->toBe(0)
        ->and(AuditLog::where('action', 'entry.confirm')->count())->toBe($auditBefore)
        ->and($company->workbook()->first()?->next_row ?? 2)->toBe(2);
});

test('保存：社長から戻された後の再確定でも、最新の行で検算し、合わなければ戻された状態のまま', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    ['user' => $owner] = createCompanyWithMember(Role::Owner, company: $company);
    $source = sendReceipt($company, $editor);

    Livewire::actingAs($editor)
        ->test('pages::company.sources.show', ['company' => $company, 'source' => $source->id])
        ->call('confirm')->assertHasNoErrors();

    $entry = $source->refresh()->journalEntry()->firstOrFail();
    app(OwnerReviewService::class)->returnToBookkeeper($company, $owner, $entry->id, '金額を確かめて');
    expect($entry->refresh()->status)->toBe(EntryStatus::Returned);

    breakLineWriter();
    Livewire::actingAs($editor)
        ->test('pages::company.sources.show', ['company' => $company, 'source' => $source->id])
        ->call('confirm')->assertHasErrors('amountYen');
    expect($entry->refresh()->status)->toBe(EntryStatus::Returned);

    app()->forgetInstance(JournalLineWriter::class);
    app()->bind(JournalLineWriter::class, fn () => new JournalLineWriter);
    Livewire::actingAs($editor)
        ->test('pages::company.sources.show', ['company' => $company, 'source' => $source->id])
        ->call('confirm')->assertHasNoErrors();

    $entry->refresh();
    expect($entry->status)->toBe(EntryStatus::Confirmed)
        ->and($entry->concern_flags)->not->toContain(ConcernFlag::AiUnbalanced->value)
        ->and(app(JournalBalanceChecker::class)->forEntry($entry)->balanced())->toBeTrue();
});
