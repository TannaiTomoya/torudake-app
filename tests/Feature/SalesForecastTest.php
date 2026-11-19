<?php

use App\Enums\Role;
use App\Services\OwnerDashboard;
use App\Support\SalesForecastRule;

beforeEach(fn () => $this->travelTo(now()->setDate(2026, 10, 24)->setTime(9, 0)));

test('T53 来月の売上の見込み（規則）：Python（tools/analysis）と共通の架空ベクトルで同じ答えになる', function (): void {
    $vectors = json_decode((string) file_get_contents(base_path('tests/fixtures/forecast_vectors.json')), true, 512, JSON_THROW_ON_ERROR);
    expect($vectors['cases'])->not->toBeEmpty();

    foreach ($vectors['cases'] as $case) {
        expect(SalesForecastRule::compute($case['target_year_month'], $case['sales'], $case['busy']))->toBe($case['expected'], $case['name']);
    }
});

test('T53 見込みの規則：月の形が違えば計算しない', function (string $month): void {
    expect(fn () => SalesForecastRule::compute($month, [], false))->toThrow(InvalidArgumentException::class);
})->with(['2026-13', '2026-1', '']);

test('T53 社長の画面：来月と同じ月の過去3期から幅と真ん中を出し、欠けた年は数えず、AI は参考として並べる', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    $company->forceFill(['busy_months' => [11]])->save();
    putSales($company, '2025-11', 5_000_000);
    putSales($company, '2024-11', 4_600_000);
    putSales($company, '2022-11', 9_000_000);
    putSales($company, '2026-10', 7_000_000);

    $forecast = app(OwnerDashboard::class)->build($company)['sales_forecast'];
    expect($forecast['target_year_month'])->toBe('2026-11')
        ->and($forecast['known_years'])->toBe(2)
        ->and($forecast['low_yen'])->toBe(4_600_000)
        ->and($forecast['high_yen'])->toBe(5_000_000)
        ->and($forecast['middle_yen'])->toBe(4_800_000)
        ->and($forecast['busy'])->toBeTrue();

    $this->actingAs($owner)->get(route('company.owner', $company))
        ->assertOk()
        ->assertSeeInOrder(['11月の売上の見込み（過去3期の同じ月から）', '忙しい月', '460万円〜500万円', '真ん中 480万円（2期の平均）', '2期分しかないため目安です', 'AIの参考：'])
        ->assertDontSee('8割の確からしさ');
});

test('T53 社長の画面：3期そろえば真ん中の年を出し、同じ月の売上がなければそう出す', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);

    $this->actingAs($owner)->get(route('company.owner', $company))
        ->assertSee('過去の同じ月の売上がまだありません');

    putSales($company, '2023-11', 4_000_000);
    putSales($company, '2024-11', 4_600_000);
    putSales($company, '2025-11', 5_000_000);

    $this->actingAs($owner)->get(route('company.owner', $company))
        ->assertSee('400万円〜500万円')
        ->assertSee('真ん中 460万円（2024年11月）')
        ->assertSee('3期分しかないため目安です');
});

test('T53 他社の売上は見込みに入らない', function (): void {
    ['company' => $company] = createCompanyWithMember(Role::Owner);
    ['company' => $other] = createCompanyWithMember(Role::Owner);
    putSales($other, '2025-11', 9_999_999);

    expect(app(OwnerDashboard::class)->build($company)['sales_forecast']['known_years'])->toBe(0);
});
