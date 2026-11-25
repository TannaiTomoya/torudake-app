<?php

use App\Enums\AiTask;
use App\Enums\Role;
use App\Enums\ScheduleCategory;
use App\Enums\ScheduleDirection;
use App\Models\AuditLog;
use App\Models\BusinessPartner;
use App\Models\CashSchedule;
use App\Models\Company;
use App\Models\User;
use App\Services\Ai\FakeClaudeClient;
use App\Services\CashScheduleBoard;
use App\Services\CashScheduleInput;
use App\Services\CashScheduleService;
use App\Services\TrainingDataExporter;
use App\Support\ScheduleDelay;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// #67：入金と支払いの予定。名前・日付・金額はすべて架空

const SCHEDULE_PARTNER_A = 'みちのく架空建設';
const SCHEDULE_PARTNER_B = '北上サンプル商事';
const SCHEDULE_BANK = '〇〇銀行（普通預金）';

/** 見えてはいけない人の応答に入っていないことを確かめる文字（取引先の名前・金額・給料の合計） */
const SCHEDULE_SECRETS = [
    SCHEDULE_PARTNER_A,
    SCHEDULE_PARTNER_B,
    '1,234,500', '1234500',
    '2,345,600', '2345600',
    '765,400', '765400',
    '345,600', '345600',
    '1,580,100', '1580100',
];

beforeEach(fn () => $this->travelTo(now()->setDate(2026, 11, 21)->setTime(9, 0)));

function schedulePage(Company $company, User $user)
{
    return Livewire::actingAs($user)->test('pages::company.schedules', ['company' => $company]);
}

function schedulePartner(Company $company, User $user, string $name = SCHEDULE_PARTNER_A): BusinessPartner
{
    return app(CashScheduleService::class)->addPartner($company, $user, $name);
}

function scheduleInput(Company $company, ScheduleCategory $category, int $yen, string $due, ?string $partnerId = null, ?string $accountId = null, ?ScheduleDirection $direction = null): CashScheduleInput
{
    return new CashScheduleInput(
        direction: $direction ?? $category->direction(),
        category: $category,
        partnerId: $partnerId,
        bankAccountId: $accountId ?? bankAccountId($company, SCHEDULE_BANK),
        amountYen: $yen,
        dueDate: CarbonImmutable::parse($due),
    );
}

function scheduleAdd(Company $company, User $user, ScheduleCategory $category, int $yen, string $due, ?string $partnerId = null): CashSchedule
{
    return app(CashScheduleService::class)->add($company, $user, scheduleInput($company, $category, $yen, $due, $partnerId));
}

/**
 * 遅れた入金（取引先あり・個人のお客さん）、これからの仕入、給料の合計が入った会社。
 *
 * @return array{company: Company, owner: User, partnerA: BusinessPartner, partnerB: BusinessPartner}
 */
function scheduleCompany(): array
{
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    $partnerA = schedulePartner($company, $owner, SCHEDULE_PARTNER_A);
    $partnerB = schedulePartner($company, $owner, SCHEDULE_PARTNER_B);

    scheduleAdd($company, $owner, ScheduleCategory::Sales, 1_234_500, '2026-11-10', $partnerA->id);
    scheduleAdd($company, $owner, ScheduleCategory::Sales, 345_600, '2026-11-20');
    scheduleAdd($company, $owner, ScheduleCategory::Purchase, 2_345_600, '2026-11-25', $partnerB->id);
    scheduleAdd($company, $owner, ScheduleCategory::Salary, 765_400, '2026-11-25');

    return ['company' => $company, 'owner' => $owner, 'partnerA' => $partnerA, 'partnerB' => $partnerB];
}

/**
 * 文字の中に入っている「見えてはいけない文字」を返す。名前は JSON の形（\\uXXXX）でも探す。
 * 数字だけのものは、ID やハッシュの一部と取り違えないよう、前後が数字・英字でないときだけ数える。
 *
 * @return list<string>
 */
function scheduleLeaks(string $text): array
{
    $found = [];
    foreach (SCHEDULE_SECRETS as $secret) {
        if (preg_match('/\A\d+\z/', $secret) === 1) {
            $hit = preg_match('/(?<![0-9A-Za-z])'.$secret.'(?![0-9A-Za-z])/', $text) === 1;
        } else {
            $hit = str_contains($text, $secret) || str_contains($text, trim((string) json_encode($secret), '"'));
        }
        if ($hit) {
            $found[] = $secret;
        }
    }

    return $found;
}

/** 監査記録の中身を、日本語のままの1つの文字列にする */
function scheduleAuditText(): string
{
    return (string) json_encode(
        AuditLog::query()->get()->map(fn (AuditLog $log): array => ['action' => $log->action, 'before' => $log->before, 'after' => $log->after])->all(),
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );
}

test('T58 遅れの規則：Python（tools/analysis）と共通の架空ベクトルで同じ答えになる', function (): void {
    $vectors = json_decode((string) file_get_contents(base_path('tests/fixtures/schedule_vectors.json')), true, 512, JSON_THROW_ON_ERROR);
    expect($vectors['judgements'])->not->toBeEmpty()->and($vectors['summaries'])->not->toBeEmpty();

    foreach ($vectors['judgements'] as $case) {
        expect(ScheduleDelay::judge($case['due_date'], $case['settled_on'], $case['today']))->toBe($case['expected'], $case['name']);
    }
    foreach ($vectors['summaries'] as $case) {
        expect(ScheduleDelay::summarize($case['items'], $case['today']))->toBe($case['expected'], $case['name']);
    }
});

