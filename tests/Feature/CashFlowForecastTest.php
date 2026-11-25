<?php

use App\Enums\Role;
use App\Enums\ScheduleCategory;
use App\Models\Company;
use App\Models\User;
use App\Services\BankBalanceService;
use App\Services\CashFlowBoard;
use App\Services\CashScheduleInput;
use App\Services\CashScheduleService;
use App\Services\TrainingDataExporter;
use App\Support\CashFlowForecast;
use App\Support\WeekendCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use ZipArchive;

// #68：資金繰り表。名前・日付・金額はすべて架空

const CASHFLOW_BANK = '〇〇銀行（普通預金）';
const CASHFLOW_PARTNER = 'みちのく架空建設';

beforeEach(fn () => $this->travelTo(now()->setDate(2026, 11, 21)->setTime(9, 0)));

function cashflowPage(Company $company, User $user)
{
    return Livewire::actingAs($user)->test('pages::company.cashflow', ['company' => $company]);
}

function cashflowEnterBalance(Company $company, User $user, string $date, int $yen): void
{
    app(BankBalanceService::class)->enter(
        $company,
        $user,
        bankAccountId($company, CASHFLOW_BANK),
        CarbonImmutable::parse($date),
        $yen,
    );
}

function cashflowAddOut(Company $company, User $user, int $yen, string $due): void
{
    // 給料（全員の合計）なら取引先なし。同じ名前の取引先を何度も足さない
    app(CashScheduleService::class)->add($company, $user, new CashScheduleInput(
        direction: ScheduleCategory::Salary->direction(),
        category: ScheduleCategory::Salary,
        partnerId: null,
        bankAccountId: bankAccountId($company, CASHFLOW_BANK),
        amountYen: $yen,
        dueDate: CarbonImmutable::parse($due),
    ));
}

test('T59 土日と資金繰り（規則）：Python（tools/analysis）と共通の架空ベクトルで同じ答えになる', function (): void {
    $vectors = json_decode((string) file_get_contents(base_path('tests/fixtures/cashflow_vectors.json')), true, 512, JSON_THROW_ON_ERROR);

    foreach ($vectors['weekend'] as $case) {
        expect(WeekendCalendar::isWeekend($case['date']))->toBe($case['expected'], $case['name']);
    }
    foreach ($vectors['month_end_weekend'] as $case) {
        expect(WeekendCalendar::monthEndsOnWeekendInRange($case['from'], $case['to']))->toBe($case['expected'], $case['name']);
    }
    foreach ($vectors['project'] as $case) {
        expect(CashFlowForecast::project($case['opening'], $case['from'], $case['to'], $case['items']))
            ->toBe($case['expected'], $case['name']);
    }
});

test('T59 規則：正しくない日付や金額は判定しない', function (callable $call): void {
    expect($call)->toThrow(InvalidArgumentException::class);
})->with([
    'ない日付' => [fn () => CashFlowForecast::project(0, '2026-02-30', '2026-03-01', [])],
    '終了が前' => [fn () => CashFlowForecast::project(0, '2026-11-22', '2026-11-21', [])],
    '金額0' => [fn () => CashFlowForecast::project(0, '2026-11-21', '2026-11-21', [['due_date' => '2026-11-21', 'direction' => 'in', 'amount_yen' => 0]])],
    '方向が違う' => [fn () => CashFlowForecast::project(0, '2026-11-21', '2026-11-21', [['due_date' => '2026-11-21', 'direction' => 'both', 'amount_yen' => 1]])],
    '土日の不正日付' => [fn () => WeekendCalendar::isWeekend('2026/11/21')],
]);

test('T59 画面：残高がなければ見込みを出さず「残高が未入力」。0円とはみなさない', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);

    cashflowPage($company, $owner)
        ->assertOk()
        ->assertSee('残高が未入力')
        ->assertSee('0円とはみなしません')
        ->assertDontSee('data-test="cashflow-table"', false);

    expect(app(CashFlowBoard::class)->forAccount($company, bankAccountId($company, CASHFLOW_BANK))['missing_balance'])->toBeTrue();
});

