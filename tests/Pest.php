<?php

use App\Enums\EntryKind;
use App\Enums\EntryStatus;
use App\Enums\FigureMetric;
use App\Enums\FigureSource;
use App\Enums\Role;
use App\Models\Company;
use App\Models\ExternalCustomerSale;
use App\Models\ExternalMonthlyFigure;
use App\Models\JournalEntry;
use App\Models\Membership;
use App\Models\SourceRecord;
use App\Models\User;
use App\Services\JournalLineWriter;
use App\Services\SubmitEvidence;
use Database\Seeders\CompanyDefaultSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => Storage::fake('local'))
    ->in('Feature');

/**
 * 勘定科目・分類・口座の初期値つきの会社と、そのメンバーを作る。
 *
 * @return array{company: Company, user: User, membership: Membership}
 */
function createCompanyWithMember(
    Role $role = Role::Owner,
    bool $submitOnly = false,
    bool $withTwoFactor = true,
    ?Company $company = null,
): array {
    if ($company === null) {
        $company = Company::factory()->create(['name' => 'テスト建機']);
        CompanyDefaultSeeder::seedForCompany($company);
    }

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

/**
 * 写真を1枚送り、AI の下書きまで済んだ原資料を返す（キューは同期で動く）。
 */
function sendReceipt(Company $company, User $user, int $width = 400): SourceRecord
{
    $result = app(SubmitEvidence::class)->submit($company, $user, UploadedFile::fake()->image('receipt.jpg', $width, 600));

    return $result->source?->fresh() ?? throw new RuntimeException('原資料ができていません');
}

function categoryId(Company $company, string $name): string
{
    return (string) $company->categories()->where('name', $name)->value('id');
}

function bankAccountId(Company $company, string $name): string
{
    return (string) $company->bankAccounts()->where('name', $name)->value('id');
}

function putSales(Company $company, string $yearMonth, int $amount): void
{
    $figure = new ExternalMonthlyFigure;
    $figure->forceFill([
        'company_id' => $company->id,
        'year_month' => $yearMonth,
        'metric' => FigureMetric::Sales,
        'amount_yen' => $amount,
        'source' => FigureSource::Manual,
    ])->save();
}

function putCustomer(Company $company, int $rank, string $label, int $amount): void
{
    $row = new ExternalCustomerSale;
    $row->forceFill([
        'company_id' => $company->id,
        'period_from' => '2025-08-01',
        'period_to' => '2026-07-31',
        'rank' => $rank,
        'label' => $label,
        'amount_yen' => $amount,
    ])->save();
}

/**
 * @param  list<string>|null  $flags
 */
function putEntry(Company $company, string $date, string $category, int $amount, EntryStatus $status = EntryStatus::Confirmed, ?array $flags = null, ?string $vendor = '〇〇石油 八幡平店'): JournalEntry
{
    $categoryModel = $company->categories()->where('name', $category)->firstOrFail();
    $bankAccount = $company->bankAccounts()->where('name', '現金')->firstOrFail();

    $entry = new JournalEntry;
    $entry->forceFill([
        'company_id' => $company->id,
        'entry_date' => $date,
        'kind' => EntryKind::Normal,
        'status' => $status,
        'vendor' => $vendor,
        'category_id' => $categoryModel->id,
        'bank_account_id' => $bankAccount->id,
        'confirmed_at' => $status === EntryStatus::Confirmed ? now() : null,
        'concern_flags' => $flags,
    ])->save();
    app(JournalLineWriter::class)->rewrite($entry, $categoryModel, $bankAccount, $amount);

    return $entry;
}
