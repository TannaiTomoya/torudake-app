<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\BankBalance;
use App\Models\Company;
use App\Models\User;
use App\Services\BankBalanceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

const BANK_NAME = '〇〇銀行（普通預金）';

function openBalances(Company $company, User $user)
{
    return Livewire::actingAs($user)->test('pages::company.balances', ['company' => $company]);
}

function enterBalance(Company $company, User $user, string $date, int $yen, ?string $accountId = null): BankBalance
{
    return app(BankBalanceService::class)->enter($company, $user, $accountId ?? bankAccountId($company, BANK_NAME), CarbonImmutable::parse($date), $yen);
}

test('T50 残高を入れると、口座×日付で1件残り、監査記録に入れた値が残る', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);

    openBalances($company, $user)
        ->set('bankAccountId', bankAccountId($company, BANK_NAME))
        ->set('balanceDate', '2026-10-31')
        ->set('balanceYen', '1,234,567')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('1,234,567円')
        ->assertSee('10月31日(土)');

    $balance = $company->bankBalances()->sole();
    expect($balance->balance_yen)->toBe(1234567)
        ->and($balance->balance_date->toDateString())->toBe('2026-10-31')
        ->and($balance->source)->toBe(BankBalance::SOURCE_MANUAL)
        ->and($balance->entered_by)->toBe($user->id);

    $log = AuditLog::query()->where('action', 'bank_balance.enter')->sole();
    expect($log->company_id)->toBe($company->id)
        ->and($log->before)->toBeNull()
        ->and($log->after)->toMatchArray(['balance_date' => '2026-10-31', 'balance_yen' => 1234567]);
});

test('T50 マイナスの残高（借り越し）も入れられる', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);

    openBalances($company, $user)
        ->set('balanceDate', '2026-10-31')
        ->set('balanceYen', '-50000')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('-50,000円');

    expect($company->bankBalances()->sole()->balance_yen)->toBe(-50000);
});

test('T50 同じ口座・同じ日にもう一度入れると上書きし、前の値を監査記録に残す', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Admin);
    enterBalance($company, $user, '2026-10-31', 100000);

    enterBalance($company, $user, '2026-10-31', 120000);
    enterBalance($company, $user, '2026-10-31', 120000);

    expect($company->bankBalances()->count())->toBe(1)
        ->and($company->bankBalances()->sole()->balance_yen)->toBe(120000);

    $update = AuditLog::query()->where('action', 'bank_balance.update')->sole();
    expect($update->before)->toMatchArray(['balance_yen' => 100000])
        ->and($update->after)->toMatchArray(['balance_yen' => 120000]);

    enterBalance($company, $user, '2026-11-01', 90000);
    expect($company->bankBalances()->count())->toBe(2);
});

test('T50 取り消すと行が消え、消した値が監査記録に残る', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Owner);
    $balance = enterBalance($company, $user, '2026-10-31', 100000);

    openBalances($company, $user)->call('remove', $balance->id)->assertHasNoErrors();

    expect($company->bankBalances()->count())->toBe(0);
    $log = AuditLog::query()->where('action', 'bank_balance.delete')->sole();
    expect($log->before)->toMatchArray(['balance_yen' => 100000])
        ->and($log->after)->toBeNull();
});

test('T50 銀行でない口座・先の日付・数字でない残高は入れられない', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);

    expect(fn () => enterBalance($company, $user, '2026-10-31', 1000, bankAccountId($company, '現金')))
        ->toThrow(ValidationException::class);
    expect(fn () => enterBalance($company, $user, CarbonImmutable::tomorrow()->toDateString(), 1000))
        ->toThrow(ValidationException::class);
    expect(fn () => enterBalance($company, $user, '2026-10-31', BankBalanceService::MAX_ABS_YEN + 1))
        ->toThrow(ValidationException::class);

    openBalances($company, $user)->set('balanceYen', '12a')->call('save')->assertHasErrors('balanceYen');

    expect($company->bankBalances()->count())->toBe(0);
});