test('T59 画面：残高と予定から日ごとに並べ、土日に休日の印、0円未満に文言と色の印。取引先の名前は出さない', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    cashflowEnterBalance($company, $owner, '2026-11-20', 100_000);
    $partner = app(CashScheduleService::class)->addPartner($company, $owner, CASHFLOW_PARTNER);
    app(CashScheduleService::class)->add($company, $owner, new CashScheduleInput(
        direction: ScheduleCategory::Purchase->direction(),
        category: ScheduleCategory::Purchase,
        partnerId: $partner->id,
        bankAccountId: bankAccountId($company, CASHFLOW_BANK),
        amountYen: 150_000,
        dueDate: CarbonImmutable::parse('2026-11-25'),
    ));

    $page = cashflowPage($company, $owner)->assertOk();
    $html = $page->html();

    expect($html)->toContain('data-test="cashflow-table"')
        ->toContain('data-weekend="1"')
        ->toContain('data-test="cashflow-weekend-mark"')
        ->toContain('休日')
        ->toContain('data-below-zero="1"')
        ->toContain('残高が0円を下回る見込み')
        ->toContain('150,000円')
        ->not->toContain('資金ショート')
        ->not->toContain(CASHFLOW_PARTNER);

    $row = app(CashFlowBoard::class)->forAccount($company, bankAccountId($company, CASHFLOW_BANK));
    expect($row['missing_balance'])->toBeFalse()
        ->and($row['forecast']['below_zero_dates'])->toContain('2026-11-25')
        ->and($row['from'])->toBe('2026-11-21')
        ->and($row['to'])->toBe('2027-02-21');
});

test('T59 画面：月末が土曜の月は表の上に注意書き。続く3か月の月末がすべて平日なら注意は出ない', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);

    // 今日を 2026-10-20 にして、10/31（土曜）が範囲に入る
    $this->travelTo(now()->setDate(2026, 10, 20)->setTime(9, 0));
    cashflowEnterBalance($company, $owner, '2026-10-19', 50_000);

    cashflowPage($company, $owner)
        ->assertOk()
        ->assertSee('data-test="cashflow-month-end-weekend"', false)
        ->assertSee('2026年10月の月末が休日です。入金が翌月になる取引がないか確かめてください。');

    // 2026-06-15〜09-15：6/30火・7/31金・8/31月で、月末の土日はない
    $this->travelTo(now()->setDate(2026, 6, 15)->setTime(9, 0));
    cashflowEnterBalance($company, $owner, '2026-06-14', 50_000);

    $html = cashflowPage($company, $owner)->assertOk()->html();
    expect($html)->not->toContain('data-test="cashflow-month-end-weekend"')
        ->not->toContain('月末が休日です');
});

test('T59 済みの予定と他社の予定は足さない。同じ日の入金と支払いをまとめる', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    ['company' => $other, 'user' => $otherOwner] = createCompanyWithMember(Role::Owner);

    cashflowEnterBalance($company, $owner, '2026-11-20', 100_000);
    cashflowEnterBalance($other, $otherOwner, '2026-11-20', 1);

    $service = app(CashScheduleService::class);
    $partner = $service->addPartner($company, $owner, CASHFLOW_PARTNER);
    $in = $service->add($company, $owner, new CashScheduleInput(
        direction: ScheduleCategory::Sales->direction(),
        category: ScheduleCategory::Sales,
        partnerId: $partner->id,
        bankAccountId: bankAccountId($company, CASHFLOW_BANK),
        amountYen: 30_000,
        dueDate: CarbonImmutable::parse('2026-11-25'),
    ));
    $service->add($company, $owner, new CashScheduleInput(
        direction: ScheduleCategory::Purchase->direction(),
        category: ScheduleCategory::Purchase,
        partnerId: $partner->id,
        bankAccountId: bankAccountId($company, CASHFLOW_BANK),
        amountYen: 80_000,
        dueDate: CarbonImmutable::parse('2026-11-25'),
    ));
    $service->settle($company, $owner, $in->id, CarbonImmutable::parse('2026-11-21'));

    cashflowAddOut($other, $otherOwner, 999_999, '2026-11-25');

    $day = collect(app(CashFlowBoard::class)->forAccount($company, bankAccountId($company, CASHFLOW_BANK))['forecast']['days'])
        ->firstWhere('date', '2026-11-25');

    expect($day)->toMatchArray([
        'in_yen' => 0,
        'out_yen' => 80_000,
        'balance_yen' => 20_000,
        'below_zero' => false,
    ]);
});

