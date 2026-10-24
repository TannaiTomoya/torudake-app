<?php

use App\Enums\EntryStatus;
use App\Enums\Role;
use App\Models\User;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

beforeEach(fn () => $this->travelTo(now()->setDate(2026, 10, 24)->setTime(9, 0)));

test('社長のサイドバーは社長の画面（気になる仕訳の数）・仕訳の一覧・Excel・ライブ画面・設定。ホームは社長の画面だけを大きく出す', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    putEntry($company, '2026-10-01', '部品・修理', 120_000, flags: ['amount']);
    putEntry($company, '2026-10-02', '燃料', 5_000, EntryStatus::Draft);

    $this->actingAs($owner)->get(route('company.home', $company))
        ->assertOk()
        ->assertSee('data-test="sidebar-owner"', false)
        ->assertSee('data-test="sidebar-concern-count">1</span>', false)
        ->assertSee('data-test="sidebar-entries"', false)
        ->assertSee('data-test="sidebar-workbook"', false)
        ->assertSee('data-test="sidebar-live"', false)
        ->assertSee(route('company.settings', $company))
        ->assertDontSee('data-test="sidebar-sources"', false)
        ->assertDontSee('data-test="sidebar-submit"', false)
        ->assertDontSee('メニュー')
        ->assertSee('collapsible="true"', false)
        ->assertSee('次にやること')
        ->assertSee('data-test="owner-link"', false)
        ->assertSee('気になる仕訳が')
        ->assertDontSee('data-test="remaining"', false)
        ->assertDontSee('原資料の一覧');
});

test('経理のサイドバーは直して保存（あと n 件）・レシートを送る・仕訳の一覧・Excel・設定。社長の画面とライブ画面は出ない', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    putEntry($company, '2026-10-01', '燃料', 5_000, EntryStatus::Draft);
    putEntry($company, '2026-10-02', '燃料', 6_000, EntryStatus::Draft);
    putEntry($company, '2026-10-03', '部品・修理', 120_000, flags: ['amount']);

    $this->actingAs($editor)->get(route('company.home', $company))
        ->assertOk()
        ->assertSee('data-test="sidebar-sources"', false)
        ->assertSee('data-test="sidebar-remaining">あと2件</span>', false)
        ->assertSee('data-test="sidebar-submit"', false)
        ->assertSee('data-test="sidebar-entries"', false)
        ->assertSee('data-test="sidebar-workbook"', false)
        ->assertSee(route('profile.edit'))
        ->assertDontSee(route('company.settings', $company))
        ->assertDontSee('data-test="sidebar-owner"', false)
        ->assertDontSee('data-test="sidebar-live"', false)
        ->assertSee('data-test="remaining"', false)
        ->assertSee('直して保存する')
        ->assertDontSee('data-test="owner-link"', false);
});

test('閲覧のみは仕訳の一覧・Excel だけ。ホームは仕訳の一覧を出す', function (): void {
    ['company' => $company, 'user' => $viewer] = createCompanyWithMember(Role::Viewer);

    $this->actingAs($viewer)->get(route('company.home', $company))
        ->assertOk()
        ->assertSee('data-test="sidebar-entries"', false)
        ->assertSee('data-test="sidebar-workbook"', false)
        ->assertDontSee('data-test="sidebar-sources"', false)
        ->assertDontSee('data-test="sidebar-submit"', false)
        ->assertDontSee('data-test="sidebar-owner"', false)
        ->assertSee('data-test="entries-link"', false);
});

test('送るだけの人にはサイドバーを出さない', function (): void {
    ['company' => $company, 'user' => $submitter] = createCompanyWithMember(Role::Editor, submitOnly: true);

    $this->actingAs($submitter)->get(route('company.submit', $company))
        ->assertOk()
        ->assertDontSee('data-test="sidebar"', false)
        ->assertDontSee('data-test="sidebar-entries"', false);
});

test('サイドバーとホームの数に他社のものが混ざらない', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    ['user' => $editor] = createCompanyWithMember(Role::Editor, company: $company);
    ['company' => $other] = createCompanyWithMember(Role::Owner);
    putEntry($company, '2026-10-01', '部品・修理', 120_000, flags: ['amount']);
    putEntry($company, '2026-10-02', '燃料', 5_000, EntryStatus::Draft);
    foreach (range(1, 3) as $day) {
        putEntry($other, "2026-10-0{$day}", '部品・修理', 90_000, flags: ['amount']);
        putEntry($other, "2026-10-1{$day}", '燃料', 4_000, EntryStatus::Draft);
    }

    $this->actingAs($owner)->get(route('company.home', $company))
        ->assertSee('data-test="sidebar-concern-count">1</span>', false)
        ->assertSee('<span class="text-5xl">1</span>', false);

    $this->actingAs($editor)->get(route('company.home', $company))
        ->assertSee('data-test="sidebar-remaining">あと1件</span>', false)
        ->assertSee('1<span class="text-3xl">件</span>', false);
});

