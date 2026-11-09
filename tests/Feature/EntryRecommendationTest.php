<?php

use App\Enums\AiTask;
use App\Enums\EntryStatus;
use App\Enums\Role;
use App\Models\AiRun;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\SourceRecord;
use App\Models\User;
use App\Services\Ai\FakeClaudeClient;
use App\Services\EntryRecommender;
use App\Support\EntryRecommendation;
use App\Support\FirstConfirmationMetrics;
use App\Support\VendorKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('evidence');
});

const SHOP = '〇〇石油 八幡平店';

/**
 * 同じ店の確定した履歴を1件作る（分類・支払い元・何日前に確定したか）。
 */
function history(Company $company, string $bank = '社長の立替', string $category = '消耗品', int $daysAgo = 1, string $vendor = SHOP, EntryStatus $status = EntryStatus::Confirmed): JournalEntry
{
    $entry = putEntry($company, now()->subDays($daysAgo)->toDateString(), $category, 3_000, $status, vendor: $vendor);
    $entry->forceFill([
        'bank_account_id' => bankAccountId($company, $bank),
        'confirmed_at' => $status === EntryStatus::Confirmed ? now()->subDays($daysAgo) : $entry->confirmed_at,
    ])->save();

    return $entry->refresh();
}

/** 実際のAI（claude）で作った下書きとして扱う（指標は fake・デモと混ぜないため） */
function realSend(Company $company, User $user, int $width): SourceRecord
{
    $source = sendReceipt($company, $user, $width);
    AiRun::query()->where('subject_id', $source->id)->update(['driver' => 'claude']);

    return $source->refresh();
}

function recommend(Company $company, ?string $vendor = SHOP, ?string $paymentMethod = null): EntryRecommendation
{
    return app(EntryRecommender::class)->for($company, $vendor, $paymentMethod);
}

test('店名の正規化：全角・半角・空白・大文字小文字の違いは同じ店。仕訳の保存のたびに vendor_key が入る', function (): void {
    ['company' => $company] = createCompanyWithMember(Role::Editor);

    $entry = history($company, vendor: 'ＡＢＣ　商店 ');

    expect(VendorKey::of('ＡＢＣ　商店 '))->toBe('abc商店')
        ->and(VendorKey::of('abc商店'))->toBe('abc商店')
        ->and(VendorKey::of('  '))->toBeNull()
        ->and($entry->vendor_key)->toBe('abc商店');
});

test('履歴が0件・1件・複数件：0件は候補なし（失敗ではない）、1件は参考だけ、2件以上で一つにそろえば当てる', function (): void {
    ['company' => $company] = createCompanyWithMember(Role::Editor);

    expect(recommend($company)->status)->toBe('none')
        ->and(recommend($company, null)->reasons)->toBe(['no_vendor']);

    history($company);
    $one = recommend($company);
    expect($one->status)->toBe('hold')
        ->and($one->reasons)->toBe(['few_history'])
        ->and($one->applicable())->toBeNull()
        ->and($one->top()['bank_account_name'])->toBe('社長の立替')
        ->and($one->top()['count'])->toBe(1);

    history($company, daysAgo: 2);
    history($company, daysAgo: 3);
    $many = recommend($company);
    expect($many->status)->toBe('confident')
        ->and($many->reasons)->toBe([])
        ->and($many->applicable()['category_name'])->toBe('消耗品')
        ->and($many->applicable()['count'])->toBe(3)
        ->and($many->usableCount)->toBe(3);
});

test('100件より前の同じ店の履歴も使う（店名で先に絞る）', function (): void {
    ['company' => $company] = createCompanyWithMember(Role::Editor);
    history($company, daysAgo: 150);
    history($company, daysAgo: 151);

    foreach (range(1, 105) as $i) {
        history($company, bank: '現金', daysAgo: 1, vendor: "別の店{$i}");
    }

    $result = recommend($company);

    expect($result->status)->toBe('confident')
        ->and($result->top()['bank_account_name'])->toBe('社長の立替')
        ->and($result->top()['count'])->toBe(2);
});