test('T59 権限：開けるのは owner・editor だけ', function (Role $role, bool $submitOnly, int $status): void {
    ['company' => $company] = createCompanyWithMember(Role::Owner);
    ['user' => $user] = createCompanyWithMember($role, submitOnly: $submitOnly, company: $company);

    $this->actingAs($user)->get(route('company.cashflow', $company))->assertStatus($status);
    expect(Gate::forUser($user)->allows('viewCashFlow', $company))->toBe($status === 200);

    if ($status === 403) {
        cashflowPage($company, $user)->assertForbidden();
    } else {
        cashflowPage($company, $user)->assertOk();
    }
})->with([
    'owner' => [Role::Owner, false, 200],
    'editor' => [Role::Editor, false, 200],
    'admin' => [Role::Admin, false, 403],
    'approver' => [Role::Approver, false, 403],
    'viewer' => [Role::Viewer, false, 403],
    '送るだけ' => [Role::Editor, true, 403],
]);

test('T59 権限：未ログイン・2段階認証がまだ・他社・無効な所属は開けない', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    ['user' => $noTwoFactor] = createCompanyWithMember(Role::Owner, withSecurityAnswer: false, company: $company);
    ['company' => $other, 'user' => $outsider] = createCompanyWithMember(Role::Owner);

    $this->get(route('company.cashflow', $company))->assertRedirect();
    expect(Gate::forUser($noTwoFactor)->allows('viewCashFlow', $company))->toBeFalse()
        ->and(Gate::forUser($outsider)->allows('viewCashFlow', $company))->toBeFalse();

    $this->actingAs($outsider)->get(route('company.cashflow', $company))->assertNotFound();

    $company->memberships()->where('user_id', $owner->id)->sole()->forceFill(['disabled_at' => now()])->save();
    expect(Gate::forUser($owner->fresh())->allows('viewCashFlow', $company))->toBeFalse();
});

test('T59 権限：開いたあとで役割が変わったら読み直しと口座の変更が403', function (array $change): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    cashflowEnterBalance($company, $owner, '2026-11-20', 10_000);
    $page = cashflowPage($company, $owner)->assertOk();

    $company->memberships()->where('user_id', $owner->id)->sole()->forceFill($change)->save();

    $page->set('bankAccountId', bankAccountId($company, CASHFLOW_BANK))->assertForbidden();
})->with([
    'admin になった' => [['role' => Role::Admin]],
    '送るだけになった' => [['submit_only' => true]],
]);

test('T59 サイドバーは開ける人にだけ', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    ['user' => $editor] = createCompanyWithMember(Role::Editor, company: $company);
    ['user' => $admin] = createCompanyWithMember(Role::Admin, company: $company);
    ['user' => $viewer] = createCompanyWithMember(Role::Viewer, company: $company);

    $this->actingAs($owner)->get(route('company.home', $company))->assertSee('data-test="sidebar-cashflow"', false);
    $this->actingAs($editor)->get(route('company.home', $company))->assertSee('data-test="sidebar-cashflow"', false);
    $this->actingAs($admin)->get(route('company.home', $company))->assertDontSee('data-test="sidebar-cashflow"', false);
    $this->actingAs($viewer)->get(route('company.home', $company))->assertDontSee('data-test="sidebar-cashflow"', false);
});

