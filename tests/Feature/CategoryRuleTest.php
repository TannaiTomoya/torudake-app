<?php

use App\Enums\CorrectionReason;
use App\Enums\Role;
use App\Models\AiCorrection;
use App\Models\AuditLog;
use App\Models\CategoryRule;
use App\Models\Company;
use App\Models\User;
use App\Services\Ai\FakeClaudeClient;
use App\Services\CategoryRuleBoard;
use App\Services\CategoryRuleService;
use App\Support\CategoryRuleMatcher;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

// #69：分類のルール。店名・キーワードはすべて架空

const RULE_VENDOR = 'ホームセンター架空店';
const RULE_KEYWORD = 'ホームセンター';
const RULE_KEYWORD_BROAD = 'ホーム';
const RULE_CATEGORY_TOP = '部品・修理';
const RULE_CATEGORY_BROAD = '消耗品';

beforeEach(fn () => $this->travelTo(now()->setDate(2026, 11, 21)->setTime(9, 0)));

function rulePage(Company $company, User $user)
{
    return Livewire::actingAs($user)->test('pages::company.category-rules', ['company' => $company]);
}

function ruleAdd(Company $company, User $user, string $keyword, string $category = RULE_CATEGORY_BROAD): CategoryRule
{
    return app(CategoryRuleService::class)->add($company, $user, $keyword, categoryId($company, $category));
}

test('T60 分類ルール（規則）：Python（tools/analysis）と共通の架空ベクトルで同じ答えになる', function (): void {
    $vectors = json_decode((string) file_get_contents(base_path('tests/fixtures/category_rule_vectors.json')), true, 512, JSON_THROW_ON_ERROR);

    foreach ($vectors['cases'] as $case) {
        expect(CategoryRuleMatcher::match($case['vendor'], $case['rules']))->toBe($case['expected'], $case['name']);
        expect(CategoryRuleMatcher::match($case['vendor'], $case['rules']))->toBe($case['expected'], $case['name'].'（2回目）');
    }
});

test('T60 規則：正しくない入力は判定しない', function (callable $call): void {
    expect($call)->toThrow(InvalidArgumentException::class);
})->with([
    '店名が配列' => [fn () => CategoryRuleMatcher::match(['x'], [['keyword' => 'a', 'category_id' => 'c']])],
    'ルールが組でない' => [fn () => CategoryRuleMatcher::match('店', ['x'])],
    'キーワード空' => [fn () => CategoryRuleMatcher::match('店', [['keyword' => '  ', 'category_id' => 'c']])],
    '分類ID空' => [fn () => CategoryRuleMatcher::match('店', [['keyword' => '店', 'category_id' => '']])],
]);

test('T60 サービス：新規は一番上。上の例外が下の大まかなルールより先に当たる', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);

    $broad = ruleAdd($company, $owner, RULE_KEYWORD_BROAD, RULE_CATEGORY_BROAD);
    $top = ruleAdd($company, $owner, RULE_KEYWORD, RULE_CATEGORY_TOP);

    expect($top->fresh()->position)->toBe(0)
        ->and($broad->fresh()->position)->toBe(1);

    $match = app(CategoryRuleBoard::class)->matchVendor($company, RULE_VENDOR);
    expect($match['keyword'])->toBe(RULE_KEYWORD)
        ->and($match['category_id'])->toBe(categoryId($company, RULE_CATEGORY_TOP))
        ->and(array_column($match['other'], 'keyword'))->toBe([RULE_KEYWORD_BROAD]);
});