test('T58 遅れの規則：日付・金額・入金か支払いかが正しくないときは判定しない', function (Closure $call): void {
    expect($call)->toThrow(InvalidArgumentException::class);
})->with([
    'ない日付' => [fn () => ScheduleDelay::judge('2026-02-30', null, '2026-11-21')],
    '形が違う日付' => [fn () => ScheduleDelay::judge('2026/11/20', null, '2026-11-21')],
    '改行つきの日付' => [fn () => ScheduleDelay::judge("2026-11-20\n", null, '2026-11-21')],
    '数字の日付' => [fn () => ScheduleDelay::judge(20261120, null, '2026-11-21')],
    '今日がない' => [fn () => ScheduleDelay::judge('2026-11-20', null, null)],
    '済みの日が正しくない' => [fn () => ScheduleDelay::judge('2026-11-20', '2026-13-01', '2026-11-21')],
    '入金でも支払いでもない' => [fn () => ScheduleDelay::summarize([['direction' => 'both', 'amount_yen' => 1, 'due_date' => '2026-11-20', 'settled_on' => null]], '2026-11-21')],
    '金額が0円' => [fn () => ScheduleDelay::summarize([['direction' => 'in', 'amount_yen' => 0, 'due_date' => '2026-11-20', 'settled_on' => null]], '2026-11-21')],
    '金額が小数' => [fn () => ScheduleDelay::summarize([['direction' => 'in', 'amount_yen' => 1.5, 'due_date' => '2026-11-20', 'settled_on' => null]], '2026-11-21')],
    '金額が文字' => [fn () => ScheduleDelay::summarize([['direction' => 'in', 'amount_yen' => '1000', 'due_date' => '2026-11-20', 'settled_on' => null]], '2026-11-21')],
    '組になっていない' => [fn () => ScheduleDelay::summarize(['2026-11-20'], '2026-11-21')],
]);

test('T58 請求ごとに、取引先・金額・入金予定日を登録できる。監査記録には ID と金額が残り、名前は残らない', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);

    $page = schedulePage($company, $editor)
        ->set('partnerName', '　'.SCHEDULE_PARTNER_A.'  ')
        ->call('addPartner')
        ->assertHasNoErrors()
        ->assertSee('取引先を足しました。');

    $partner = $company->businessPartners()->sole();
    expect($partner->name)->toBe(SCHEDULE_PARTNER_A);

    $page->assertSet('partnerId', $partner->id)
        ->set('amountYen', '1,234,500円')
        ->set('dueDate', '2026-11-30')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('11月30日(月)の入金の予定を入れました。')
        ->assertSee(SCHEDULE_PARTNER_A)
        ->assertSee('1,234,500円')
        ->assertSee('売上の入金')
        ->assertSet('amountYen', '')
        ->assertSet('partnerId', '');

    $schedule = $company->cashSchedules()->sole();
    expect($schedule->direction)->toBe(ScheduleDirection::In)
        ->and($schedule->category)->toBe(ScheduleCategory::Sales)
        ->and($schedule->business_partner_id)->toBe($partner->id)
        ->and($schedule->amount_yen)->toBe(1_234_500)
        ->and($schedule->due_date->toDateString())->toBe('2026-11-30')
        ->and($schedule->settled_on)->toBeNull()
        ->and($schedule->created_by)->toBe($editor->id)
        ->and($schedule->bank_account_id)->toBe(bankAccountId($company, SCHEDULE_BANK));

    $log = AuditLog::query()->where('action', 'cash_schedule.add')->sole();
    expect($log->company_id)->toBe($company->id)
        ->and($log->before)->toBeNull()
        ->and($log->after['amount_yen'] ?? null)->toBe(1_234_500)
        ->and($log->after)->toEqual([
            'direction' => 'in',
            'category' => 'sales',
            'business_partner_id' => $partner->id,
            'bank_account_id' => bankAccountId($company, SCHEDULE_BANK),
            'amount_yen' => 1_234_500,
            'due_date' => '2026-11-30',
            'settled_on' => null,
        ]);
    expect(scheduleAuditText())->toContain('business_partner.add')->not->toContain(SCHEDULE_PARTNER_A);
});

test('T58 入金予定日を過ぎても入金済みの印がなければ「遅れ」。今日の予定は遅れではない', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    $partner = schedulePartner($company, $owner);
    scheduleAdd($company, $owner, ScheduleCategory::Sales, 1_000_000, '2026-11-20', $partner->id);
    scheduleAdd($company, $owner, ScheduleCategory::Sales, 500_000, '2026-11-21', $partner->id);
    scheduleAdd($company, $owner, ScheduleCategory::Sales, 300_000, '2026-11-22', $partner->id);
    scheduleAdd($company, $owner, ScheduleCategory::Lease, 80_000, '2026-10-31', $partner->id);

    $html = schedulePage($company, $owner)
        ->assertSee('遅れ（1日）')
        ->assertSee('遅れ（21日）')
        ->assertSeeInOrder(['まだの入金', '3件・1,800,000円', 'うち遅れ 1件・1,000,000円', 'まだの支払い', '1件・80,000円', 'うち遅れ 1件・80,000円'])
        ->html();

    expect(substr_count($html, 'data-test="schedule-late"'))->toBe(2)
        ->and(substr_count($html, 'data-test="schedule-today"'))->toBe(1)
        ->and(substr_count($html, 'data-status="upcoming"'))->toBe(1);

    expect(app(CashScheduleBoard::class)->summary($company))->toBe([
        'in' => ['open_count' => 3, 'open_yen' => 1_800_000, 'late_count' => 1, 'late_yen' => 1_000_000],
        'out' => ['open_count' => 1, 'open_yen' => 80_000, 'late_count' => 1, 'late_yen' => 80_000],
    ]);

    // 日付が変わると、きのうまで「今日」だった予定が遅れになる
    $this->travelTo(now()->addDay());
    expect(app(CashScheduleBoard::class)->summary($company)['in'])->toMatchArray(['late_count' => 2, 'late_yen' => 1_500_000]);
});

