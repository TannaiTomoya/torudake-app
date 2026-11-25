<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Services\CompanySettingsService;
use App\Services\OwnerDashboard;
use App\Support\CustomerConcentration;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(fn () => $this->travelTo(now()->setDate(2026, 10, 24)->setTime(9, 0)));

test('T57 取引先への依存度（規則）：Python（tools/analysis）と共通の架空ベクトルで同じ答えになる', function (): void {
    $vectors = json_decode((string) file_get_contents(base_path('tests/fixtures/concentration_vectors.json')), true, 512, JSON_THROW_ON_ERROR);
    expect($vectors['cases'])->not->toBeEmpty()->and($vectors['shares'])->not->toBeEmpty();

    foreach ($vectors['cases'] as $case) {
        expect(CustomerConcentration::compute($case['amounts'], $case['alert_pct']))->toBe($case['expected'], $case['name']);
    }
    foreach ($vectors['shares'] as $case) {
        expect(CustomerConcentration::sharePct($case['amount'], $case['total']))->toBe($case['expected'], $case['name']);
    }
});

test('T57 規則：知らせる割合が1〜100でない・金額が整数でないときは計算しない', function (array $amounts, int $alertPct): void {
    expect(fn () => CustomerConcentration::compute($amounts, $alertPct))->toThrow(InvalidArgumentException::class);
})->with([
    '割合が0' => [[100], 0],
    '割合が101' => [[100], 101],
    '金額が小数' => [[100, 1.5], 70],
    '金額が文字' => [[100, '100'], 70],
    '金額がない' => [[100, null], 70],
]);

test('T57 社長の画面：⑤売上の柱に上位1社・上位2社の割合を添え、会社設定の割合を超えたら ⑤ の中で知らせる', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    putCustomer($company, 1, 'A社', 5_000_000);
    putCustomer($company, 2, 'B社', 3_000_000);
    putCustomer($company, 3, 'C社', 1_000_000);
    putCustomer($company, 4, 'D社', 1_000_000);

    $customers = app(OwnerDashboard::class)->build($company)['customers'];
    expect($company->customer_concentration_alert_pct)->toBe(70)
        ->and($customers['concentration'] ?? null)->toBe([
            'customer_count' => 4, 'total_yen' => 10_000_000,
            'top1_yen' => 5_000_000, 'top1_pct' => 50,
            'top2_yen' => 8_000_000, 'top2_pct' => 80,
            'alert_pct' => 70, 'over' => true,
        ])
        // ⑤ の A社 の割合と、上位1社の割合は同じ数え方
        ->and($customers['top'][0]['share_pct'] ?? null)->toBe(50)
        ->and(array_column($customers['top'] ?? [], 'share_pct'))->toBe([50, 30, 10]);

    $html = $this->actingAs($owner)->get(route('company.owner', $company))->assertOk()->getContent();
    preg_match('/data-test="owner-customers">(.*?)<\/section>/s', $html, $pillar);

    // 新しい欄は作らない：知らせは ⑤（売上の柱）の中だけにある
    expect($pillar[1] ?? '')->toContain('data-test="owner-concentration"')
        ->toContain('上位1社で <span class="font-bold">50%</span>・上位2社で <span class="font-bold">80%</span>')
        ->toContain('data-test="owner-concentration-alert"')
        ->toContain('上位2社で売上の80%です。会社設定の割合（70%）を超えています。')
        ->and(substr_count($html, 'data-test="owner-concentration-alert"'))->toBe(1)
        ->and(substr_count($html, 'data-test="owner-concentration"'))->toBe(1);
});

test('T57 社長の画面：割合が会社設定とちょうど同じなら知らせず、割合だけ出す。設定を変えると判定も変わる', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    putCustomer($company, 1, 'A社', 4_000_000);
    putCustomer($company, 2, 'B社', 3_000_000);
    putCustomer($company, 3, 'C社', 3_000_000);

    $this->actingAs($owner)->get(route('company.owner', $company))
        ->assertOk()
        ->assertSee('上位1社で <span class="font-bold">40%</span>・上位2社で <span class="font-bold">70%</span>', false)
        ->assertSee('上位2社の割合が70%を超えたら、ここで知らせます。')
        ->assertDontSee('data-test="owner-concentration-alert"', false);

    $company->forceFill(['customer_concentration_alert_pct' => 69])->save();

    $this->get(route('company.owner', $company))
        ->assertOk()
        ->assertSee('上位2社で売上の70%です。会社設定の割合（69%）を超えています。');
});