test('T60 サービス：足す・直す・消す・並べ替えは監査に前後が残る。同じ内容の直すは監査を増やさない', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    $rule = ruleAdd($company, $owner, RULE_KEYWORD);
    expect(AuditLog::query()->where('action', 'category_rule.create')->count())->toBe(1);

    app(CategoryRuleService::class)->update($company, $owner, $rule->id, RULE_KEYWORD, categoryId($company, RULE_CATEGORY_BROAD));
    expect(AuditLog::query()->where('action', 'category_rule.update')->count())->toBe(0);

    app(CategoryRuleService::class)->update($company, $owner, $rule->id, RULE_KEYWORD, categoryId($company, RULE_CATEGORY_TOP));
    expect(AuditLog::query()->where('action', 'category_rule.update')->count())->toBe(1);

    $other = ruleAdd($company, $owner, RULE_KEYWORD_BROAD);
    app(CategoryRuleService::class)->moveDown($company, $owner, $other->id);
    expect(AuditLog::query()->where('action', 'category_rule.reorder')->count())->toBe(1);

    app(CategoryRuleService::class)->remove($company, $owner, $rule->id);
    expect(AuditLog::query()->where('action', 'category_rule.delete')->count())->toBe(1)
        ->and($company->categoryRules()->whereKey($rule->id)->exists())->toBeFalse();
});

test('T60 下書き：ルールに当たれば規則の分類。既存の下書きはルールを足しても変わらない', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);

    $source = sendReceipt($company, $editor, 410);
    $entry = $source->journalEntry;
    expect($entry)->not->toBeNull();
    $beforeCategory = $entry->category_id;
    $beforeOrigin = $entry->draft_category_origin;

    ruleAdd($company, $editor, '石油', RULE_CATEGORY_TOP);

    expect($entry->fresh()->category_id)->toBe($beforeCategory)
        ->and($entry->fresh()->draft_category_origin)->toBe($beforeOrigin);
});

test('T60 下書き：ルール適用・ほかに当たったルール・過去との食い違いが画面に出る', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);

    foreach ([1, 2] as $days) {
        $past = putEntry($company, now()->subDays($days)->toDateString(), RULE_CATEGORY_BROAD, 3_000, vendor: RULE_VENDOR);
        $past->forceFill([
            'bank_account_id' => bankAccountId($company, '現金'),
            'confirmed_at' => now()->subDays($days),
        ])->save();
    }

    ruleAdd($company, $editor, RULE_KEYWORD_BROAD, RULE_CATEGORY_BROAD);
    ruleAdd($company, $editor, RULE_KEYWORD, RULE_CATEGORY_TOP);

    $fake = app(FakeClaudeClient::class);
    $fake->withReading([
        'date' => '2026-11-20',
        'vendor' => RULE_VENDOR,
        'total_yen' => 5_200,
        'payment_method' => 'cash',
    ]);
    $fake->withJournalize([
        'vendor' => RULE_VENDOR,
        'category' => RULE_CATEGORY_BROAD,
        'bank_account' => '現金',
        'description' => '架空の備品',
    ]);

    $source = sendReceipt($company, $editor, 420);
    $fake->withReading([]);
    $fake->withJournalize([]);

    $entry = $source->fresh()->journalEntry;
    expect($entry->draft_category_origin)->toBe('rule')
        ->and($entry->category_id)->toBe(categoryId($company, RULE_CATEGORY_TOP))
        ->and($entry->ai_check['category_rule'] ?? null)->toBe(RULE_KEYWORD)
        ->and($entry->ai_check['other_matched_rules'] ?? null)->toBe([RULE_KEYWORD_BROAD])
        ->and($entry->ai_check['category_history_differs'] ?? null)->toBe(RULE_CATEGORY_BROAD);

    $this->actingAs($editor)->get(route('company.sources.show', [$company, $source]))
        ->assertOk()
        ->assertSee('分類のルール「'.RULE_KEYWORD.'」から入れました')
        ->assertSee('過去は'.RULE_CATEGORY_BROAD.'で保存しています')
        ->assertSee('ほかにも当たったルール：'.RULE_KEYWORD_BROAD);
});

