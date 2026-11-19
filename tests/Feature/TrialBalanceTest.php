<?php

use App\Enums\EntryStatus;
use App\Enums\Role;
use App\Models\Company;
use App\Models\User;
use App\Services\OpeningEntryService;
use App\Services\TrialBalanceReport;
use App\Support\TrialBalance;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

function trialAccountId(Company $company, string $code): string
{
    return (string) $company->accounts()->where('code', $code)->value('id');
}

function putOpening(Company $company, User $user): void
{
    app(OpeningEntryService::class)->save($company, $user, [
        ['account_id' => trialAccountId($company, '102'), 'side' => 'debit', 'amount' => 800000],
        ['account_id' => trialAccountId($company, '301'), 'side' => 'credit', 'amount' => 500000],
        ['account_id' => trialAccountId($company, '302'), 'side' => 'credit', 'amount' => 300000],
    ]);
}

/**
 * @return array<string, array{carried: int, debit: int, credit: int, closing: int}>
 */
function rowsByCode(array $result): array
{
    $rows = [];
    foreach ($result['rows'] as $row) {
        $rows[$row['code']] = ['carried' => $row['carried'], 'debit' => $row['debit'], 'credit' => $row['credit'], 'closing' => $row['closing']];
    }

    return $rows;
}

test('T51 試算表の規則：Python（tools/analysis）と共通の架空ベクトルで同じ答えになる', function (): void {
    $vectors = json_decode((string) file_get_contents(base_path('tests/fixtures/trial_balance_vectors.json')), true, 512, JSON_THROW_ON_ERROR);
    expect($vectors['cases'])->not->toBeEmpty();

    foreach ($vectors['cases'] as $case) {
        $result = TrialBalance::compute($vectors['accounts'], $case['entries'], $case['month'], $case['fiscal_year_end_month']);

        expect($result)->toBe([...['month' => $case['month']], ...$case['expected']], $case['name']);
    }
});

test('T51 試算表の規則：月と決算月が正しくなければ計算しない', function (string $month, int $endMonth): void {
    expect(fn () => TrialBalance::compute([], [], $month, $endMonth))->toThrow(InvalidArgumentException::class);
})->with([
    ['2026-13', 7],
    ['2026-9', 7],
    ['', 7],
    ['2026-09', 0],
    ['2026-09', 13],
]);

test('T51 DB から：確定だけ・月またぎ・他社を入れずに足し上げる', function (): void {
    ['company' => $company] = createCompanyWithMember(Role::Editor);
    ['company' => $other] = createCompanyWithMember(Role::Editor);
    putEntry($company, '2026-08-31', '消耗品', 500);
    putEntry($company, '2026-09-01', '消耗品', 3850);
    putEntry($company, '2026-09-30', '消耗品', 150);
    putEntry($company, '2026-10-01', '消耗品', 900);
    putEntry($company, '2026-09-10', '消耗品', 1000, EntryStatus::Draft);
    putEntry($company, '2026-09-11', '消耗品', 2000, EntryStatus::Returned);
    putEntry($other, '2026-09-10', '消耗品', 77777);

    $result = app(TrialBalanceReport::class)->forMonth($company, '2026-09');

    expect(rowsByCode($result))->toBe([
        '101' => ['carried' => -500, 'debit' => 0, 'credit' => 4000, 'closing' => -4500],
        '505' => ['carried' => 500, 'debit' => 4000, 'credit' => 0, 'closing' => 4500],
    ])->and($result['debit_total'])->toBe(4000)
        ->and($result['balanced'])->toBeTrue()
        ->and($result['has_opening'])->toBeFalse();
});

test('T51 画面：開始仕訳がないあいだは「残高ではありません」と出し、仕訳0件の月は空と出す', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);
    putEntry($company, now()->toDateString(), '消耗品', 3850);

    Livewire::actingAs($user)->test('pages::company.trial-balance', ['company' => $company])
        ->assertSee(TrialBalanceReport::NO_OPENING_NOTE)
        ->assertSee('借方と貸方は合っています（3,850円）')
        ->assertSee('消耗品費')
        ->assertSee('-3,850円')
        ->set('month', '2000-01')
        ->assertSee('この月までの確定した仕訳はありません。')
        ->set('month', 'zzz')
        ->assertSet('month', now()->format('Y-m'));
});