test('T57 社長の画面：取引先が1社だけなら「1社だけ」と出す。売上の合計が0円・取り込みなしなら出さない', function (): void {
    ['company' => $single, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    putCustomer($single, 1, 'A社', 3_000_000);

    $this->actingAs($owner)->get(route('company.owner', $single))
        ->assertOk()
        ->assertSee('取引先は1社だけです（<span class="font-bold">100%</span>）', false)
        ->assertSee('売上のすべてが1社です。会社設定の割合（70%）を超えています。');

    ['company' => $zero, 'user' => $zeroOwner] = createCompanyWithMember(Role::Owner);
    putCustomer($zero, 1, 'A社', 0);
    putCustomer($zero, 2, 'B社', 0);

    $zeroCustomers = app(OwnerDashboard::class)->build($zero)['customers'];
    expect($zeroCustomers)->toBeArray()->toHaveKey('concentration')
        ->and($zeroCustomers['concentration'] ?? null)->toBeNull();
    $this->actingAs($zeroOwner)->get(route('company.owner', $zero))
        ->assertOk()
        ->assertSee('売上の柱')
        ->assertDontSee('data-test="owner-concentration"', false);

    ['company' => $none, 'user' => $noneOwner] = createCompanyWithMember(Role::Owner);
    $this->actingAs($noneOwner)->get(route('company.owner', $none))
        ->assertOk()
        ->assertSee('顧客別の売上はまだ取り込まれていません')
        ->assertDontSee('data-test="owner-concentration"', false);
});

test('T57 他社の取引先の売上は数えない', function (): void {
    ['company' => $company] = createCompanyWithMember(Role::Owner);
    ['company' => $other] = createCompanyWithMember(Role::Owner);
    putCustomer($company, 1, 'A社', 4_000_000);
    putCustomer($company, 2, 'B社', 6_000_000);
    putCustomer($other, 1, 'A社', 90_000_000);

    expect(app(OwnerDashboard::class)->build($company)['customers']['concentration'] ?? null)
        ->toMatchArray(['customer_count' => 2, 'total_yen' => 10_000_000, 'top1_yen' => 6_000_000, 'top1_pct' => 60, 'top2_pct' => 100]);
});

test('T57 名前は保存しない：取引先ごとの売上の表に、順位・呼び名・金額のほかの列を足していない', function (): void {
    expect(Schema::getColumnListing('external_customer_sales'))->toEqualCanonicalizing([
        'id', 'company_id', 'period_from', 'period_to', 'rank', 'label', 'amount_yen', 'report_import_id', 'created_at', 'updated_at',
    ]);
});

test('T57 会社設定：社長は割合を変えられ、監査に前後が残る。同じ値の保存では監査を増やさない', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);

    $component = Livewire::actingAs($owner)->test('pages::company.settings', ['company' => $company])
        ->assertSet('concentrationAlertPct', 70)
        ->assertSee('取引先への依存の知らせ')
        ->assertSee('割合を保存')
        ->assertDontSee('変更は社長に依頼してください。')
        ->set('concentrationAlertPct', 60)
        ->call('saveConcentrationAlert')
        ->assertHasNoErrors()
        ->assertSee('割合を保存しました。');

    expect($company->refresh()->customer_concentration_alert_pct)->toBe(60);

    $log = AuditLog::query()->where('action', 'company.concentration_alert.update')->sole();
    expect($log->company_id)->toBe($company->id)
        ->and($log->user_id)->toBe($owner->id)
        ->and($log->before)->toBe(['customer_concentration_alert_pct' => 70])
        ->and($log->after)->toBe(['customer_concentration_alert_pct' => 60]);

    $component->call('saveConcentrationAlert')->assertHasNoErrors();
    expect(AuditLog::query()->where('action', 'company.concentration_alert.update')->count())->toBe(1);
});