test('T58 支払予定（仕入・給料・リース・その他）も同じ形で登録できる。給料は全員の合計の1行で、取引先は付けられない', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $partner = schedulePartner($company, $editor, SCHEDULE_PARTNER_B);

    $page = schedulePage($company, $editor)
        ->set('direction', 'out')
        ->assertSet('category', 'purchase')
        ->set('partnerId', $partner->id)
        ->set('amountYen', '2345600')
        ->set('dueDate', '2026-11-25')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('11月25日(水)の支払いの予定を入れました。');

    $page->set('direction', 'out')
        ->set('partnerId', $partner->id)
        ->set('category', 'salary')
        ->assertSet('partnerId', '')
        ->assertSee('給料は全員の合計を1行で入れます。人の名前や、人ごとの金額は入れません。')
        ->set('amountYen', '765400')
        ->set('dueDate', '2026-11-25')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('給料（全員の合計）')
        ->assertSee('765,400円');

    foreach ([ScheduleCategory::Lease, ScheduleCategory::OtherOut] as $category) {
        scheduleAdd($company, $editor, $category, 50_000, '2026-12-10', $partner->id);
    }

    $salary = $company->cashSchedules()->where('category', 'salary')->sole();
    expect($salary->business_partner_id)->toBeNull()
        ->and($salary->partyLabel())->toBeNull()
        ->and($company->cashSchedules()->where('direction', 'out')->count())->toBe(4);

    // 画面を通さずに、給料へ取引先を付けようとしても保存しない
    expect(fn () => scheduleAdd($company, $editor, ScheduleCategory::Salary, 100_000, '2026-12-25', $partner->id))
        ->toThrow(ValidationException::class);
    schedulePage($company, $editor)
        ->set('direction', 'out')
        ->set('category', 'salary')
        ->set('partnerId', $partner->id)
        ->set('amountYen', '100000')
        ->set('dueDate', '2026-12-25')
        ->call('save')
        ->assertHasErrors('partnerId');
    expect($company->cashSchedules()->where('category', 'salary')->count())->toBe(1);
});

test('T58 個人のお客さんは名前を持たない（取引先なしの入金は「個人のお客さん」と出る）', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);

    schedulePage($company, $editor)
        ->set('amountYen', '345600')
        ->set('dueDate', '2026-11-30')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('個人のお客さん')
        ->assertSee('個人のお客さんの名前は入れません');

    $schedule = $company->cashSchedules()->sole();
    expect($schedule->business_partner_id)->toBeNull()
        ->and($schedule->partyLabel())->toBe('個人のお客さん')
        ->and($company->businessPartners()->count())->toBe(0);

    $payment = scheduleAdd($company, $editor, ScheduleCategory::OtherOut, 10_000, '2026-11-30');
    expect($payment->partyLabel())->toBe('取引先なし');
});

test('T58 済みにすると遅れが消え、済みを戻すと遅れに戻る。監査記録に前後が残り、二度押しでは増えない', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $partner = schedulePartner($company, $editor);
    $schedule = scheduleAdd($company, $editor, ScheduleCategory::Sales, 1_000_000, '2026-11-10', $partner->id);

    $page = schedulePage($company, $editor)
        ->assertSee('遅れ（11日）')
        ->assertSet('settleDate', '2026-11-21')
        ->set('settleDate', '2026-11-22')
        ->call('settle', $schedule->id)
        ->assertHasErrors('settleDate');
    expect($schedule->refresh()->settled_on)->toBeNull();

    $page->set('settleDate', '2026-11-19')
        ->call('settle', $schedule->id)
        ->assertHasNoErrors()
        ->assertSee('入金済みにしました。')
        ->assertSee('11月19日(木)に入金済み')
        ->assertDontSee('遅れ（11日）')
        ->assertSee('遅れはありません')
        ->call('settle', $schedule->id);

    expect($schedule->refresh()->settled_on?->toDateString())->toBe('2026-11-19')
        ->and($schedule->settled_by)->toBe($editor->id);
    $log = AuditLog::query()->where('action', 'cash_schedule.settle')->sole();
    expect($log->before)->toMatchArray(['settled_on' => null])
        ->and($log->after)->toMatchArray(['settled_on' => '2026-11-19', 'amount_yen' => 1_000_000]);

    $page->call('unsettle', $schedule->id)
        ->assertSee('済みを戻しました。')
        ->assertSee('遅れ（11日）')
        ->call('unsettle', $schedule->id);

    expect($schedule->refresh()->settled_on)->toBeNull()
        ->and($schedule->settled_by)->toBeNull()
        ->and(AuditLog::query()->where('action', 'cash_schedule.unsettle')->count())->toBe(1);
});

test('T58 予定を直すと監査記録に前後が残る。同じ中身なら残さない。済みの予定は直せず、取り消せない', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $partner = schedulePartner($company, $editor);
    $schedule = scheduleAdd($company, $editor, ScheduleCategory::Sales, 1_000_000, '2026-11-10', $partner->id);

    $page = schedulePage($company, $editor)
        ->call('startEdit', $schedule->id)
        ->assertSet('editingId', $schedule->id)
        ->assertSet('amountYen', '1000000')
        ->assertSet('dueDate', '2026-11-10')
        ->assertSet('partnerId', $partner->id)
        ->assertSee('予定を直す')
        ->call('save')
        ->assertHasNoErrors();
    expect(AuditLog::query()->where('action', 'cash_schedule.update')->count())->toBe(0);

    $page->call('startEdit', $schedule->id)
        ->set('dueDate', '2026-12-05')
        ->set('amountYen', '900000')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('12月5日(土)の予定を直しました。')
        ->assertSet('editingId', null)
        ->assertDontSee('遅れ（11日）');

    expect($schedule->refresh()->amount_yen)->toBe(900_000)
        ->and($schedule->due_date->toDateString())->toBe('2026-12-05')
        ->and($company->cashSchedules()->count())->toBe(1);
    $log = AuditLog::query()->where('action', 'cash_schedule.update')->sole();
    expect($log->before)->toMatchArray(['amount_yen' => 1_000_000, 'due_date' => '2026-11-10'])
        ->and($log->after)->toMatchArray(['amount_yen' => 900_000, 'due_date' => '2026-12-05']);

    $service = app(CashScheduleService::class);
    $service->settle($company, $editor, $schedule->id, CarbonImmutable::parse('2026-11-21'));

    expect(fn () => $service->update($company, $editor, $schedule->id, scheduleInput($company, ScheduleCategory::Sales, 1, '2026-12-06', $partner->id)))
        ->toThrow(ValidationException::class);
    expect(fn () => $service->remove($company, $editor, $schedule->id))->toThrow(ValidationException::class);
    schedulePage($company, $editor)->call('startEdit', $schedule->id)->assertNotFound();
    schedulePage($company, $editor)->call('remove', $schedule->id)->assertHasErrors('schedule')->assertSee('済みの予定は取り消せません。');

    expect($schedule->refresh()->amount_yen)->toBe(900_000)
        ->and($company->cashSchedules()->count())->toBe(1);
});

