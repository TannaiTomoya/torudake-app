<?php

use App\Enums\FigureMetric;
use App\Enums\FigureSource;
use App\Enums\ReportStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\ExternalMonthlyFigure;
use App\Models\ReportImport;
use App\Models\User;
use App\Services\Ai\FakeClaudeClient;
use App\Services\ReportImportService;
use App\Support\FiscalYear;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

function readReportPhoto(Company $company, User $user): ReportImport
{
    Livewire::actingAs($user)
        ->test('pages::company.reports', ['company' => $company])
        ->set('photo', UploadedFile::fake()->image('report.jpg', 800, 600))
        ->call('upload')
        ->assertHasNoErrors();

    return $company->reportImports()->latest('created_at')->firstOrFail();
}

function currentPeriodMonth(Company $company, int $i): string
{
    return FiscalYear::containing(now(), $company->fiscal_year_end_month)->months()[$i];
}

function reportForSaveTest(Company $company, User $user, int $amount, ReportStatus $status = ReportStatus::Review): ReportImport
{
    $import = new ReportImport;
    $import->forceFill([
        'company_id' => $company->id,
        'kind' => ReportImport::KIND_MONTHLY_SALES,
        'status' => $status,
        'rows' => [['year_month' => '2026-10', 'amount_yen' => $amount, 'confidence' => 0.99]],
        'created_by' => $user->id,
    ])->save();

    return $import;
}

test('T42: 他の管理者が新しい帳票を送っても、表示していた帳票だけを保存する', function (ReportStatus $newStatus): void {
    ['company' => $company, 'user' => $admin] = createCompanyWithMember(Role::Admin);
    ['user' => $owner] = createCompanyWithMember(Role::Owner, company: $company);
    $shown = reportForSaveTest($company, $admin, 1_200_000);
    $screen = Livewire::actingAs($admin)->test('pages::company.reports', ['company' => $company])
        ->assertSet('displayedImportId', $shown->id)
        ->assertSee('1,200,000円');

    $this->travel(1)->seconds();
    $new = reportForSaveTest($company, $owner, 9_000_000, $newStatus);
    $screen->call('save')->assertHasNoErrors()->assertSee('1か月分の売上を保存しました');

    $figure = $company->externalMonthlyFigures()->sole();
    expect($figure->amount_yen)->toBe(1_200_000)
        ->and($figure->report_import_id)->toBe($shown->id)
        ->and($shown->refresh()->status)->toBe(ReportStatus::Saved)
        ->and($shown->saved_by)->toBe($admin->id)
        ->and($new->refresh()->status)->toBe($newStatus)
        ->and($new->saved_at)->toBeNull();
})->with([ReportStatus::Reading, ReportStatus::Review]);

test('T42: 表示した帳票を別の人が先に保存したら理由を表示し、二重保存しない', function (): void {
    ['company' => $company, 'user' => $admin] = createCompanyWithMember(Role::Admin);
    ['user' => $owner] = createCompanyWithMember(Role::Owner, company: $company);
    $import = reportForSaveTest($company, $admin, 1_200_000);
    $screen = Livewire::actingAs($admin)->test('pages::company.reports', ['company' => $company]);
    app(ReportImportService::class)->save($company, $owner, $import->id);

    $screen->call('save')->assertHasErrors('save')
        ->assertSeeHtml('data-test="report-save-error"')
        ->assertSee('この取り込みはすでに保存されています。画面を読み直してください。');

    expect($import->refresh()->saved_by)->toBe($owner->id)
        ->and($company->externalMonthlyFigures()->sole()->amount_yen)->toBe(1_200_000)
        ->and(AuditLog::query()->where('action', 'report.save')->where('subject_id', $import->id)->count())->toBe(1);
});