test('同じ店で現金と立替が混在するときは、一つに決めず複数候補で保留。多数派が8割以上で直近もそうなら一つにそろう', function (): void {
    ['company' => $company] = createCompanyWithMember(Role::Editor);
    history($company, bank: '社長の立替', daysAgo: 4);
    history($company, bank: '社長の立替', daysAgo: 5);
    history($company, bank: '現金', daysAgo: 1);
    history($company, bank: '現金', daysAgo: 2);

    $mixed = recommend($company);
    expect($mixed->status)->toBe('hold')
        ->and($mixed->reasons)->toContain('mixed_usage')
        ->and($mixed->applicable())->toBeNull()
        ->and(array_column($mixed->candidates, 'bank_account_name'))->toEqualCanonicalizing(['社長の立替', '現金']);

    ['company' => $dominant] = createCompanyWithMember(Role::Editor);
    foreach (range(1, 9) as $i) {
        history($dominant, bank: '社長の立替', daysAgo: $i + 1);
    }
    history($dominant, bank: '現金', daysAgo: 20);
    expect(recommend($dominant)->status)->toBe('confident');

    // 直近の確認が多数派と違えば、使い方が変わりつつあるので保留
    history($dominant, bank: '現金', daysAgo: 0);
    expect(recommend($dominant)->status)->toBe('hold')
        ->and(recommend($dominant)->reasons)->toContain('mixed_usage');
});

test('古い履歴・分類の廃止・戻し中の履歴・他社だけの履歴は、当てずに保留か候補なしにする', function (): void {
    ['company' => $company] = createCompanyWithMember(Role::Editor);
    history($company, daysAgo: 200);
    history($company, daysAgo: 201);
    expect(recommend($company)->reasons)->toBe(['stale'])
        ->and(recommend($company)->applicable())->toBeNull();

    ['company' => $retired] = createCompanyWithMember(Role::Editor);
    history($retired);
    history($retired, daysAgo: 2);
    $retired->categories()->where('name', '消耗品')->firstOrFail()->forceFill(['archived_at' => now()])->save();
    expect(recommend($retired)->status)->toBe('none')
        ->and(recommend($retired)->reasons)->toBe(['retired']);

    ['company' => $returned] = createCompanyWithMember(Role::Editor);
    history($returned);
    history($returned, daysAgo: 2);
    history($returned, daysAgo: 3, status: EntryStatus::Returned);
    expect(recommend($returned)->status)->toBe('hold')
        ->and(recommend($returned)->reasons)->toBe(['returned_in_history']);

    ['company' => $mine] = createCompanyWithMember(Role::Editor);
    ['company' => $theirs] = createCompanyWithMember(Role::Editor);
    history($theirs);
    history($theirs, daysAgo: 2);
    history($theirs, daysAgo: 3);
    expect(recommend($mine)->status)->toBe('none')
        ->and(recommend($theirs)->status)->toBe('confident');
});

test('分類の改名は ID で結び付いたまま新しい名前で出る。同じ名前の別の分類には結び付かない', function (): void {
    ['company' => $company] = createCompanyWithMember(Role::Editor);
    history($company);
    history($company, daysAgo: 2);

    $category = $company->categories()->where('name', '消耗品')->firstOrFail();
    $category->forceFill(['name' => '消耗品（改）'])->save();

    expect(recommend($company)->top()['category_name'])->toBe('消耗品（改）')
        ->and(recommend($company)->top()['category_id'])->toBe($category->id);
});

test('後日請求のレシートに、支払い済みの履歴は当てない（保留）。AIの下書きは請求の規則を使う', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    foreach ([1, 2, 3] as $day) {
        history($company, bank: '現金', daysAgo: $day);
    }

    expect(recommend($company)->status)->toBe('confident')
        ->and(recommend($company, SHOP, 'invoice_later')->status)->toBe('hold')
        ->and(recommend($company, SHOP, 'invoice_later')->reasons)->toBe(['payment_conflict']);

    app(FakeClaudeClient::class)->withReading(['payment_method' => 'invoice_later']);
    $entry = sendReceipt($company, $editor)->journalEntry()->firstOrFail();

    expect($entry->draft_payment_origin)->toBe('invoice_rule')
        ->and($entry->recommendation['reasons'] ?? null)->toBe(['payment_conflict'])
        ->and($entry->recommendation['applied'] ?? null)->toBeFalse();
});