test('T60 権限：開けるのは owner・editor だけ', function (Role $role, bool $submitOnly, int $status): void {
    ['company' => $company] = createCompanyWithMember(Role::Owner);
    ['user' => $user] = createCompanyWithMember($role, submitOnly: $submitOnly, company: $company);

    $this->actingAs($user)->get(route('company.category-rules', $company))->assertStatus($status);
    expect(Gate::forUser($user)->allows('manageCategoryRules', $company))->toBe($status === 200);
})->with([
    'owner' => [Role::Owner, false, 200],
    'editor' => [Role::Editor, false, 200],
    'admin' => [Role::Admin, false, 403],
    'approver' => [Role::Approver, false, 403],
    'viewer' => [Role::Viewer, false, 403],
    '送るだけ' => [Role::Editor, true, 403],
]);

test('T60 権限：開いたあとで役割が変わったら保存は403', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    $page = rulePage($company, $owner)
        ->assertOk()
        ->set('keyword', 'テスト')
        ->set('categoryId', categoryId($company, RULE_CATEGORY_BROAD));

    $company->memberships()->where('user_id', $owner->id)->sole()->forceFill(['role' => Role::Admin])->save();

    $page->call('save')->assertForbidden();
    expect($company->categoryRules()->count())->toBe(0);
});

test('T60 画面：足すと一番上。並べ替えできる。サイドバーは開ける人だけ', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    ['user' => $admin] = createCompanyWithMember(Role::Admin, company: $company);

    rulePage($company, $owner)
        ->set('keyword', RULE_KEYWORD_BROAD)
        ->set('categoryId', categoryId($company, RULE_CATEGORY_BROAD))
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('一番上に足しました');

    rulePage($company, $owner)
        ->set('keyword', RULE_KEYWORD)
        ->set('categoryId', categoryId($company, RULE_CATEGORY_TOP))
        ->call('save')
        ->assertHasNoErrors();

    expect($company->categoryRules()->orderBy('position')->pluck('keyword')->all())
        ->toBe([RULE_KEYWORD, RULE_KEYWORD_BROAD]);

    $topId = (string) $company->categoryRules()->where('keyword', RULE_KEYWORD)->value('id');
    rulePage($company, $owner)->call('moveDown', $topId)->assertHasNoErrors();
    expect($company->categoryRules()->orderBy('position')->pluck('keyword')->all())
        ->toBe([RULE_KEYWORD_BROAD, RULE_KEYWORD]);

    $this->actingAs($owner)->get(route('company.home', $company))->assertSee('data-test="sidebar-category-rules"', false);
    $this->actingAs($admin)->get(route('company.home', $company))->assertDontSee('data-test="sidebar-category-rules"', false);
});

test('T60 候補から採用できる。一括代入では入れられない。他社のルールは触れない', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    ['company' => $other, 'user' => $otherOwner] = createCompanyWithMember(Role::Owner);

    $correction = new AiCorrection;
    $correction->forceFill([
        'company_id' => $company->id,
        'field' => 'category',
        'before' => '雑費',
        'after' => RULE_CATEGORY_BROAD,
        'reason' => CorrectionReason::WrongCategory,
        'vendor' => '北上サンプル商事',
        'corrected_by' => $owner->id,
    ])->save();

    $candidates = app(CategoryRuleBoard::class)->candidates($company);
    expect($candidates)->not->toBeEmpty()
        ->and($candidates[0]['keyword'])->toBe('北上サンプル商事');

    rulePage($company, $owner)->call('adoptCandidate', 0)->assertHasNoErrors()
        ->assertSee('候補をルールとして一番上に足しました');

    expect(fn () => (new CategoryRule)->fill(['company_id' => 'x', 'keyword' => 'a']))
        ->toThrow(MassAssignmentException::class);

    $otherRule = ruleAdd($other, $otherOwner, '他社キーワード');
    app(CategoryRuleService::class)->remove($company, $owner, $otherRule->id);
    expect($other->categoryRules()->whereKey($otherRule->id)->exists())->toBeTrue();

    expect(fn () => app(CategoryRuleService::class)->update($company, $owner, $otherRule->id, 'x', categoryId($company, RULE_CATEGORY_BROAD)))
        ->toThrow(ModelNotFoundException::class);
});