test('T42: 保存できない帳票の状態を画面に表示し、数字を書き込まない', function (ReportStatus $status, string $message): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    $import = reportForSaveTest($company, $owner, 1_200_000, $status);

    Livewire::actingAs($owner)->test('pages::company.reports', ['company' => $company])
        ->call('save')->assertHasErrors('save')->assertSee($message);

    expect($company->externalMonthlyFigures()->count())->toBe(0)
        ->and($import->refresh()->status)->toBe($status);
})->with([
    '読み取り中' => [ReportStatus::Reading, 'AIが読み取り中です。完了してから保存してください。'],
    '読み取り失敗' => [ReportStatus::Failed, '読み取りに失敗した取り込みは保存できません。写真を選び直してください。'],
]);

test('T42: 表示対象がなくなっても新しい取り込みへ切り替えて保存しない', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    $shown = reportForSaveTest($company, $owner, 1_200_000);
    $screen = Livewire::actingAs($owner)->test('pages::company.reports', ['company' => $company]);
    $shown->delete();
    $new = reportForSaveTest($company, $owner, 9_000_000);

    $screen->call('save')->assertHasErrors('save')->assertSee('保存する取り込みがありません。もう一度画面を読み込んでください。');
    expect($company->externalMonthlyFigures()->count())->toBe(0)
        ->and($new->refresh()->status)->toBe(ReportStatus::Review);
});

test('T42: 同時刻に取り込んだ帳票はID順で最新を表示する', function (): void {
    $this->travelTo(now()->startOfSecond());
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    reportForSaveTest($company, $owner, 1_200_000);
    $new = reportForSaveTest($company, $owner, 9_000_000);
    Livewire::actingAs($owner)->test('pages::company.reports', ['company' => $company])
        ->assertSet('displayedImportId', $new->id)->assertSee('9,000,000円');
});

test('T42: 表示中の取り込みIDと会社IDはブラウザーから改変できない', function (string $property): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    ['company' => $other, 'user' => $otherOwner] = createCompanyWithMember(Role::Owner);
    reportForSaveTest($company, $owner, 1_200_000);
    $otherImport = reportForSaveTest($other, $otherOwner, 9_000_000);
    $screen = Livewire::actingAs($owner)->test('pages::company.reports', ['company' => $company]);
    expect(fn () => $screen->set($property, $property === 'companyId' ? $other->id : $otherImport->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
    expect(ExternalMonthlyFigure::query()->count())->toBe(0);
})->with(['companyId', 'displayedImportId']);

test('T42: 他社の取り込みIDはサービスへ渡しても404で、どちらの会社の数字も変わらない', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    ['company' => $other, 'user' => $otherOwner] = createCompanyWithMember(Role::Owner);
    $otherImport = reportForSaveTest($other, $otherOwner, 9_000_000);
    expect(fn () => app(ReportImportService::class)->save($company, $owner, $otherImport->id))
        ->toThrow(ModelNotFoundException::class);
    expect(ExternalMonthlyFigure::query()->count())->toBe(0)
        ->and($otherImport->refresh()->status)->toBe(ReportStatus::Review);
});

test('T42: 画面を開いた後に経理ロールへ変わると保存は403で数字が変わらない', function (): void {
    ['company' => $company, 'user' => $admin, 'membership' => $membership] = createCompanyWithMember(Role::Admin);
    $import = reportForSaveTest($company, $admin, 1_200_000);
    $screen = Livewire::actingAs($admin)->test('pages::company.reports', ['company' => $company]);
    $membership->forceFill(['role' => Role::Editor])->save();
    $screen->call('save')->assertForbidden();
    expect($company->externalMonthlyFigures()->count())->toBe(0)
        ->and($import->refresh()->status)->toBe(ReportStatus::Review);
});

test('T42: 保存の再実行で失敗したときに前の成功表示を残さない', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    $import = reportForSaveTest($company, $owner, 1_200_000);
    Livewire::actingAs($owner)->test('pages::company.reports', ['company' => $company])
        ->call('save')->assertSeeHtml('data-test="report-notice"')
        ->call('save')->assertHasErrors('save')
        ->assertDontSeeHtml('data-test="report-notice"');
    expect(AuditLog::query()->where('action', 'report.save')->where('subject_id', $import->id)->count())->toBe(1);
});