test('T58 取り消すと行が消え、消した値が監査記録に残る。二度目は案内だけ出す', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    $schedule = scheduleAdd($company, $owner, ScheduleCategory::Purchase, 200_000, '2026-11-30');

    schedulePage($company, $owner)
        ->call('remove', $schedule->id)
        ->assertSee('予定を取り消しました。')
        ->call('remove', $schedule->id)
        ->assertOk()
        ->assertSee('この予定はすでに取り消されています。');

    expect($company->cashSchedules()->count())->toBe(0);
    $log = AuditLog::query()->where('action', 'cash_schedule.delete')->sole();
    expect($log->before)->toMatchArray(['amount_yen' => 200_000, 'category' => 'purchase'])
        ->and($log->after)->toBeNull();
});

test('T58 金額・予定日・口座・種類が正しくない予定は入れられない', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $partner = schedulePartner($company, $editor);
    $add = fn (CashScheduleInput $input) => fn () => app(CashScheduleService::class)->add($company, $editor, $input);

    expect($add(scheduleInput($company, ScheduleCategory::Sales, 0, '2026-11-30')))->toThrow(ValidationException::class);
    expect($add(scheduleInput($company, ScheduleCategory::Sales, CashScheduleService::MAX_YEN + 1, '2026-11-30')))->toThrow(ValidationException::class);
    expect($add(scheduleInput($company, ScheduleCategory::Sales, 1000, '1999-12-31')))->toThrow(ValidationException::class);
    expect($add(scheduleInput($company, ScheduleCategory::Sales, 1000, '2031-11-22')))->toThrow(ValidationException::class);
    expect($add(scheduleInput($company, ScheduleCategory::Sales, 1000, '2026-11-30', accountId: bankAccountId($company, '現金'))))->toThrow(ValidationException::class);
    expect($add(scheduleInput($company, ScheduleCategory::Purchase, 1000, '2026-11-30', direction: ScheduleDirection::In)))->toThrow(ValidationException::class);

    $company->bankAccounts()->where('name', SCHEDULE_BANK)->sole()->forceFill(['archived_at' => now()])->save();
    expect($add(scheduleInput($company, ScheduleCategory::Sales, 1000, '2026-11-30', $partner->id)))->toThrow(ValidationException::class);
    $company->bankAccounts()->where('name', SCHEDULE_BANK)->sole()->forceFill(['archived_at' => null])->save();

    schedulePage($company, $editor)->set('amountYen', '12a')->set('dueDate', '2026-11-30')->call('save')->assertHasErrors('amountYen');
    schedulePage($company, $editor)->set('amountYen', '-500')->set('dueDate', '2026-11-30')->call('save')->assertHasErrors('amountYen');
    schedulePage($company, $editor)->set('amountYen', '1000')->set('dueDate', '11/30')->call('save')->assertHasErrors('dueDate');
    schedulePage($company, $editor)->set('amountYen', '1000')->set('dueDate', '2026-11-30')->set('category', 'purchase')->call('save')->assertHasErrors('category');
    schedulePage($company, $editor)->set('amountYen', '1000')->set('dueDate', '2026-11-30')->set('direction', 'sideways')->call('save')->assertHasErrors('direction');

    expect($company->cashSchedules()->count())->toBe(0);

    // 上限ちょうど・5年先ちょうどは入る
    scheduleAdd($company, $editor, ScheduleCategory::Sales, CashScheduleService::MAX_YEN, '2031-11-21', $partner->id);
    scheduleAdd($company, $editor, ScheduleCategory::Sales, 1, '2000-01-01');
    expect($company->cashSchedules()->count())->toBe(2);
});

test('T58 取引先：同じ名前は足せない・名前を直せる・使わなくしても入れてある予定には名前が残り、同じ名前で足すと戻る', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $partner = schedulePartner($company, $editor, SCHEDULE_PARTNER_A);
    $schedule = scheduleAdd($company, $editor, ScheduleCategory::Sales, 1_000_000, '2026-11-30', $partner->id);

    $page = schedulePage($company, $editor)
        ->set('partnerName', SCHEDULE_PARTNER_A)->call('addPartner')->assertHasErrors('partnerName')
        ->set('partnerName', '   ')->call('addPartner')->assertHasErrors('partnerName')
        ->set('partnerName', str_repeat('あ', CashScheduleService::MAX_PARTNER_NAME + 1))->call('addPartner')->assertHasErrors('partnerName')
        ->set('partnerName', "架空\u{200B}商店")->call('addPartner')->assertHasErrors('partnerName');
    expect($company->businessPartners()->count())->toBe(1);

    schedulePartner($company, $editor, SCHEDULE_PARTNER_B);
    $page->call('startRename', $partner->id)
        ->assertSet('renameName', SCHEDULE_PARTNER_A)
        ->set('renameName', SCHEDULE_PARTNER_B)
        ->call('saveRename')
        ->assertHasErrors('renameName')
        ->set('renameName', 'みちのく架空建設工業')
        ->call('saveRename')
        ->assertHasNoErrors()
        ->assertSee('取引先の名前を直しました。')
        ->assertSee('みちのく架空建設工業');
    expect($partner->refresh()->name)->toBe('みちのく架空建設工業');

    $page->call('archivePartner', $partner->id)
        ->assertSee('取引先を使わなくしました。')
        ->call('archivePartner', $partner->id);
    expect($partner->refresh()->archived_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'business_partner.archive')->count())->toBe(1)
        ->and($schedule->refresh()->partyLabel())->toBe('みちのく架空建設工業');

    // 使わなくした取引先は、新しい予定には選べない。入れてある予定を直すときは、そのまま残せる
    expect(fn () => scheduleAdd($company, $editor, ScheduleCategory::Sales, 1000, '2026-12-01', $partner->id))->toThrow(ValidationException::class);
    schedulePage($company, $editor)
        ->call('startEdit', $schedule->id)
        ->assertSee('みちのく架空建設工業（使わなくした取引先）')
        ->set('amountYen', '1100000')
        ->call('save')
        ->assertHasNoErrors();
    expect($schedule->refresh()->business_partner_id)->toBe($partner->id)
        ->and($schedule->amount_yen)->toBe(1_100_000);

    schedulePage($company, $editor)->set('partnerName', 'みちのく架空建設工業')->call('addPartner')->assertHasNoErrors();
    expect($partner->refresh()->archived_at)->toBeNull()
        ->and($company->businessPartners()->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'business_partner.restore')->count())->toBe(1);

    // 名前は取引先の表にだけあり、監査記録には入らない。配列にしても名前は出ない
    expect(scheduleAuditText())
        ->toContain('business_partner.rename')
        ->not->toContain(SCHEDULE_PARTNER_A)
        ->not->toContain(SCHEDULE_PARTNER_B)
        ->not->toContain('みちのく');
    expect($partner->toArray())->not->toHaveKey('name');
});

