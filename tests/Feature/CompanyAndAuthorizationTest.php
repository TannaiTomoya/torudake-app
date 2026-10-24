<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use Database\Seeders\CompanyDefaultSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Helper to create a company with default accounts/categories/bank accounts and a member.
 *
 * @return array{company: Company, user: User, membership: Membership}
 */
function createCompanyWithMember(
    Role $role = Role::Owner,
    bool $submitOnly = false,
    bool $withTwoFactor = true,
    ?Company $company = null,
): array {
    $company ??= Company::factory()->create(['name' => 'テスト建機']);
    CompanyDefaultSeeder::seedForCompany($company);

    $userFactory = User::factory();
    if ($withTwoFactor) {
        $userFactory = $userFactory->withTwoFactor();
    }
    $user = $userFactory->create();

    $membership = new Membership;
    $membership->forceFill([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'role' => $role,
        'submit_only' => $submitOnly,
        'disabled_at' => null,
    ])->save();

    return ['company' => $company, 'user' => $user, 'membership' => $membership];
}

test('T02: 2段階認証が未設定の人は会社の画面に入れず設定画面へリダイレクトされる', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(
        role: Role::Owner,
        withTwoFactor: false,
    );

    $this->actingAs($user)
        ->get(route('company.home', $company))
        ->assertRedirect(route('security.edit'));

    $this->actingAs($user)
        ->get(route('company.submit', $company))
        ->assertRedirect(route('security.edit'));

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('security.edit'));
});

test('T03: ログインから24時間を過ぎたらログアウトされる', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Owner);

    $this->actingAs($user)
        ->get(route('company.home', $company))
        ->assertOk();

    $this->travel(23)->hours();
    $this->get(route('company.home', $company))->assertOk();
    $this->assertAuthenticatedAs($user);

    $this->travel(2)->hours();
    $this->get(route('company.home', $company))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('T04: 会社Aの人が会社BのURLを開くとすべて404になり、Livewireのアクションに他社のIDを渡しても404になる', function (): void {
    ['company' => $companyA, 'user' => $userA] = createCompanyWithMember(Role::Owner);
    ['company' => $companyB] = createCompanyWithMember(Role::Owner);

    $this->actingAs($userA);

    $urls = [
        route('company.home', $companyB),
        route('company.submit', $companyB),
        route('company.sources.index', $companyB),
        route('company.sources.show', [$companyB, '01900000-0000-7000-8000-000000000001']),
        route('company.entries.index', $companyB),
        route('company.entries.show', [$companyB, '01900000-0000-7000-8000-000000000002']),
        route('company.close', [$companyB, '2026-10']),
        route('company.live', $companyB),
        route('company.ai', $companyB),
        route('company.settings', $companyB),
        route('company.evidences.image', [$companyB, '01900000-0000-7000-8000-000000000003']),
        route('company.exports.excel', [$companyB, '2026-10']),
        route('company.exports.training', $companyB),
    ];

    foreach ($urls as $url) {
        $this->get($url)->assertNotFound();
    }

    // 他社の分類ID・口座IDを会社AのLivewireアクションに渡しても404になり書き換わらない
    $categoryB = $companyB->categories()->firstOrFail();
    $originalCategoryName = $categoryB->name;

    Livewire::test('pages::company.settings', ['company' => $companyA])
        ->call('renameCategory', $categoryB->id, '書き換えテスト')
        ->assertNotFound();

    expect($categoryB->refresh()->name)->toBe($originalCategoryName);

    $bankAccountB = $companyB->bankAccounts()->where('is_default_for_receipts', false)->firstOrFail();

    Livewire::test('pages::company.settings', ['company' => $companyA])
        ->call('setDefaultBankAccount', $bankAccountB->id)
        ->assertNotFound();

    expect($bankAccountB->refresh()->is_default_for_receipts)->toBeFalse();

    // 会社Aの設定画面を開いたあと、会社Bのユーザーに切り替えてアクションを直接呼んでも403で拒否されデータが変わらない
    ['user' => $userB] = createCompanyWithMember(Role::Owner, company: $companyB);
    $component = Livewire::actingAs($userA)->test('pages::company.settings', ['company' => $companyA]);

    $this->actingAs($userB);
    $component->set('name', '乗っ取り社名')->call('saveCompany')->assertForbidden();
    expect($companyA->refresh()->name)->toBe('テスト建機');
});

test('T04: 退職済み（disabled_atあり）のメンバーは会社の画面を開くと404になる', function (): void {
    ['company' => $company, 'user' => $user, 'membership' => $membership] = createCompanyWithMember(Role::Owner);
    $membership->forceFill(['disabled_at' => now()])->save();

    $this->actingAs($user)
        ->get(route('company.home', $company))
        ->assertNotFound();
});