test('T50 他社の口座・他社の残高は404（会社から引く）', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);
    ['company' => $other, 'user' => $otherUser] = createCompanyWithMember(Role::Editor);
    $otherBalance = enterBalance($other, $otherUser, '2026-10-31', 5000);

    expect(fn () => enterBalance($company, $user, '2026-10-31', 1000, bankAccountId($other, BANK_NAME)))
        ->toThrow(ModelNotFoundException::class);
    expect(fn () => app(BankBalanceService::class)->remove($company, $user, $otherBalance->id))
        ->toThrow(ModelNotFoundException::class);

    $this->actingAs($user)->get(route('company.balances', $other))->assertNotFound();

    openBalances($company, $user)
        ->set('bankAccountId', bankAccountId($other, BANK_NAME))
        ->set('balanceDate', '2026-10-31')
        ->set('balanceYen', '1000')
        ->call('save')
        ->assertNotFound();
    openBalances($company, $user)->call('remove', $otherBalance->id)->assertSee('この残高はすでに取り消されています。');

    expect($other->bankBalances()->sole()->balance_yen)->toBe(5000)
        ->and($company->bankBalances()->count())->toBe(0);
});

test('T50 使っていない銀行の口座には入れられない', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);
    $account = $company->bankAccounts()->where('name', BANK_NAME)->sole();
    $account->forceFill(['archived_at' => now()])->save();

    expect(fn () => enterBalance($company, $user, '2026-10-31', 1000))->toThrow(ValidationException::class);
    expect($company->bankBalances()->count())->toBe(0);
});

test('T50 △ を付けたマイナスも読み、二度目の取り消しは案内だけ出す', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);

    $page = openBalances($company, $user)
        ->set('balanceDate', '2026-10-31')
        ->set('balanceYen', '△12,000円')
        ->call('save')
        ->assertHasNoErrors();
    $balance = $company->bankBalances()->sole();
    expect($balance->balance_yen)->toBe(-12000);

    $page->call('remove', $balance->id)->call('remove', $balance->id)
        ->assertOk()
        ->assertSee('この残高はすでに取り消されています。');
    expect(AuditLog::query()->where('action', 'bank_balance.delete')->count())->toBe(1);
});

test('T50 画面を開ける・入れられるのは owner・admin・editor だけ', function (Role $role, bool $submitOnly, int $status): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember($role, $submitOnly);

    $this->actingAs($user)->get(route('company.balances', $company))->assertStatus($status);
})->with([
    'owner' => [Role::Owner, false, 200],
    'admin' => [Role::Admin, false, 200],
    'editor' => [Role::Editor, false, 200],
    'approver' => [Role::Approver, false, 403],
    'viewer' => [Role::Viewer, false, 403],
    '送るだけ' => [Role::Editor, true, 403],
]);

test('T50 画面を開いたあとで役割が変わったら、保存も取り消しも403', function (array $change): void {
    ['company' => $company, 'user' => $user, 'membership' => $membership] = createCompanyWithMember(Role::Editor);
    $balance = enterBalance($company, $user, '2026-10-31', 100000);
    $savePage = openBalances($company, $user);
    $removePage = openBalances($company, $user);

    $membership->forceFill($change)->save();

    $savePage->set('balanceYen', '1')->call('save')->assertForbidden();
    $removePage->call('remove', $balance->id)->assertForbidden();
    expect($company->bankBalances()->sole()->balance_yen)->toBe(100000);
})->with([
    'viewer になった' => [['role' => Role::Viewer]],
    '送るだけになった' => [['submit_only' => true]],
]);

test('T50 サイドバーの「通帳の残高」は、入れられる人にだけ出る', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    ['user' => $viewer] = createCompanyWithMember(Role::Viewer, company: $company);

    $this->actingAs($editor)->get(route('company.home', $company))->assertSee('data-test="sidebar-balances"', false);
    $this->actingAs($viewer)->get(route('company.home', $company))->assertDontSee('data-test="sidebar-balances"', false);
});
