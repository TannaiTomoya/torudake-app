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
use App\Services\JournalLineWriter;
use App\Services\OwnerDashboard;
use Livewire\Livewire;

beforeEach(fn () => $this->travelTo(now()->setDate(2026, 10, 24)->setTime(9, 0)));

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

test('T20 社長の画面の三期比較・前年同月の差・3期の平均・売上の柱が、入れた数字と一致する', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    putSales($company, '2026-08', 3_600_000);
    putSales($company, '2025-08', 3_000_000);
    putSales($company, '2024-08', 2_800_000);
    putSales($company, '2026-09', 2_000_000);
    putSales($company, '2025-09', 2_500_000);
    putCustomer($company, 1, 'A社', 8_000_000);
    putCustomer($company, 2, 'B社', 2_000_000);

    $dashboard = app(OwnerDashboard::class)->build($company);
    $august = $dashboard['months'][0];

    expect($dashboard['periods'])->toBe(['current' => '2027年7月期', 'previous' => '2026年7月期', 'before' => '2025年7月期'])
        ->and($august)->toMatchArray([
            'month' => 8, 'current' => 3_600_000, 'previous' => 3_000_000, 'before' => 2_800_000,
            'yoy_diff' => 600_000, 'yoy_pct' => 20, 'average' => 3_133_333, 'latest_year_month' => '2026-08',
        ])
        ->and($dashboard['months'][1]['yoy_diff'])->toBe(-500_000)
        ->and($dashboard['totals'])->toMatchArray(['current' => 5_600_000, 'previous_same_months' => 5_500_000, 'diff' => 100_000, 'pct' => 2, 'months_with_sales' => 2])
        ->and($dashboard['customers']['top'] ?? null)->toBe([
            ['label' => 'A社', 'amount_yen' => 8_000_000, 'share_pct' => 80],
            ['label' => 'B社', 'amount_yen' => 2_000_000, 'share_pct' => 20],
        ]);

    $this->actingAs($owner)->get(route('company.owner', $company))
        ->assertOk()
        ->assertSee('三期比較（売上）')
        ->assertSee('3,600,000円')
        ->assertSee('+600,000円（+20%）')
        ->assertSee('−500,000円（−20%）')
        ->assertSee('3,133,333円')
        ->assertSee('前期の同じ月より +100,000円（+2%）')
        ->assertSeeInOrder(['A社', '80%', 'B社', '20%']);
});

test('T26 忙しい月は表とグラフで色づけし、忙しい月なのに前年同月より少ないと印を出す', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    putSales($company, '2026-07', 3_000_000);
    putSales($company, '2025-07', 3_500_000);
    putSales($company, '2026-06', 4_000_000);
    putSales($company, '2025-06', 3_500_000);

    $dashboard = app(OwnerDashboard::class)->build($company);
    $byMonth = collect($dashboard['months'])->keyBy('month');

    expect($byMonth[7])->toMatchArray(['busy' => true, 'latest_year_month' => '2026-07', 'yoy_diff' => -500_000, 'busy_but_lower' => true])
        ->and($byMonth[6]['busy_but_lower'])->toBeFalse()
        ->and($byMonth[8]['busy'])->toBeFalse()
        ->and(collect($dashboard['months'])->where('busy', true)->pluck('month')->sort()->values()->all())->toBe([6, 7, 10, 11, 12]);

    $html = $this->actingAs($owner)->get(route('company.owner', $company))->assertOk()->getContent();

    expect($html)->toContain('data-month="10" data-busy="1"')
        ->toContain('data-month="8" data-busy="0"')
        ->toContain('data-test="busy-but-lower"')
        ->toContain('ownerChart(');

    $chart = Livewire::actingAs($owner)->test('pages::company.owner', ['company' => $company])->instance()->chartData();
    expect($chart['labels'][0])->toBe('8月')
        ->and($chart['busy'])->toBe([false, false, true, true, true, false, false, false, false, false, true, true])
        ->and(array_column($chart['datasets'], 'color'))->toBe(['brand', 'ink', 'muted']);

    $company->forceFill(['busy_months' => [8]])->save();

    expect($this->actingAs($owner)->get(route('company.owner', $company))->getContent())
        ->toContain('data-month="8" data-busy="1"')
        ->toContain('data-month="10" data-busy="0"')
        ->not->toContain('data-test="busy-but-lower"');
});

test('今期の経費は確定した支出だけ。経費のない月は「—」、売上 − 経費を出す', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    putSales($company, '2026-09', 2_000_000);
    putSales($company, '2026-08', 1_000_000);
    putEntry($company, '2026-09-05', '燃料', 150_000);
    putEntry($company, '2026-09-20', '消耗品', 50_000);
    putEntry($company, '2026-09-21', '燃料', 70_000, EntryStatus::Draft);
    putEntry($company, '2026-09-22', '売上', 900_000);
    putEntry($company, '2026-07-31', '燃料', 99_000);

    $dashboard = app(OwnerDashboard::class)->build($company);

    expect($dashboard['months'][1])->toMatchArray(['month' => 9, 'expense' => 200_000, 'left' => 1_800_000])
        ->and($dashboard['months'][0])->toMatchArray(['month' => 8, 'expense' => null, 'left' => null])
        ->and($dashboard['expense_by_category'])->toBe([
            ['name' => '燃料', 'amount_yen' => 150_000],
            ['name' => '消耗品', 'amount_yen' => 50_000],
        ]);

    $this->actingAs($owner)->get(route('company.owner', $company))
        ->assertSee('1,800,000円')
        ->assertSeeInOrder(['8月', '1,000,000円', '—', '—']);
});