test('T58 他社の予定・取引先・口座は404（会社から引く）で、相手の会社のデータは変わらない', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);
    ['company' => $other, 'user' => $otherUser] = createCompanyWithMember(Role::Editor);
    $otherPartner = schedulePartner($other, $otherUser, SCHEDULE_PARTNER_B);
    $otherSchedule = scheduleAdd($other, $otherUser, ScheduleCategory::Sales, 5000, '2026-11-10', $otherPartner->id);
    $service = app(CashScheduleService::class);

    expect(fn () => scheduleAdd($company, $user, ScheduleCategory::Sales, 1000, '2026-11-30', $otherPartner->id))->toThrow(ModelNotFoundException::class);
    expect(fn () => $service->add($company, $user, scheduleInput($company, ScheduleCategory::Sales, 1000, '2026-11-30', accountId: bankAccountId($other, SCHEDULE_BANK))))->toThrow(ModelNotFoundException::class);
    expect(fn () => $service->update($company, $user, $otherSchedule->id, scheduleInput($company, ScheduleCategory::Sales, 1, '2026-11-30')))->toThrow(ModelNotFoundException::class);
    expect(fn () => $service->settle($company, $user, $otherSchedule->id, CarbonImmutable::today()))->toThrow(ModelNotFoundException::class);
    expect(fn () => $service->unsettle($company, $user, $otherSchedule->id))->toThrow(ModelNotFoundException::class);
    expect(fn () => $service->remove($company, $user, $otherSchedule->id))->toThrow(ModelNotFoundException::class);
    expect(fn () => $service->renamePartner($company, $user, $otherPartner->id, '書き換え'))->toThrow(ModelNotFoundException::class);
    expect(fn () => $service->archivePartner($company, $user, $otherPartner->id))->toThrow(ModelNotFoundException::class);

    $this->actingAs($user)->get(route('company.schedules', $other))->assertNotFound();

    schedulePage($company, $user)->call('startEdit', $otherSchedule->id)->assertNotFound();
    schedulePage($company, $user)->call('settle', $otherSchedule->id)->assertNotFound();
    schedulePage($company, $user)->call('unsettle', $otherSchedule->id)->assertNotFound();
    schedulePage($company, $user)->call('remove', $otherSchedule->id)->assertSee('この予定はすでに取り消されています。');
    schedulePage($company, $user)->call('startRename', $otherPartner->id)->assertNotFound();
    schedulePage($company, $user)->call('archivePartner', $otherPartner->id)->assertNotFound();
    // 他社の「使わなくした取引先」の ID を取引先の欄に入れても、名前は選択肢に出ない（会社から引く）
    $otherArchived = schedulePartner($other, $otherUser, '他社の使わなくした架空工務店');
    $service->archivePartner($other, $otherUser, $otherArchived->id);
    schedulePage($company, $user)->set('partnerId', $otherArchived->id)->assertDontSee('他社の使わなくした架空工務店')->assertDontSee('（使わなくした取引先）');

    schedulePage($company, $user)
        ->set('partnerId', $otherPartner->id)
        ->assertDontSee(SCHEDULE_PARTNER_B)
        ->set('amountYen', '1000')
        ->set('dueDate', '2026-11-30')
        ->call('save')
        ->assertNotFound();

    // 自分の会社の画面には、他社の予定・取引先は出ない
    schedulePage($company, $user)->assertDontSee(SCHEDULE_PARTNER_B)->assertDontSee('5,000円');

    expect($company->cashSchedules()->count())->toBe(0)
        ->and($otherSchedule->refresh()->settled_on)->toBeNull()
        ->and($otherSchedule->amount_yen)->toBe(5000)
        ->and($otherPartner->refresh()->name)->toBe(SCHEDULE_PARTNER_B)
        ->and($otherPartner->archived_at)->toBeNull();
});

test('T58 権限：予定の画面を開けるのは owner・editor だけ（admin・approver・viewer・送るだけは403）', function (Role $role, bool $submitOnly, int $status): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember($role, $submitOnly);

    $this->actingAs($user)->get(route('company.schedules', $company))->assertStatus($status);
    expect(Gate::forUser($user)->allows('manageSchedules', $company))->toBe($status === 200);

    if ($status === 200) {
        schedulePage($company, $user)->assertOk();
    } else {
        schedulePage($company, $user)->assertForbidden();
    }
})->with([
    'owner' => [Role::Owner, false, 200],
    'editor' => [Role::Editor, false, 200],
    'admin' => [Role::Admin, false, 403],
    'approver' => [Role::Approver, false, 403],
    'viewer' => [Role::Viewer, false, 403],
    '送るだけ' => [Role::Editor, true, 403],
    '送るだけ（owner の役割でも）' => [Role::Owner, true, 403],
]);

test('T58 権限：未ログイン・2段階認証がまだ・無効な所属・所属なしの人は、予定の画面を開けない', function (): void {
    ['company' => $company, 'user' => $owner, 'membership' => $membership] = createCompanyWithMember(Role::Owner);
    ['user' => $noTwoFactor] = createCompanyWithMember(Role::Owner, withSecurityAnswer: false, company: $company);
    ['user' => $outsider] = createCompanyWithMember(Role::Owner);

    $this->get(route('company.schedules', $company))->assertRedirect(route('login'));

    expect(Gate::forUser($noTwoFactor)->allows('manageSchedules', $company))->toBeFalse()
        ->and(Gate::forUser($outsider)->allows('manageSchedules', $company))->toBeFalse();
    $this->actingAs($outsider)->get(route('company.schedules', $company))->assertNotFound();
    expect($this->actingAs($noTwoFactor)->get(route('company.schedules', $company))->status())->not->toBe(200);

    $membership->forceFill(['disabled_at' => now()])->save();
    expect(Gate::forUser($owner->fresh())->allows('manageSchedules', $company))->toBeFalse();
    $this->actingAs($owner->fresh())->get(route('company.schedules', $company))->assertNotFound();
});