test('T05: 権限の表（§5）をロールごとに検証する', function (): void {
    $company = Company::factory()->create(['allow_self_approval' => false]);
    CompanyDefaultSeeder::seedForCompany($company);

    $matrix = [
        ['role' => Role::Owner, 'submit_only' => false, 'expected' => [
            'submit' => true,
            'viewSources' => true,
            'editDraft' => true,
            'approve' => true,
            'recordCash' => true,
            'viewClose' => true,
            'exportExcel' => true,
            'close' => true,
            'viewLive' => true,
            'viewAi' => true,
            'exportTrainingData' => true,
            'manageSettings' => true,
        ]],
        ['role' => Role::Admin, 'submit_only' => false, 'expected' => [
            'submit' => true,
            'viewSources' => true,
            'editDraft' => true,
            'approve' => true,
            'recordCash' => true,
            'viewClose' => true,
            'exportExcel' => true,
            'close' => false,
            'viewLive' => true,
            'viewAi' => true,
            'exportTrainingData' => true,
            'manageSettings' => true,
        ]],
        ['role' => Role::Approver, 'submit_only' => false, 'expected' => [
            'submit' => true,
            'viewSources' => true,
            'editDraft' => true,
            'approve' => true,
            'recordCash' => true,
            'viewClose' => true,
            'exportExcel' => true,
            'close' => false,
            'viewLive' => false,
            'viewAi' => false,
            'exportTrainingData' => false,
            'manageSettings' => false,
        ]],
        ['role' => Role::Editor, 'submit_only' => false, 'expected' => [
            'submit' => true,
            'viewSources' => true,
            'editDraft' => true,
            'approve' => false,
            'recordCash' => true,
            'viewClose' => true,
            'exportExcel' => true,
            'close' => false,
            'viewLive' => false,
            'viewAi' => false,
            'exportTrainingData' => false,
            'manageSettings' => false,
        ]],
        ['role' => Role::Viewer, 'submit_only' => false, 'expected' => [
            'submit' => false,
            'viewSources' => true,
            'editDraft' => false,
            'approve' => false,
            'recordCash' => false,
            'viewClose' => true,
            'exportExcel' => true,
            'close' => false,
            'viewLive' => false,
            'viewAi' => false,
            'exportTrainingData' => false,
            'manageSettings' => false,
        ]],
        ['role' => Role::Editor, 'submit_only' => true, 'expected' => [
            'submit' => true,
            'viewSources' => false,
            'editDraft' => false,
            'approve' => false,
            'recordCash' => false,
            'viewClose' => false,
            'exportExcel' => false,
            'close' => false,
            'viewLive' => false,
            'viewAi' => false,
            'exportTrainingData' => false,
            'manageSettings' => false,
        ]],
    ];

    foreach ($matrix as $case) {
        ['user' => $user] = createCompanyWithMember(
            role: $case['role'],
            submitOnly: $case['submit_only'],
            company: $company,
        );

        foreach ($case['expected'] as $ability => $allowed) {
            expect(Gate::forUser($user)->allows($ability, $company))
                ->toBe($allowed, "{$case['role']->value} (submit_only=".($case['submit_only'] ? 'true' : 'false').") の {$ability}");
        }

        // HTTP ルートでも 200 / 403 が権限表と一致することを確かめる
        $this->actingAs($user);

        $this->get(route('company.submit', $company))
            ->assertStatus($case['expected']['submit'] ? 200 : 403);

        $this->get(route('company.sources.index', $company))
            ->assertStatus($case['expected']['viewSources'] ? 200 : 403);

        $this->get(route('company.sources.show', [$company, '01900000-0000-7000-8000-000000000001']))
            ->assertStatus($case['expected']['editDraft'] ? 200 : 403);

        $this->get(route('company.entries.show', [$company, '01900000-0000-7000-8000-000000000002']))
            ->assertStatus($case['expected']['approve'] ? 200 : 403);

        $this->get(route('company.close', [$company, '2026-10']))
            ->assertStatus($case['expected']['viewClose'] ? 200 : 403);

        $this->get(route('company.exports.excel', [$company, '2026-10']))
            ->assertStatus($case['expected']['exportExcel'] ? 200 : 403);

        $this->get(route('company.live', $company))
            ->assertStatus($case['expected']['viewLive'] ? 200 : 403);

        $this->get(route('company.ai', $company))
            ->assertStatus($case['expected']['viewAi'] ? 200 : 403);

        $this->get(route('company.exports.training', $company))
            ->assertStatus($case['expected']['exportTrainingData'] ? 200 : 403);

        $this->get(route('company.settings', $company))
            ->assertStatus($case['expected']['manageSettings'] ? 200 : 403);
    }

    // 自己承認の制限（allow_self_approval = false のとき自分が作った下書きは承認不可）
    ['user' => $owner] = createCompanyWithMember(Role::Owner, company: $company);
    expect(Gate::forUser($owner)->allows('approve', [$company, $owner->id]))->toBeFalse();
    expect(Gate::forUser($owner)->allows('approve', [$company, $owner->id + 999]))->toBeTrue();
});