test('気になる仕訳は、印があり社長がまだ見ていない確定した仕訳だけ。他社のものは出ない', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    ['company' => $other] = createCompanyWithMember(Role::Owner);
    putEntry($company, '2026-10-01', '部品・修理', 120_000, flags: ['amount'], vendor: '△△機械');
    putEntry($company, '2026-10-02', '燃料', 3_000, flags: []);
    putEntry($company, '2026-10-03', '燃料', 4_000);
    putEntry($company, '2026-10-04', '燃料', 5_000, EntryStatus::Draft, flags: ['amount_missing'], vendor: '下書きの店');
    $seen = putEntry($company, '2026-10-05', '交際', 50_000, flags: ['amount'], vendor: '見た店');
    $seen->forceFill(['owner_seen_at' => now()])->save();
    putEntry($other, '2026-10-06', '燃料', 90_000, flags: ['amount'], vendor: '他社の店');

    $this->actingAs($owner)->get(route('company.owner', $company))
        ->assertSee('気になる仕訳（1件）')
        ->assertSee('△△機械')
        ->assertSee('120,000円')
        ->assertSee('金額が大きい')
        ->assertDontSee('見た店')
        ->assertDontSee('下書きの店')
        ->assertDontSee('他社の店');
});

test('T17 社長の画面に入れるのは社長・管理者だけ。経理・承認者・閲覧・送るだけは403、他社は404', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    ['user' => $admin] = createCompanyWithMember(Role::Admin, company: $company);
    ['company' => $other] = createCompanyWithMember(Role::Owner);

    $this->actingAs($owner)->get(route('company.owner', $company))->assertOk();
    $this->actingAs($admin)->get(route('company.owner', $company))->assertOk();
    $this->actingAs($owner)->get(route('company.owner', $other))->assertNotFound();

    foreach ([Role::Editor, Role::Approver, Role::Viewer] as $role) {
        ['user' => $user] = createCompanyWithMember($role, company: $company);
        $this->actingAs($user)->get(route('company.owner', $company))->assertForbidden();
        $this->actingAs($user)->get(route('company.home', $company))->assertDontSee('data-test="owner-link"', false);
    }

    ['user' => $submitter] = createCompanyWithMember(Role::Editor, submitOnly: true, company: $company);
    $this->actingAs($submitter)->get(route('company.owner', $company))->assertForbidden();

    $this->actingAs($owner)->get(route('company.home', $company))->assertSee('data-test="owner-link"', false);
});

test('ライブ画面のキー5と「5 社長の画面」は社長の画面に行く', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    $ownerUrl = route('company.owner', $company);

    $this->actingAs($owner)->get(route('company.live', $company))
        ->assertSee("else if (\$event.key === '5') { window.location = '{$ownerUrl}' }", false)
        ->assertSee('href="'.$ownerUrl.'" class="text-ink underline" data-test="live-owner-link"', false);
});

test('売上の数字がまだないときは、グラフの代わりに「数字がまだありません」と出す', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);

    $this->actingAs($owner)->get(route('company.owner', $company))
        ->assertOk()
        ->assertSee('数字がまだありません')
        ->assertSee('data-test="owner-chart-empty"', false)
        ->assertDontSee('data-test="owner-chart"', false)
        ->assertDontSee('data-busy=', false);

    putSales($company, '2024-08', 2_800_000);

    $this->actingAs($owner)->get(route('company.owner', $company))
        ->assertDontSee('data-test="owner-chart-empty"', false)
        ->assertSee('data-test="owner-chart"', false);
});

test('torudake:seed-demo はデモ用の会社にだけ、秋が多く前期の7月だけ前年より少ない架空の数字を入れる。2回流しても増えない', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    ['company' => $real] = createCompanyWithMember(Role::Owner);
    $company->forceFill(['is_demo' => true])->save();

    $this->artisan('torudake:seed-demo', ['company' => $real->id])->assertFailed();
    expect($real->externalMonthlyFigures()->count())->toBe(0);

    $this->artisan('torudake:seed-demo', ['company' => $company->id])->assertSuccessful();
    $counts = [$company->externalMonthlyFigures()->count(), $company->externalCustomerSales()->count(), $company->journalEntries()->count()];
    $this->artisan('torudake:seed-demo', ['company' => $company->id])->assertSuccessful();

    expect([$company->externalMonthlyFigures()->count(), $company->externalCustomerSales()->count(), $company->journalEntries()->count()])->toBe($counts)
        ->and($counts[0])->toBe(26);

    $sales = $company->externalMonthlyFigures()->pluck('amount_yen', 'year_month');
    expect($sales['2025-11'])->toBeGreaterThan($sales['2025-08'])
        ->and($sales['2026-02'])->toBeLessThan($sales['2025-08'])
        ->and($sales['2026-07'])->toBeLessThan($sales['2025-07'])
        ->and($sales['2026-06'])->toBeGreaterThan($sales['2025-06']);

    $dashboard = app(OwnerDashboard::class)->build($company);
    $top = $dashboard['customers']['top'] ?? [];
    expect($top[0]['share_pct'] + $top[1]['share_pct'])->toBeGreaterThanOrEqual(75)
        ->and(collect($dashboard['months'])->firstWhere('month', 7)['busy_but_lower'] ?? null)->toBeTrue()
        ->and($dashboard['months'][1]['expense'])->toBeGreaterThan(0);

    $this->actingAs($owner)->get(route('company.owner', $company))->assertOk()->assertSee('忙しい月なのに前年より少ない');
});