test('メニューの「設定」「ログアウト」は日本語', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);

    $this->actingAs($owner)->get(route('company.home', $company))
        ->assertSee('ログアウト')
        ->assertSee('設定')
        ->assertDontSee('Log out')
        ->assertDontSee('>Settings<', false);
});

test('送るだけの人：送る画面の右上に設定・ログアウト、設定の画面に「送る画面へ戻る」', function (): void {
    ['company' => $company, 'user' => $worker] = createCompanyWithMember(Role::Editor, submitOnly: true);
    ['user' => $editor] = createCompanyWithMember(Role::Editor, company: $company);

    $this->actingAs($worker)->get(route('company.submit', $company))
        ->assertSee('data-test="submit-settings"', false)
        ->assertSee('data-test="submit-logout"', false);
    $this->actingAs($worker)->get(route('profile.edit'))
        ->assertSee('data-test="back-to-home"', false)
        ->assertSee('送る画面へ戻る')
        ->assertSee(route('company.submit', $company));

    $this->actingAs($editor)->get(route('company.submit', $company))
        ->assertDontSee('data-test="submit-settings"', false);
});

test('admin も設定の画面から「ホームへ戻る」で会社のホームに戻れる', function (): void {
    ['company' => $company, 'user' => $admin] = createCompanyWithMember(Role::Admin);

    $this->actingAs($admin)->get(route('profile.edit'))
        ->assertSee('ホームへ戻る')
        ->assertSee(route('company.home', $company));
});

test('2社に入っている人は、設定の「戻る」もログイン後の振り分けも、今の会社（最後に開いた会社）に戻る', function (): void {
    ['company' => $first, 'user' => $user] = createCompanyWithMember(Role::Admin);
    ['company' => $second] = createCompanyWithMember(Role::Owner);
    createCompanyWithMember(Role::Editor, submitOnly: true, company: $second)['membership']->forceFill(['user_id' => $user->id])->save();

    $this->actingAs($user)->get(route('profile.edit'))->assertSee(route('company.home', $first));

    $this->actingAs($user)->get(route('company.submit', $second))->assertOk();

    $this->actingAs($user)->get(route('profile.edit'))
        ->assertSee('送る画面へ戻る')
        ->assertSee(route('company.submit', $second))
        ->assertDontSee(route('company.home', $first));
    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('company.submit', $second));
});

test('2段階認証を設定し終えると、その人の最初の画面へ移る（送るだけは送る画面・admin はホーム・2社なら今の会社）', function (Role $role, bool $submitOnly, string $route): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember($role, submitOnly: $submitOnly, withTwoFactor: false);

    app(EnableTwoFactorAuthentication::class)($user);
    $code = app(Google2FA::class)->getCurrentOtp(decrypt((string) $user->fresh()?->two_factor_secret));

    Livewire::actingAs($user)
        ->test('pages::settings.two-factor-setup-modal', ['requiresConfirmation' => true])
        ->set('code', $code)
        ->call('confirmTwoFactor')
        ->assertHasNoErrors()
        ->assertNoRedirect()
        ->call('finish')
        ->assertRedirect(route($route, $company));

    expect($user->fresh()?->two_factor_confirmed_at)->not->toBeNull();
})->with([
    '送るだけ' => [Role::Editor, true, 'company.submit'],
    'admin' => [Role::Admin, false, 'company.home'],
]);

test('2社に入っている人は、2段階認証を設定し終えると今の会社へ移る', function (): void {
    ['company' => $first, 'user' => $user] = createCompanyWithMember(Role::Admin, withTwoFactor: false);
    ['company' => $second] = createCompanyWithMember(Role::Owner);
    createCompanyWithMember(Role::Admin, company: $second)['membership']->forceFill(['user_id' => $user->id])->save();

    app(EnableTwoFactorAuthentication::class)($user);
    $code = app(Google2FA::class)->getCurrentOtp(decrypt((string) $user->fresh()?->two_factor_secret));

    session([User::CURRENT_COMPANY_SESSION_KEY => $second->id]);

    Livewire::actingAs($user)
        ->test('pages::settings.two-factor-setup-modal', ['requiresConfirmation' => true])
        ->set('code', $code)
        ->call('confirmTwoFactor')
        ->call('finish')
        ->assertRedirect(route('company.home', $second));
});

test('送る画面：今月0枚なら手順の案内を出し、枚数は出さない。1枚送ったら案内は出さない', function (): void {
    ['company' => $company, 'user' => $worker] = createCompanyWithMember(Role::Editor, submitOnly: true);

    $this->actingAs($worker)->get(route('company.submit', $company))
        ->assertSee('レシートを送る')
        ->assertSee('data-test="first-guide"', false)
        ->assertSee('金額を書き写す必要はありません。経理が確かめて帳簿に入れます。')
        ->assertDontSee('data-test="month-count"', false);

    sendReceipt($company, $worker);

    $this->actingAs($worker)->get(route('company.submit', $company))
        ->assertDontSee('data-test="first-guide"', false)
        ->assertSee('data-test="month-count">1枚', false);
});