test('T57 会社設定：割合は1〜100の整数だけ。それ以外は保存しない', function (mixed $value): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);

    Livewire::actingAs($owner)->test('pages::company.settings', ['company' => $company])
        ->set('concentrationAlertPct', $value)
        ->call('saveConcentrationAlert')
        ->assertHasErrors('concentrationAlertPct')
        ->assertSee('割合は1〜100の整数で入れてください。');

    expect($company->refresh()->customer_concentration_alert_pct)->toBe(70)
        ->and(AuditLog::query()->where('action', 'company.concentration_alert.update')->count())->toBe(0);
})->with(['0' => 0, '101' => 101, 'マイナス' => -5, '小数' => '70.5', '文字' => 'abc', '空' => '']);

test('T57 会社設定：1 と 100 は保存できる', function (int $value): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);

    Livewire::actingAs($owner)->test('pages::company.settings', ['company' => $company])
        ->set('concentrationAlertPct', $value)
        ->call('saveConcentrationAlert')
        ->assertHasNoErrors();

    expect($company->refresh()->customer_concentration_alert_pct)->toBe($value);
})->with([1, 100]);

test('T57 サービス：1〜100 の外は、画面を通さずに呼んでも保存しない', function (int $value): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);

    expect(fn () => app(CompanySettingsService::class)->updateConcentrationAlert($company, $owner, $value))
        ->toThrow(ValidationException::class);

    expect($company->refresh()->customer_concentration_alert_pct)->toBe(70)
        ->and(AuditLog::query()->where('action', 'company.concentration_alert.update')->count())->toBe(0);
})->with([0, 101, -1, 255]);

test('T57 割合は一括代入では変わらない', function (): void {
    ['company' => $company] = createCompanyWithMember(Role::Owner);

    $company->fill(['customer_concentration_alert_pct' => 5, 'name' => '一括代入建機'])->save();

    expect($company->refresh()->name)->toBe('一括代入建機')
        ->and($company->customer_concentration_alert_pct)->toBe(70);
});

test('T57 DB に範囲の外の値が入っていても社長の画面と会社設定は開き、初期値の70で判定する', function (int $stored): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    $company->forceFill(['customer_concentration_alert_pct' => $stored])->save();
    putCustomer($company, 1, 'A社', 5_000_000);
    putCustomer($company, 2, 'B社', 3_000_000);
    putCustomer($company, 3, 'C社', 2_000_000);

    expect($company->refresh()->concentrationAlertPct())->toBe(70);

    $this->actingAs($owner)->get(route('company.owner', $company))
        ->assertOk()
        ->assertSee('上位2社で売上の80%です。会社設定の割合（70%）を超えています。');

    Livewire::actingAs($owner)->test('pages::company.settings', ['company' => $company])
        ->assertSet('concentrationAlertPct', 70)
        ->call('saveConcentrationAlert')
        ->assertHasNoErrors();

    expect($company->refresh()->customer_concentration_alert_pct)->toBe(70);
})->with([0, 101, 255]);

test('T57 返品で合計より大きくなっても、割合は100%までにする（A社の割合も同じ）', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    putCustomer($company, 1, 'A社', 1_000_000);
    putCustomer($company, 2, 'B社', -100_000);

    $customers = app(OwnerDashboard::class)->build($company)['customers'];
    expect($customers['concentration'] ?? null)->toMatchArray(['top1_pct' => 100, 'top2_pct' => 100, 'over' => true])
        ->and(array_column($customers['top'] ?? [], 'share_pct'))->toBe([100, -11]);

    $company->forceFill(['customer_concentration_alert_pct' => 100])->save();
    $this->actingAs($owner)->get(route('company.owner', $company))
        ->assertOk()
        ->assertDontSee('data-test="owner-concentration-alert"', false);
});

test('T57 管理者も社長の画面で知らせを見られる（割合は変えられない）', function (): void {
    ['company' => $company] = createCompanyWithMember(Role::Owner);
    ['user' => $admin] = createCompanyWithMember(Role::Admin, company: $company);
    putCustomer($company, 1, 'A社', 9_000_000);
    putCustomer($company, 2, 'B社', 1_000_000);

    $this->actingAs($admin)->get(route('company.owner', $company))
        ->assertOk()
        ->assertSee('上位2社で売上の100%です。会社設定の割合（70%）を超えています。');
});