test('下書きは、十分な履歴があれば支払い元に当て、元のAIの案・履歴の候補・当てたかを分けて残す', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    history($company);
    history($company, daysAgo: 2);

    $entry = sendReceipt($company, $editor)->journalEntry()->with('bankAccount')->firstOrFail();

    expect($entry->bankAccount?->name)->toBe('社長の立替')
        ->and($entry->draft_payment_origin)->toBe('history')
        ->and($entry->status)->toBe(EntryStatus::Draft)
        ->and($entry->recommendation)->toMatchArray(['status' => 'confident', 'applied' => true])
        ->and($entry->recommendation['l2']['bank_account_id'])->toBe(bankAccountId($company, '現金'))
        ->and($entry->recommendation['candidates'][0]['bank_account_id'])->toBe(bankAccountId($company, '社長の立替'));
});

test('候補の「今回も使う」は欄に入れるだけで確定にならない。保存の扱い（採用・別を選んだ・保留）が初回確定の記録に残る', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    history($company);

    $source = realSend($company, $editor, 400);
    $component = Livewire::actingAs($editor)
        ->test('pages::company.sources.show', ['company' => $company, 'source' => $source->id])
        ->assertSee('data-test="recommendation"', false)
        ->assertSee('分類「消耗品」・支払い元「社長の立替」')
        ->assertSee('決めきれません：同じ店の確認が1件しかなく')
        ->assertSet('bankAccountId', bankAccountId($company, '現金'))
        ->call('useRecommendation', 0)
        ->assertSet('bankAccountId', bankAccountId($company, '社長の立替'));

    expect($source->refresh()->journalEntry()->firstOrFail()->status)->toBe(EntryStatus::Draft);

    $component->set('reason', 'wrong_payment')->call('confirm')->assertHasNoErrors();
    $adopted = $company->firstConfirmations()->sole();
    expect($adopted->recommendation)->toMatchArray(['outcome' => 'adopted', 'status' => 'hold', 'expired' => false, 'candidate_count' => 1]);

    // 別を選んだ
    $second = realSend($company, $editor, 410);
    Livewire::actingAs($editor)
        ->test('pages::company.sources.show', ['company' => $company, 'source' => $second->id])
        ->set('bankAccountId', bankAccountId($company, '事業用カード'))
        ->set('reason', 'wrong_payment')
        ->call('confirm')->assertHasNoErrors();

    // 保留にして保存
    $third = realSend($company, $editor, 420);
    Livewire::actingAs($editor)
        ->test('pages::company.sources.show', ['company' => $company, 'source' => $third->id])
        ->call('holdRecommendation')
        ->assertSee('候補は保留にしています')
        ->call('confirm')->assertHasNoErrors();

    $outcomes = $company->firstConfirmations()->orderBy('first_confirmed_at')->orderBy('id')->get()->pluck('recommendation.outcome')->all();
    expect($outcomes)->toContain('adopted', 'rejected', 'held');

    $rec = FirstConfirmationMetrics::for($company, CarbonImmutable::instance(now()))['recommendation'];
    expect($rec['adopted'] + $rec['rejected'] + $rec['held'])->toBe($rec['with_candidate'])
        ->and($rec['rejected'])->toBe(1)
        ->and($rec['held'])->toBe(1)
        ->and($rec['review_seconds'])->toBeNull();
});

test('候補が複数並ぶとき、2番目の候補を「今回も使う」で選んで保存しても採用として残る', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    history($company, bank: '社長の立替', daysAgo: 4);
    history($company, bank: '社長の立替', daysAgo: 5);
    history($company, bank: '現金', daysAgo: 1);
    history($company, bank: '現金', daysAgo: 2);
    history($company, bank: '現金', daysAgo: 3);

    $candidates = recommend($company)->candidates;
    expect($candidates)->toHaveCount(2)
        ->and($candidates[1]['bank_account_name'])->toBe('社長の立替');

    $source = realSend($company, $editor, 430);
    Livewire::actingAs($editor)
        ->test('pages::company.sources.show', ['company' => $company, 'source' => $source->id])
        ->call('useRecommendation', 1)
        ->assertSet('bankAccountId', bankAccountId($company, '社長の立替'))
        ->set('reason', 'wrong_payment')
        ->call('confirm')->assertHasNoErrors();

    expect($company->firstConfirmations()->sole()->recommendation)
        ->toMatchArray(['outcome' => 'adopted', 'status' => 'hold', 'candidate_count' => 2]);
});

