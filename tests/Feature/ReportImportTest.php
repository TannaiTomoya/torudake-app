<?php

use App\Enums\FigureMetric;
use App\Enums\FigureSource;
use App\Enums\ReportStatus;
use App\Enums\Role;
use App\Models\Company;
use App\Models\ExternalMonthlyFigure;
use App\Models\ReportImport;
use App\Models\User;
use App\Support\FiscalYear;
use Illuminate\Http\UploadedFile;
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

test('T19: 帳票を読むだけでは月ごとの売上に入らず、自信が低い月に「確かめて」が出る', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);

    $import = readReportPhoto($company, $owner);

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

test('帳票取り込みは未変換のHEICを受け付けない', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);

    Livewire::actingAs($owner)
        ->test('pages::company.reports', ['company' => $company])
        ->set('photo', UploadedFile::fake()->create('report.heic', 10, 'image/heic'))
        ->call('upload')
        ->assertHasErrors(['photo']);

    expect($company->reportImports()->count())->toBe(0)
        ->and($company->evidences()->count())->toBe(0);
});

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