test('T57 権限：割合を変えられるのは社長だけ。管理者は数字が見えるだけで、保存は403', function (): void {
    ['company' => $company] = createCompanyWithMember(Role::Owner);
    ['user' => $admin] = createCompanyWithMember(Role::Admin, company: $company);

    $component = Livewire::actingAs($admin)->test('pages::company.settings', ['company' => $company])
        ->assertSee('知らせる割合：')
        ->assertSeeHtml('<span class="font-bold">70%</span>')
        ->assertSee('変更は社長に依頼してください。')
        ->assertDontSee('割合を保存');

    $component->set('concentrationAlertPct', 99)->call('saveConcentrationAlert')->assertForbidden();

    expect($company->refresh()->customer_concentration_alert_pct)->toBe(70)
        ->and(AuditLog::query()->where('action', 'company.concentration_alert.update')->count())->toBe(0);
});

test('T57 権限：管理者が会社設定のほかの欄を保存しても、割合は変わらない', function (): void {
    ['company' => $company] = createCompanyWithMember(Role::Owner);
    ['user' => $admin] = createCompanyWithMember(Role::Admin, company: $company);

    Livewire::actingAs($admin)->test('pages::company.settings', ['company' => $company])
        ->set('concentrationAlertPct', 99)
        ->set('name', '名前を変えた建機')
        ->call('saveCompany')
        ->assertHasNoErrors();

    expect($company->refresh()->name)->toBe('名前を変えた建機')
        ->and($company->customer_concentration_alert_pct)->toBe(70);
});

test('T57 権限：役割ごとの可否（許可は社長だけ。送るだけの人は社長の役割でも不可）', function (Role $role, bool $submitOnly, bool $allowed): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember($role, $submitOnly);

    expect(Gate::forUser($user)->allows('manageConcentrationAlert', $company))->toBe($allowed);
})->with([
    'owner' => [Role::Owner, false, true],
    'admin' => [Role::Admin, false, false],
    'approver' => [Role::Approver, false, false],
    'editor' => [Role::Editor, false, false],
    'viewer' => [Role::Viewer, false, false],
    '送るだけ' => [Role::Editor, true, false],
    '送るだけの owner' => [Role::Owner, true, false],
]);

test('T57 権限：2段階認証がまだの社長・他社の社長・止められた社長は割合を変えられない', function (): void {
    ['company' => $company, 'user' => $owner, 'membership' => $membership] = createCompanyWithMember(Role::Owner);
    ['company' => $otherCompany, 'user' => $otherOwner] = createCompanyWithMember(Role::Owner);
    ['user' => $noTwoFactor] = createCompanyWithMember(Role::Owner, withSecurityAnswer: false, company: $company);

    expect(Gate::forUser($noTwoFactor)->allows('manageConcentrationAlert', $company))->toBeFalse()
        ->and(Gate::forUser($otherOwner)->allows('manageConcentrationAlert', $company))->toBeFalse();

    // 他社の会社設定は開けない（404）
    $this->actingAs($otherOwner)->get(route('company.settings', $company))->assertNotFound();

    // 画面を開いたあとに他社の社長へ切り替えて、アクションを直接呼んでも変わらない
    $component = Livewire::actingAs($owner)->test('pages::company.settings', ['company' => $company]);
    $this->actingAs($otherOwner);
    $component->set('concentrationAlertPct', 5)->call('saveConcentrationAlert')->assertForbidden();

    // 画面を開いたあとに所属を止められた社長も変えられない
    $this->actingAs($owner);
    $component = Livewire::actingAs($owner)->test('pages::company.settings', ['company' => $company]);
    $membership->forceFill(['disabled_at' => now()])->save();
    $component->set('concentrationAlertPct', 5)->call('saveConcentrationAlert')->assertForbidden();

    expect($company->refresh()->customer_concentration_alert_pct)->toBe(70)
        ->and($otherCompany->refresh()->customer_concentration_alert_pct)->toBe(70);
});

test('T57 権限：知らせ（上位2社の割合）は社長の画面の中だけ。経理・閲覧のみの人には出ない', function (Role $role): void {
    ['company' => $company] = createCompanyWithMember(Role::Owner);
    ['user' => $user] = createCompanyWithMember($role, company: $company);
    putCustomer($company, 1, 'A社', 9_000_000);
    putCustomer($company, 2, 'B社', 1_000_000);

    $this->actingAs($user)->get(route('company.owner', $company))->assertForbidden();
    $this->get(route('company.home', $company))->assertOk()->assertDontSee('上位2社');
})->with([Role::Editor, Role::Viewer]);