test('T20: 監査記録に各操作が残り、機密情報が含まれず、更新・削除はできない', function (): void {
    Notification::fake();

    $this->artisan('torudake:create-company', [
        'name' => '丹内建機サービス',
        '--fiscal-year-end' => 7,
        '--demo' => true,
    ])->assertSuccessful();

    $company = Company::where('name', '丹内建機サービス')->firstOrFail();

    expect($company->accounts()->count())->toBe(19)
        ->and($company->categories()->count())->toBe(9)
        ->and($company->bankAccounts()->count())->toBe(4);

    $this->artisan('torudake:add-member', [
        'company' => $company->id,
        'email' => 'boss@example.com',
        'role' => 'owner',
        '--name' => '社長',
    ])->assertSuccessful();

    $owner = User::where('email', 'boss@example.com')->firstOrFail();
    Notification::assertSentTo($owner, ResetPassword::class);

    $owner->forceFill([
        'two_factor_secret' => encrypt('secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    Livewire::actingAs($owner)
        ->test('pages::company.settings', ['company' => $company])
        ->set('name', '有限会社 丹内建機サービス')
        ->set('fiscalYearEndMonth', 7)
        ->set('allowSelfApproval', false)
        ->call('saveCompany')
        ->assertHasNoErrors();

    $logActions = AuditLog::where('company_id', $company->id)->pluck('action')->all();
    expect($logActions)->toContain('company.create', 'member.add', 'company.settings.update');

    // 機密キーは自動的に取り除かれる
    $log = AuditLog::record(
        user: $owner,
        action: 'user.test',
        subject: $owner,
        before: ['password' => 'secret-pass', 'name' => '旧名'],
        after: ['two_factor_secret' => 'totp-secret', 'name' => '新名'],
        company: $company,
    );

    expect($log->before)->toBe(['name' => '旧名'])
        ->and($log->after)->toBe(['name' => '新名']);

    // 監査記録は更新・削除できない
    expect(fn () => $log->forceFill(['action' => 'tampered'])->save())->toThrow(LogicException::class);
    expect(fn () => $log->delete())->toThrow(LogicException::class);
});

test('T21: 送るだけの人は送る画面以外が403になり、原資料は自分が送ったものだけ見られる', function (): void {
    ['company' => $company, 'user' => $submitter] = createCompanyWithMember(
        role: Role::Editor,
        submitOnly: true,
    );
    ['user' => $otherMember] = createCompanyWithMember(
        role: Role::Editor,
        submitOnly: false,
        company: $company,
    );

    // 振り分け：送るだけの人は / と /dashboard から送る画面へ、通常のメンバーはホームへ
    $this->actingAs($submitter)
        ->get(route('home'))
        ->assertRedirect(route('company.submit', $company));

    $this->actingAs($submitter)
        ->get(route('dashboard'))
        ->assertRedirect(route('company.submit', $company));

    $this->actingAs($otherMember)
        ->get(route('home'))
        ->assertRedirect(route('company.home', $company));

    // 送る画面は開けるが、それ以外の画面はすべて403
    $this->actingAs($submitter)
        ->get(route('company.submit', $company))
        ->assertOk();

    $forbiddenUrls = [
        route('company.home', $company),
        route('company.sources.index', $company),
        route('company.sources.show', [$company, '01900000-0000-7000-8000-000000000001']),
        route('company.entries.index', $company),
        route('company.entries.show', [$company, '01900000-0000-7000-8000-000000000002']),
        route('company.close', [$company, '2026-10']),
        route('company.live', $company),
        route('company.ai', $company),
        route('company.settings', $company),
        route('company.exports.excel', [$company, '2026-10']),
        route('company.exports.training', $company),
    ];

    foreach ($forbiddenUrls as $url) {
        $this->actingAs($submitter)->get($url)->assertForbidden();
    }

    // 原資料の閲覧権限は自分が送ったものだけ true
    expect(Gate::forUser($submitter)->allows('viewSource', [$company, $submitter->id]))->toBeTrue()
        ->and(Gate::forUser($submitter)->allows('viewSource', [$company, $otherMember->id]))->toBeFalse()
        ->and(Gate::forUser($otherMember)->allows('viewSource', [$company, $submitter->id]))->toBeTrue();
});