test('T59 社長の画面：owner には0円を下回る見込みの日が出る。admin には出さない。editor は社長の画面自体が403', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    ['user' => $admin] = createCompanyWithMember(Role::Admin, company: $company);
    ['user' => $editor] = createCompanyWithMember(Role::Editor, company: $company);

    cashflowEnterBalance($company, $owner, '2026-11-20', 10_000);
    cashflowAddOut($company, $owner, 50_000, '2026-11-25');
    cashflowAddOut($company, $owner, 1, '2026-11-26');

    $this->actingAs($owner)->get(route('company.owner', $company))
        ->assertOk()
        ->assertSee('残高が0円を下回る見込み')
        ->assertSee('data-test="owner-cash-shortfalls"', false)
        ->assertSee('11月25日')
        ->assertDontSee(CASHFLOW_PARTNER);

    $adminHtml = $this->actingAs($admin)->get(route('company.owner', $company))->assertOk()->getContent();
    expect($adminHtml)->not->toContain('data-test="owner-cash-shortfalls"')
        ->not->toContain('残高が0円を下回る見込み')
        ->not->toContain(CASHFLOW_PARTNER);

    $this->actingAs($editor)->get(route('company.owner', $company))->assertForbidden();
});

test('T59 社長の画面：下回る日がなければ出さない。6日以上は5日まで', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    cashflowEnterBalance($company, $owner, '2026-11-20', 100_000);

    $this->actingAs($owner)->get(route('company.owner', $company))
        ->assertOk()
        ->assertDontSee('data-test="owner-cash-shortfalls"', false);

    // 一度0円を下回ると、入金が来るまでその後の日も下回ったまま（約3か月分）
    cashflowEnterBalance($company, $owner, '2026-11-20', 0);
    cashflowAddOut($company, $owner, 1, '2026-11-24');

    $short = app(CashFlowBoard::class)->shortfalls($company);
    expect($short['count'])->toBeGreaterThan(CashFlowBoard::OWNER_ROWS)
        ->and($short['more'])->toBe($short['count'] - CashFlowBoard::OWNER_ROWS)
        ->and(count($short['dates']))->toBe(CashFlowBoard::OWNER_ROWS);

    $html = $this->actingAs($owner)->get(route('company.owner', $company))->assertOk()->getContent();
    expect(substr_count($html, 'data-test="owner-cash-shortfall-date"'))->toBe(CashFlowBoard::OWNER_ROWS)
        ->and($html)->toContain('ほか '.$short['more'].'日');
});

test('T59 分析用の書き出しに予定の金額も取引先の名前も入らない', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    $partner = app(CashScheduleService::class)->addPartner($company, $owner, CASHFLOW_PARTNER);
    cashflowEnterBalance($company, $owner, '2026-11-20', 10_000);
    app(CashScheduleService::class)->add($company, $owner, new CashScheduleInput(
        direction: ScheduleCategory::Purchase->direction(),
        category: ScheduleCategory::Purchase,
        partnerId: $partner->id,
        bankAccountId: bankAccountId($company, CASHFLOW_BANK),
        amountYen: 12_345,
        dueDate: CarbonImmutable::parse('2026-11-25'),
    ));

    $export = app(TrainingDataExporter::class)->export($company, CarbonImmutable::now()->startOfMonth());
    $path = (string) tempnam(sys_get_temp_dir(), 'cashflow-zip-');
    file_put_contents($path, $export['contents']);
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $exportText = '';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $exportText .= $zip->getNameIndex($i)."\n".$zip->getFromIndex($i)."\n";
    }
    $zip->close();
    unlink($path);

    expect($exportText)->not->toContain(CASHFLOW_PARTNER)
        ->not->toContain('12345')
        ->not->toContain('business_partner')
        ->not->toContain('cash_schedule');
});

test('T59 他社の口座IDでは404。画面から他社のIDを渡しても相手のデータは出ない', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    ['company' => $other, 'user' => $otherOwner] = createCompanyWithMember(Role::Owner);
    cashflowEnterBalance($company, $owner, '2026-11-20', 50_000);
    cashflowEnterBalance($other, $otherOwner, '2026-11-20', 9_999_999);
    cashflowAddOut($other, $otherOwner, 1, '2026-11-25');

    expect(fn () => app(CashFlowBoard::class)->forAccount($company, bankAccountId($other, CASHFLOW_BANK)))
        ->toThrow(ModelNotFoundException::class);

    cashflowPage($company, $owner)
        ->set('bankAccountId', bankAccountId($other, CASHFLOW_BANK))
        ->assertNotFound()
        ->assertDontSee('9,999,999');
});