test('T51 画面：開始仕訳を入れると注意が消え、前月までに入る', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Owner);
    putOpening($company, $user);

    $result = app(TrialBalanceReport::class)->forMonth($company, '2026-08');
    expect($result['has_opening'])->toBeTrue()
        ->and(rowsByCode($result)['102'])->toBe(['carried' => 800000, 'debit' => 0, 'credit' => 0, 'closing' => 800000])
        ->and($result['debit_total'])->toBe(0);

    Livewire::actingAs($user)->test('pages::company.trial-balance', ['company' => $company])
        ->set('month', '2026-08')
        ->assertDontSee(TrialBalanceReport::NO_OPENING_NOTE)
        ->assertSee('800,000円');
});

test('T51 Excel：試算表と開始仕訳の2シートで、科目名は数式にしない', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);
    $company->accounts()->where('code', '505')->sole()->forceFill(['name' => '=1+1'])->save();
    putEntry($company, '2026-09-10', '消耗品', 3850);
    putOpening($company, $user);

    $response = $this->actingAs($user)->get(route('company.trial-balance.xlsx', [$company, '2026-09']));
    $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    $path = tempnam(sys_get_temp_dir(), 'tb-test');
    file_put_contents($path, $response->getContent());
    try {
        $book = IOFactory::load($path);
    } finally {
        @unlink($path);
    }

    expect($book->getSheetNames())->toBe([TrialBalanceReport::TRIAL_SHEET, TrialBalanceReport::OPENING_SHEET]);
    $trial = $book->getSheetByName(TrialBalanceReport::TRIAL_SHEET);
    $cells = $trial->toArray(null, false, false);
    $names = array_column(array_slice($cells, 5), 1);
    expect($names)->toContain('現金', '=1+1', '合計')
        ->and($trial->getCell('A2')->getValue())->toBe('借方と貸方は合っています');

    $formulaRow = array_search('=1+1', $names, true) + 6;
    expect($trial->getCell([2, $formulaRow])->getDataType())->toBe(DataType::TYPE_STRING);

    $opening = $book->getSheetByName(TrialBalanceReport::OPENING_SHEET)->toArray(null, false, false);
    expect(array_column(array_slice($opening, 1), 1))->toBe(['102', '301', '302']);
});

test('T51 試算表の画面と Excel は owner・admin・editor だけ', function (Role $role, bool $submitOnly, int $status): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember($role, $submitOnly);

    $this->actingAs($user)->get(route('company.trial-balance', $company))->assertStatus($status);
    $this->actingAs($user)->get(route('company.trial-balance.xlsx', [$company, '2026-09']))->assertStatus($status);
})->with([
    'owner' => [Role::Owner, false, 200],
    'admin' => [Role::Admin, false, 200],
    'editor' => [Role::Editor, false, 200],
    'approver' => [Role::Approver, false, 403],
    'viewer' => [Role::Viewer, false, 403],
    '送るだけ' => [Role::Editor, true, 403],
]);

test('T51 他社の試算表は404、月の形が違う URL も404', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);
    ['company' => $other] = createCompanyWithMember(Role::Editor);

    $this->actingAs($user)->get(route('company.trial-balance', $other))->assertNotFound();
    $this->actingAs($user)->get(route('company.trial-balance.xlsx', [$other, '2026-09']))->assertNotFound();
    $this->actingAs($user)->get('/c/'.$company->id.'/trial-balance/2026-13.xlsx')->assertNotFound();
});

test('T51 画面を開いたあとで役割が変わったら、月を変えても403', function (): void {
    ['company' => $company, 'user' => $user, 'membership' => $membership] = createCompanyWithMember(Role::Editor);
    $page = Livewire::actingAs($user)->test('pages::company.trial-balance', ['company' => $company]);

    $membership->forceFill(['role' => Role::Viewer])->save();

    $page->set('month', '2026-08')->assertForbidden();
});

test('T51 サイドバーの「試算表」は見られる人にだけ出る', function (): void {
    ['company' => $company, 'user' => $admin] = createCompanyWithMember(Role::Admin);
    ['user' => $viewer] = createCompanyWithMember(Role::Viewer, company: $company);

    $this->actingAs($admin)->get(route('company.home', $company))->assertSee('data-test="sidebar-trial-balance"', false);
    $this->actingAs($viewer)->get(route('company.home', $company))->assertDontSee('data-test="sidebar-trial-balance"', false);
});