test('T42: 予期しない保存失敗は案内を表示し、内部エラーを画面へ出さない', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    $import = reportForSaveTest($company, $owner, 1_200_000);
    $this->mock(ReportImportService::class)->shouldReceive('save')->once()
        ->andThrow(new RuntimeException('internal storage detail'));
    Livewire::actingAs($owner)->test('pages::company.reports', ['company' => $company])
        ->call('save')->assertHasErrors('save')
        ->assertSee('保存できませんでした。内容を確認して、もう一度お試しください。')
        ->assertDontSee('internal storage detail');
    expect($company->externalMonthlyFigures()->count())->toBe(0)
        ->and($import->refresh()->status)->toBe(ReportStatus::Review);
});

test('T19: 帳票を読むだけでは月ごとの売上に入らず、自信が低い月に「確かめて」が出る', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);

    $import = readReportPhoto($company, $owner);

    expect(app(FakeClaudeClient::class)->requests[0]->attachments[0]['media_type'])->toBe('image/jpeg');

    expect($import->status)->toBe(ReportStatus::Review)
        ->and($import->rows)->toHaveCount(3)
        ->and($import->evidence?->purge_after->isFuture())->toBeTrue()
        ->and(ExternalMonthlyFigure::query()->where('company_id', $company->id)->count())->toBe(0);

    Livewire::actingAs($owner)
        ->test('pages::company.reports', ['company' => $company])
        ->assertSeeHtml('data-test="report-check-'.currentPeriodMonth($company, 2).'"')
        ->assertDontSeeHtml('data-test="report-check-'.currentPeriodMonth($company, 0).'"')
        ->assertSee('4,300,000円');
});

test('帳票取り込みは未変換のHEIC・HEIFを受け付けない', function (string $format): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);

    Livewire::actingAs($owner)
        ->test('pages::company.reports', ['company' => $company])
        ->set('photo', UploadedFile::fake()->create("report.{$format}", 10, "image/{$format}"))
        ->call('upload')
        ->assertHasErrors(['photo']);

    expect($company->reportImports()->count())->toBe(0)
        ->and($company->evidences()->count())->toBe(0);
})->with(['heic', 'heif']);

test('T19: 保存を押すと月ごとの売上に入り、既存の数字と違う月が分かる', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Admin);
    $first = currentPeriodMonth($company, 0);

    $old = new ExternalMonthlyFigure;
    $old->forceFill(['company_id' => $company->id, 'year_month' => $first, 'metric' => FigureMetric::Sales, 'amount_yen' => 1_000_000, 'source' => FigureSource::Demo])->save();

    readReportPhoto($company, $owner);

    Livewire::actingAs($owner)
        ->test('pages::company.reports', ['company' => $company])
        ->assertSeeHtml('data-test="report-differs-'.$first.'"')
        ->assertDontSeeHtml('data-test="report-differs-'.currentPeriodMonth($company, 1).'"')
        ->call('save')
        ->assertSee('3か月分の売上を保存しました');

    $figures = $company->externalMonthlyFigures()->where('metric', FigureMetric::Sales)->pluck('amount_yen', 'year_month');

    expect($figures->all())->toBe([
        $first => 3_672_000,
        currentPeriodMonth($company, 1) => 3_888_000,
        currentPeriodMonth($company, 2) => 4_300_000,
    ])
        ->and($company->reportImports()->firstOrFail()->status)->toBe(ReportStatus::Saved);
});

test('T19: 他社の人は帳票の画面を開けない（404）', function (): void {
    ['company' => $company] = createCompanyWithMember(Role::Owner);
    ['user' => $other] = createCompanyWithMember(Role::Owner);

    $this->actingAs($other)->get(route('company.reports', $company))->assertNotFound();
});

test('T19: 帳票の取り込みは owner・admin だけ。経理担当・承認者・閲覧は 403', function (Role $role, int $status): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember($role);

    $this->actingAs($user)->get(route('company.reports', $company))->assertStatus($status);
})->with([
    'owner' => [Role::Owner, 200],
    'admin' => [Role::Admin, 200],
    'approver' => [Role::Approver, 403],
    'editor' => [Role::Editor, 403],
    'viewer' => [Role::Viewer, 403],
]);