test('T58 権限：画面を開いたあとで役割が変わったら、どの操作も欄の変更も403で、データは変わらない', function (array $change, string $method, array $args): void {
    ['company' => $company, 'user' => $user, 'membership' => $membership] = createCompanyWithMember(Role::Editor);
    $partner = schedulePartner($company, $user);
    $open = scheduleAdd($company, $user, ScheduleCategory::Sales, 1_000_000, '2026-11-10', $partner->id);
    $settled = scheduleAdd($company, $user, ScheduleCategory::Purchase, 200_000, '2026-11-10', $partner->id);
    app(CashScheduleService::class)->settle($company, $user, $settled->id, CarbonImmutable::parse('2026-11-12'));

    $page = schedulePage($company, $user)
        ->set('amountYen', '5000')
        ->set('dueDate', '2026-11-30')
        ->set('partnerName', '足せないはずの架空商店')
        ->set('renameName', '直せないはずの架空商店');
    $audits = AuditLog::query()->count();

    $membership->forceFill($change)->save();

    $args = array_map(fn (string $arg): string => match ($arg) {
        ':open' => $open->id,
        ':settled' => $settled->id,
        ':partner' => $partner->id,
        default => $arg,
    }, $args);

    if ($method === 'set') {
        $page->set(...$args)->assertForbidden();
    } else {
        $page->call($method, ...$args)->assertForbidden();
    }

    expect($company->cashSchedules()->count())->toBe(2)
        ->and($open->refresh()->settled_on)->toBeNull()
        ->and($open->amount_yen)->toBe(1_000_000)
        ->and($settled->refresh()->settled_on?->toDateString())->toBe('2026-11-12')
        ->and($company->businessPartners()->count())->toBe(1)
        ->and($partner->refresh()->name)->toBe(SCHEDULE_PARTNER_A)
        ->and($partner->archived_at)->toBeNull()
        ->and(AuditLog::query()->count())->toBe($audits);
})->with([
    'admin になった' => [['role' => Role::Admin]],
    'viewer になった' => [['role' => Role::Viewer]],
    '送るだけになった' => [['submit_only' => true]],
    '無効にされた' => [['disabled_at' => '2026-11-21 08:00:00']],
])->with([
    '予定を入れる' => ['save', []],
    '直しはじめる' => ['startEdit', [':open']],
    '直すのをやめる' => ['cancelEdit', []],
    '済みにする' => ['settle', [':open']],
    '済みを戻す' => ['unsettle', [':settled']],
    '取り消す' => ['remove', [':open']],
    '取引先を足す' => ['addPartner', []],
    '名前を直しはじめる' => ['startRename', [':partner']],
    '名前を直すのをやめる' => ['cancelRename', []],
    '名前を保存する' => ['saveRename', []],
    '使わなくする' => ['archivePartner', [':partner']],
    '欄を変える（描き直し）' => ['set', ['direction', 'out']],
    '読み直す' => ['$refresh', []],
]);

test('T58 「今日」は日本の日付で決める（日本時間の午前0時台でも、遅れの日数と済みにできる日がずれない）', function (): void {
    // 日本時間 11月22日 0:30（UTC では 11月21日 15:30）
    $this->travelTo(CarbonImmutable::parse('2026-11-21 15:30:00', 'UTC'));
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);
    $yesterday = scheduleAdd($company, $user, ScheduleCategory::Sales, 1000, '2026-11-21');
    $today = scheduleAdd($company, $user, ScheduleCategory::Sales, 2000, '2026-11-22');

    expect(CarbonImmutable::today()->toDateString())->toBe('2026-11-22')
        ->and(app(CashScheduleBoard::class)->lateReceipts($company))->toMatchArray(['count' => 1, 'total_yen' => 1000]);

    schedulePage($company, $user)
        ->assertSeeInOrder(['11月21日(土)', '遅れ（1日）', '11月22日(日)', '今日'])
        ->call('settle', $today->id)
        ->assertHasNoErrors();

    expect($today->refresh()->settled_on?->toDateString())->toBe('2026-11-22')
        ->and($yesterday->refresh()->settled_on)->toBeNull();
    expect(fn () => app(CashScheduleService::class)->settle($company, $user, $yesterday->id, CarbonImmutable::parse('2026-11-23')))->toThrow(ValidationException::class);
});

test('T58 取引先の保存でデータベースのエラーが起きても、エラーの文と呼び出しの記録に取引先の名前が入らない', function (Closure $call): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);
    $partner = schedulePartner($company, $user, SCHEDULE_PARTNER_B);
    $service = app(CashScheduleService::class);

    // business_partners への書き込み・名前での検索を、SQL のエラー（入れた値つき）にする
    DB::connection()->beforeExecuting(function (string $query, array $bindings, Connection $connection): void {
        if (str_contains($query, 'business_partners') && in_array(SCHEDULE_PARTNER_A, $bindings, true)) {
            throw new QueryException($connection->getName() ?? 'mysql', $query, $bindings, new Exception('架空のデータベースのエラー'));
        }
    });

    try {
        $call($service, $company, $user, $partner);
        $this->fail('エラーにならなかった');
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf(RuntimeException::class)->not->toBeInstanceOf(QueryException::class)
            ->and($e->getPrevious())->toBeNull()
            ->and($e->getMessage())->toContain('取引先を保存できませんでした')
            ->and($e->getMessage().$e->getTraceAsString().json_encode($e->getTrace(), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR))
            ->not->toContain(SCHEDULE_PARTNER_A)
            ->not->toContain('みちのく');
    }

    expect($company->businessPartners()->count())->toBe(1)
        ->and($partner->refresh()->name)->toBe(SCHEDULE_PARTNER_B);

    // 呼び出しの記録（スタックトレース）に引数を残す設定のサーバーでも名前が出ないよう、名前の引数には SensitiveParameter を付けてある
    foreach (['addPartner', 'renamePartner', 'partnerName'] as $method) {
        $name = collect((new ReflectionMethod(CashScheduleService::class, $method))->getParameters())->firstOrFail(fn (ReflectionParameter $parameter): bool => $parameter->getName() === 'name');
        expect($name->getAttributes(SensitiveParameter::class))->toHaveCount(1, $method);
    }
})->with([
    '足す' => [fn (CashScheduleService $service, Company $company, User $user, BusinessPartner $partner) => $service->addPartner($company, $user, SCHEDULE_PARTNER_A)],
    '名前を直す' => [fn (CashScheduleService $service, Company $company, User $user, BusinessPartner $partner) => $service->renamePartner($company, $user, $partner->id, SCHEDULE_PARTNER_A)],
]);