test('根拠にした履歴が戻されると候補は失効し、画面に警告が出て、初回確定の記録にも残る', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $a = history($company);
    history($company, daysAgo: 2);

    $source = sendReceipt($company, $editor);
    $draft = $source->journalEntry()->firstOrFail();
    expect($draft->recommendation['applied'])->toBeTrue();

    $a->forceFill(['status' => EntryStatus::Returned])->save();

    Livewire::actingAs($editor)
        ->test('pages::company.sources.show', ['company' => $company, 'source' => $source->id])
        ->assertSee('data-test="recommendation-expired"', false)
        ->call('confirm')->assertHasNoErrors();

    expect($company->firstConfirmations()->sole()->recommendation)->toMatchArray(['expired' => true]);
});

test('AIが止まっても、履歴の候補か手入力で進める。候補がなくても失敗にならない', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    history($company);
    history($company, daysAgo: 2);

    app(FakeClaudeClient::class)->failWith(AiTask::ReadReceipt, 'error');
    $source = sendReceipt($company, $editor);
    app(FakeClaudeClient::class)->failWith(AiTask::ReadReceipt, 'none');

    expect($source->journalEntry()->first())->toBeNull();

    Livewire::actingAs($editor)
        ->test('pages::company.sources.show', ['company' => $company, 'source' => $source->id])
        ->assertDontSee('data-test="recommendation"', false)
        ->set('vendor', SHOP)
        ->assertSee('data-test="recommendation"', false)
        ->call('useRecommendation', 0)
        ->set('amountYen', '2500')
        ->set('confirmDate', true)
        ->call('confirm')->assertHasNoErrors();

    $entry = $source->refresh()->journalEntry()->with(['category', 'bankAccount'])->firstOrFail();
    expect($entry->status)->toBe(EntryStatus::Confirmed)
        ->and($entry->bankAccount?->name)->toBe('社長の立替')
        ->and($company->firstConfirmations()->sole()->recommendation['outcome'] ?? null)->toBe('adopted');

    // 候補がない店は、そのまま手入力で進める（候補なしは失敗ではない）
    app(FakeClaudeClient::class)->failWith(AiTask::ReadReceipt, 'error');
    $plain = sendReceipt($company, $editor, 500);
    app(FakeClaudeClient::class)->failWith(AiTask::ReadReceipt, 'none');

    Livewire::actingAs($editor)
        ->test('pages::company.sources.show', ['company' => $company, 'source' => $plain->id])
        ->set('vendor', '△△商店')
        ->assertDontSee('data-test="recommendation"', false)
        ->set('categoryId', categoryId($company, '消耗品'))
        ->set('bankAccountId', bankAccountId($company, '現金'))
        ->set('amountYen', '900')
        ->set('confirmDate', true)
        ->call('confirm')->assertHasNoErrors();
});

test('候補を使う・保留にする操作は「直して確定」と同じ権限（editDraft）。表示後にロールを失うと403', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    history($company);
    $source = sendReceipt($company, $editor);

    $useComponent = Livewire::actingAs($editor)->test('pages::company.sources.show', ['company' => $company, 'source' => $source->id]);
    $holdComponent = Livewire::actingAs($editor)->test('pages::company.sources.show', ['company' => $company, 'source' => $source->id]);

    $company->memberships()->where('user_id', $editor->id)->firstOrFail()->forceFill(['role' => Role::Viewer])->save();

    $useComponent->call('useRecommendation', 0)->assertForbidden();
    $holdComponent->call('holdRecommendation')->assertForbidden();

    ['company' => $other, 'user' => $outsider] = createCompanyWithMember(Role::Editor);
    $this->actingAs($outsider)->get(route('company.sources.show', [$other, $source->id]))->assertNotFound();
});
