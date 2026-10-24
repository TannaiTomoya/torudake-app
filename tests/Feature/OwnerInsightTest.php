<?php

use App\Enums\AiTask;
use App\Enums\InsightKind;
use App\Enums\Role;
use App\Jobs\GenerateOwnerInsight;
use App\Models\Company;
use App\Models\OwnerInsight;
use App\Services\Ai\AiRequest;
use App\Services\Ai\FakeClaudeClient;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => $this->travelTo(now()->setDate(2026, 10, 24)->setTime(9, 0)));

/**
 * @return list<AiRequest>
 */
function insightRequests(AiTask $task): array
{
    return array_values(array_filter(app(FakeClaudeClient::class)->requests, fn (AiRequest $r): bool => $r->task === $task));
}

/**
 * 忙しい7月が前年より少なく、8月は前年より多い架空の数字。
 */
function putDemoSales(Company $company): void
{
    putSales($company, '2026-08', 3_600_000);
    putSales($company, '2025-08', 3_000_000);
    putSales($company, '2024-08', 2_800_000);
    putSales($company, '2026-07', 3_000_000);
    putSales($company, '2025-07', 3_500_000);
    putSales($company, '2025-11', 5_000_000);
    putSales($company, '2024-11', 4_600_000);
    putCustomer($company, 1, 'A社', 8_000_000);
    putCustomer($company, 2, 'B社', 2_000_000);
}

test('T26 同じ入力ならAIを呼ばない。数字や確定した仕訳が変わったら作り直す', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    putDemoSales($company);

    $this->actingAs($owner)->get(route('company.owner', $company))->assertOk();
    $this->actingAs($owner)->get(route('company.owner', $company))->assertOk();

    expect(insightRequests(AiTask::OwnerBrief))->toHaveCount(1)
        ->and(insightRequests(AiTask::Forecast))->toHaveCount(1);

    putSales($company, '2026-09', 2_000_000);
    $this->actingAs($owner)->get(route('company.owner', $company));

    expect(insightRequests(AiTask::OwnerBrief))->toHaveCount(2)
        ->and(insightRequests(AiTask::Forecast))->toHaveCount(2);

    putEntry($company, '2026-09-10', '燃料', 150_000);
    $this->actingAs($owner)->get(route('company.owner', $company));
    $this->actingAs($owner)->get(route('company.owner', $company));

    expect(insightRequests(AiTask::OwnerBrief))->toHaveCount(3)
        ->and(OwnerInsight::query()->where('company_id', $company->id)->count())->toBe(2);
});

test('T26 ひとこと・見込みには、忙しい月の印・3期の平均・「忙しい月なのに前年より少ない」を渡し、名前は渡さない', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    putDemoSales($company);
    putEntry($company, '2026-10-01', '部品・修理', 120_000, flags: ['amount'], vendor: '△△機械');

    $this->actingAs($owner)->get(route('company.owner', $company))
        ->assertSee('今月のひとこと（AI）')
        ->assertSee('7月は忙しい月なのに、前年同月より50万円少なかった')
        ->assertSee('売上の80%を占めるA社の、今後の工事の予定を確かめるとよい')
        ->assertSee('11月の売上')
        ->assertSee('だいたい');

    $brief = insightRequests(AiTask::OwnerBrief)[0]->input;
    $july = collect($brief['months'])->firstWhere('month', 7);
    $november = collect($brief['months'])->firstWhere('month', 11);

    expect($july)->toMatchArray(['busy' => true, 'busy_but_lower' => true, 'yoy_diff' => -500_000, 'average' => 3_250_000])
        ->and($november)->toMatchArray(['busy' => true, 'average' => 4_800_000])
        ->and(collect($brief['months'])->firstWhere('month', 8)['busy'])->toBeFalse()
        ->and($brief['concern_count'])->toBe(1)
        ->and(json_encode($brief, JSON_UNESCAPED_UNICODE))->not->toContain('△△機械')->not->toContain('テスト建機');

    $forecast = insightRequests(AiTask::Forecast)[0]->input;
    expect($forecast)->toMatchArray([
        'target_month' => 11,
        'target_busy' => true,
        'target_average' => 4_800_000,
        'target_history' => ['2024-11' => 4_600_000, '2025-11' => 5_000_000],
        'growth_pct' => 20,
    ])->and($forecast['monthly_sales'])->toHaveKey('2026-08', 3_600_000);
});

test('作り直しを頼んだ間は前回のひとことに「更新中」と出し、同じ入力では頼み直さない。5分たったら頼み直す', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    putDemoSales($company);
    $this->actingAs($owner)->get(route('company.owner', $company))->assertDontSee('data-test="owner-brief-updating"', false);

    Queue::fake();
    putSales($company, '2026-09', 2_000_000);

    $this->actingAs($owner)->get(route('company.owner', $company))
        ->assertSee('data-test="owner-brief-updating"', false)
        ->assertSee('今期1か月の売上は、前期の同じ月より60万円（20%）多い')
        ->assertSee('wire:poll.2s', false);
    $this->actingAs($owner)->get(route('company.owner', $company));

    Queue::assertPushed(GenerateOwnerInsight::class, 2);

    $this->travel(6)->minutes();
    $this->actingAs($owner)->get(route('company.owner', $company));

    Queue::assertPushed(GenerateOwnerInsight::class, 4);
});

test('AIが失敗したら前回のものを残し、5分は開くたびに呼び直さない', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    putDemoSales($company);
    $this->actingAs($owner)->get(route('company.owner', $company));

    app(FakeClaudeClient::class)->failWith(AiTask::OwnerBrief);
    putSales($company, '2026-09', 2_000_000);

    $this->actingAs($owner)->get(route('company.owner', $company))
        ->assertSee('最新の数字では作り直せませんでした（前回のひとことです）')
        ->assertSee('今期1か月の売上は、前期の同じ月より60万円（20%）多い');
    $this->actingAs($owner)->get(route('company.owner', $company));

    expect(insightRequests(AiTask::OwnerBrief))->toHaveCount(2);

    $insight = $company->ownerInsights()->where('kind', InsightKind::Brief)->sole();
    expect($insight->pending_hash)->toBeNull()
        ->and($insight->error)->not->toBeNull();
});

test('来月の経費は、確定した経費が2か月分ないうちは「まだ出せません」', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    putDemoSales($company);
    putEntry($company, '2026-09-10', '燃料', 150_000);

    $this->actingAs($owner)->get(route('company.owner', $company))
        ->assertSeeInOrder(['11月の経費', '数字が足りないため、まだ出せません']);

    putEntry($company, '2026-10-10', '燃料', 170_000);

    $this->actingAs($owner)->get(route('company.owner', $company))
        ->assertSeeInOrder(['11月の経費', 'だいたい 16万円', '8割の確からしさで 14万円〜18万円']);
});