test('T58 まだの予定が200件を超えたら、古い順に200件を並べて、出ていない件数を知らせる（件数と金額は全部の合計）', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);
    foreach ([10, 11, 12] as $day) {
        scheduleAdd($company, $user, ScheduleCategory::Sales, 1000, "2026-12-{$day}");
    }

    expect(app(CashScheduleBoard::class)->open($company, 2)->map(fn (CashSchedule $schedule): string => $schedule->due_date->toDateString())->all())->toBe(['2026-12-10', '2026-12-11']);
    schedulePage($company, $user)->assertSee('3件・3,000円')->assertDontSee('data-test="schedule-open-more"', false);

    for ($i = 0; $i < 198; $i++) {
        scheduleAdd($company, $user, ScheduleCategory::Sales, 1000, '2026-12-01');
    }

    schedulePage($company, $user)
        ->assertSee('201件・201,000円')
        ->assertSee('data-test="schedule-open-more"', false)
        ->assertSee('200件まで出しています（ほか 1件）')
        ->assertDontSee('12月12日');
});

test('T58 サイドバーの「入金と支払いの予定」は、開ける人にだけ出る', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    ['user' => $editor] = createCompanyWithMember(Role::Editor, company: $company);
    ['user' => $admin] = createCompanyWithMember(Role::Admin, company: $company);
    ['user' => $viewer] = createCompanyWithMember(Role::Viewer, company: $company);

    $this->actingAs($owner)->get(route('company.home', $company))->assertSee('data-test="sidebar-schedules"', false);
    $this->actingAs($editor)->get(route('company.home', $company))->assertSee('data-test="sidebar-schedules"', false);
    $this->actingAs($admin)->get(route('company.home', $company))->assertDontSee('data-test="sidebar-schedules"', false);
    $this->actingAs($viewer)->get(route('company.home', $company))->assertDontSee('data-test="sidebar-schedules"', false);
});

test('T58 社長の画面：社長には「入金の遅れ」が件数・合計・取引先の名前つきで出る。済み・これから・支払いは入らない', function (): void {
    ['company' => $company, 'owner' => $owner, 'partnerA' => $partnerA] = scheduleCompany();
    $settled = scheduleAdd($company, $owner, ScheduleCategory::Sales, 9_990_000, '2026-11-01', $partnerA->id);
    app(CashScheduleService::class)->settle($company, $owner, $settled->id, CarbonImmutable::parse('2026-11-05'));
    scheduleAdd($company, $owner, ScheduleCategory::Sales, 8_880_000, '2026-11-21', $partnerA->id);
    scheduleAdd($company, $owner, ScheduleCategory::Lease, 7_770_000, '2026-11-01', $partnerA->id);

    $this->actingAs($owner)->get(route('company.owner', $company))
        ->assertOk()
        ->assertSee('data-test="owner-late-receipts"', false)
        ->assertSeeInOrder(['入金の遅れ', '2件', '合計', '1,580,100円'])
        ->assertSeeInOrder(['11月10日(火)の予定', SCHEDULE_PARTNER_A, '11日の遅れ', '1,234,500円', '11月20日(金)の予定', '個人のお客さん', '1日の遅れ', '345,600円'])
        ->assertSee(route('company.schedules', $company), false)
        ->assertDontSee('9,990,000円')
        ->assertDontSee('8,880,000円')
        ->assertDontSee('7,770,000円')
        ->assertDontSee(SCHEDULE_PARTNER_B)
        ->assertDontSee('765,400');

    expect(app(CashScheduleBoard::class)->lateReceipts($company))->toMatchArray(['count' => 2, 'total_yen' => 1_580_100, 'more' => 0]);
});

test('T58 社長の画面：遅れがなければ「入金の遅れ」は出ない。6件以上は5件まで並べて、残りは件数だけ', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    $partner = schedulePartner($company, $owner);
    scheduleAdd($company, $owner, ScheduleCategory::Sales, 500_000, '2026-11-21', $partner->id);

    $this->actingAs($owner)->get(route('company.owner', $company))
        ->assertOk()
        ->assertDontSee('data-test="owner-late-receipts"', false)
        ->assertDontSee(SCHEDULE_PARTNER_A);

    foreach (range(1, 7) as $day) {
        scheduleAdd($company, $owner, ScheduleCategory::Sales, 100_000 + $day, "2026-11-0{$day}", $partner->id);
    }

    $html = $this->actingAs($owner)->get(route('company.owner', $company))
        ->assertOk()
        ->assertSeeInOrder(['入金の遅れ', '7件', '合計', '700,028円'])
        ->assertSee('ほか 2件')
        ->getContent();
    expect(substr_count((string) $html, 'data-test="owner-late-row"'))->toBe(CashScheduleBoard::OWNER_ROWS);
});

test('T58 見えてはいけない人の応答に、取引先の名前・金額・給料の合計が入らない（予定の画面・社長の画面・ホーム・送る画面）', function (Role $role, bool $submitOnly, int $ownerScreenStatus): void {
    ['company' => $company, 'owner' => $owner] = scheduleCompany();
    ['user' => $user] = createCompanyWithMember($role, $submitOnly, company: $company);

    // 比べる相手：社長には、同じ文字が予定の画面と社長の画面に出ている（確かめる文字が合っていること）
    $ownerSchedules = (string) $this->actingAs($owner)->get(route('company.schedules', $company))->assertOk()->getContent();
    foreach ([SCHEDULE_PARTNER_A, SCHEDULE_PARTNER_B, '1,234,500', '2,345,600', '765,400', '345,600'] as $secret) {
        expect($ownerSchedules)->toContain($secret);
    }
    $ownerScreen = (string) $this->actingAs($owner)->get(route('company.owner', $company))->assertOk()->getContent();
    foreach ([SCHEDULE_PARTNER_A, '1,234,500', '345,600', '1,580,100'] as $secret) {
        expect($ownerScreen)->toContain($secret);
    }

    $bodies = [];
    $response = $this->actingAs($user)->get(route('company.schedules', $company))->assertForbidden();
    $bodies['予定の画面'] = (string) $response->getContent();
    $response = $this->actingAs($user)->get(route('company.owner', $company))->assertStatus($ownerScreenStatus);
    $bodies['社長の画面'] = (string) $response->getContent();
    $bodies['ホーム'] = (string) $this->actingAs($user)->get(route('company.home', $company))->getContent();
    $bodies['送る画面'] = (string) $this->actingAs($user)->get(route('company.submit', $company))->getContent();

    // Livewire の部品として直接開いても同じ（応答の HTML と、画面が持つ状態の両方）
    schedulePage($company, $user)->assertForbidden();
    $ownerPage = Livewire::actingAs($user)->test('pages::company.owner', ['company' => $company]);
    if ($ownerScreenStatus === 200) {
        $bodies['社長の画面（部品）'] = $ownerPage->assertOk()->html();
        $bodies['社長の画面（状態）'] = (string) json_encode([$ownerPage->snapshot, $ownerPage->effects], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        expect($bodies['社長の画面'])->toContain('社長の画面')->not->toContain('data-test="owner-late-receipts"')->not->toContain('入金の遅れ');
    } else {
        $ownerPage->assertForbidden();
    }

    // 確かめ方そのものが働くこと（社長の応答からは見つかる）
    expect(scheduleLeaks($ownerSchedules))->toContain(SCHEDULE_PARTNER_A, SCHEDULE_PARTNER_B, '1,234,500', '2,345,600', '765,400', '345,600');
    expect(scheduleLeaks($ownerScreen))->toContain(SCHEDULE_PARTNER_A, '1,234,500', '345,600', '1,580,100');
    expect(scheduleLeaks('{"amount_yen":765400,"name":"\u307f\u3061\u306e\u304f\u67b6\u7a7a\u5efa\u8a2d"}'))->toBe([SCHEDULE_PARTNER_A, '765400']);

    foreach ($bodies as $where => $body) {
        expect($body)->not->toBe('');
        expect(scheduleLeaks($body))->toBe([], "{$where} に見えてはいけない文字が入っている");
    }
})->with([
    'admin（社長の画面は開ける）' => [Role::Admin, false, 200],
    'approver' => [Role::Approver, false, 403],
    'viewer' => [Role::Viewer, false, 403],
    '送るだけ' => [Role::Editor, true, 403],
]);

test('T58 経理担当は社長の画面を開けない（予定の画面で見る）。社長の画面を開いたあとで admin になった人には、遅れを描き直さない', function (): void {
    ['company' => $company, 'owner' => $owner] = scheduleCompany();
    ['user' => $editor] = createCompanyWithMember(Role::Editor, company: $company);

    $this->actingAs($editor)->get(route('company.owner', $company))->assertForbidden();

    $page = Livewire::actingAs($owner)->test('pages::company.owner', ['company' => $company])
        ->assertSee('入金の遅れ')
        ->assertSee(SCHEDULE_PARTNER_A);

    $company->memberships()->where('user_id', $owner->id)->sole()->forceFill(['role' => Role::Admin])->save();

    $html = $page->call('$refresh')->assertOk()->html();
    expect(scheduleLeaks($html))->toBe([])
        ->and(scheduleLeaks((string) json_encode([$page->snapshot, $page->effects], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)))->toBe([])
        ->and($html)->not->toContain('入金の遅れ');
});

test('T58 分析用の書き出しと AI への入力に、取引先の名前も予定の金額も入らない', function (): void {
    Storage::fake('evidence');
    Storage::fake('local');
    ['company' => $company, 'owner' => $owner] = scheduleCompany();
    putSales($company, '2026-10', 3_000_000);
    putSales($company, '2025-10', 2_800_000);
    putCustomer($company, 1, 'A社', 8_000_000);
    putCustomer($company, 2, 'B社', 2_000_000);
    sendReceipt($company, $owner);

    // 社長の画面を開くと、ひとこと・見込みの AI が呼ばれる（テストでは偽物）
    $this->actingAs($owner)->get(route('company.owner', $company))->assertOk()->assertSee('入金の遅れ');

    $requests = app(FakeClaudeClient::class)->requests;
    $tasks = array_map(fn ($request): AiTask => $request->task, $requests);
    expect($tasks)->toContain(AiTask::OwnerBrief)->toContain(AiTask::Forecast)->toContain(AiTask::ReadReceipt);
    $aiText = (string) json_encode(array_map(fn ($request): array => ['input' => $request->input], $requests), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $aiRuns = (string) json_encode($company->aiRuns()->get()->map(fn ($run): array => ['input' => $run->input, 'output' => $run->output])->all(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $insights = (string) json_encode($company->ownerInsights()->get()->map(fn ($insight): array => ['output' => $insight->output])->all(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $export = app(TrainingDataExporter::class)->export($company, CarbonImmutable::now()->startOfMonth());
    $path = (string) tempnam(sys_get_temp_dir(), 'schedule-zip-');
    file_put_contents($path, $export['contents']);
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $exportText = '';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $exportText .= $zip->getNameIndex($i)."\n".$zip->getFromIndex($i)."\n";
    }
    $zip->close();
    unlink($path);
    expect($exportText)->toContain('manifest.json')->toContain('journal_entries.jsonl');

    foreach (['AI への入力' => $aiText, 'AI の記録' => $aiRuns, 'ひとこと・見込み' => $insights, '分析用の書き出し' => $exportText] as $where => $text) {
        expect(scheduleLeaks($text))->toBe([], "{$where} に見えてはいけない文字が入っている");
        expect($text)->not->toContain('business_partner')->not->toContain('cash_schedule');
    }
});

test('T58 会社・作った人・済みの印は、一括代入では入れられない', function (): void {
    expect(fn () => (new CashSchedule)->fill(['company_id' => 'x', 'amount_yen' => 1, 'settled_on' => '2026-11-21']))
        ->toThrow(MassAssignmentException::class);
    expect(fn () => (new BusinessPartner)->fill(['company_id' => 'x', 'name' => SCHEDULE_PARTNER_A]))
        ->toThrow(MassAssignmentException::class);
});
