<?php

use App\Enums\ConcernFlag;
use App\Enums\DuplicateDecision;
use App\Enums\DuplicateStrength;
use App\Enums\EntryStatus;
use App\Enums\Role;
use App\Enums\SourceStatus;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\DuplicateReview;
use App\Models\JournalEntry;
use App\Models\SourceRecord;
use App\Models\User;
use App\Services\Ai\FakeClaudeClient;
use App\Services\DuplicateReviewService;
use App\Services\IgnoreSourceService;
use App\Support\DuplicateCandidateMatcher;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('evidence');
});

/**
 * 今回の対象：AI の下書き（〇〇石油 八幡平店・3,850円・今日・消耗品・現金）。
 *
 * @return array{company: Company, user: User, source: SourceRecord, entry: JournalEntry}
 */
function duplicateTarget(Role $role = Role::Editor): array
{
    ['company' => $company, 'user' => $user] = createCompanyWithMember($role);
    $source = sendReceipt($company, $user);

    return ['company' => $company, 'user' => $user, 'source' => $source, 'entry' => $source->journalEntry()->firstOrFail()];
}

function matchFor(Company $company, SourceRecord $source)
{
    return app(DuplicateCandidateMatcher::class)->forSource($company, $source->refresh());
}

function openShow(Company $company, User $user, SourceRecord $source)
{
    return Livewire::actingAs($user)->test('pages::company.sources.show', ['company' => $company, 'source' => $source->id]);
}

test('照合：hash が違っても、同じ日・同じ店・同じ金額の仕訳を「確かな一致」の候補にし、根拠を返す', function (): void {
    ['company' => $company, 'source' => $source] = duplicateTarget();
    $other = putEntry($company, now()->toDateString(), '消耗品', 3850);

    $result = matchFor($company, $source);

    expect($result->all)->toHaveCount(1)
        ->and($result->all[0]->entryId)->toBe($other->id)
        ->and($result->all[0]->strength)->toBe(DuplicateStrength::Strong)
        ->and($result->all[0]->reasons)->toBe(['amount_same', 'date_same', 'vendor_same'])
        ->and($result->windowDays)->toBe(7);
});

test('照合：同じ入力なら同じ結果になる（AI を使わない）', function (): void {
    ['company' => $company, 'source' => $source] = duplicateTarget();
    putEntry($company, now()->toDateString(), '消耗品', 3850);

    expect(matchFor($company, $source)->all)->toEqual(matchFor($company, $source)->all);
});

test('照合：店名の揺れ・日付のずれ・金額・欠損・範囲外を区別する', function (?string $vendor, int $days, int $amount, ?DuplicateStrength $expected): void {
    ['company' => $company, 'source' => $source] = duplicateTarget();
    putEntry($company, now()->subDays($days)->toDateString(), '消耗品', $amount, EntryStatus::Confirmed, null, $vendor);

    $found = matchFor($company, $source)->all;

    expect($found === [] ? null : $found[0]->strength)->toBe($expected);
})->with([
    '表記ゆれ（株式会社・全角スペース）' => ['株式会社〇〇石油　八幡平店', 0, 3850, DuplicateStrength::Strong],
    '半角全角英数のゆれ' => ['〇〇石油 八幡平店', 0, 3850, DuplicateStrength::Strong],
    '店名が一方を含む（同日）' => ['〇〇石油', 0, 3850, DuplicateStrength::Likely],
    '店名が一方を含む（日付ちがい）' => ['〇〇石油', 2, 3850, DuplicateStrength::Weak],
    '店名が同じで日付が3日ちがい' => ['〇〇石油 八幡平店', 3, 3850, DuplicateStrength::Likely],
    '範囲の端（7日）' => ['〇〇石油 八幡平店', 7, 3850, DuplicateStrength::Likely],
    '範囲外（8日）' => ['〇〇石油 八幡平店', 8, 3850, null],
    '金額が1円ちがう' => ['〇〇石油 八幡平店', 0, 3851, null],
    '店名がまったく違う' => ['△△商店', 0, 3850, null],
    '店名が短すぎて似ているとは見ない' => ['〇〇', 0, 3850, null],
    '店名が候補側にない' => [null, 0, 3850, DuplicateStrength::Weak],
]);

test('照合：返金（収入）は支出と別の取引として候補にしない。請求書と領収書の組み合わせは根拠で知らせる', function (): void {
    ['company' => $company, 'source' => $source] = duplicateTarget();
    putEntry($company, now()->toDateString(), '売上', 3850);

    expect(matchFor($company, $source)->all)->toBe([]);

    $invoice = putEntry($company, now()->subDays(2)->toDateString(), '消耗品', 3850);
    $invoice->forceFill(['bank_account_id' => bankAccountId($company, '後日の請求書（未払金）')])->save();

    $found = matchFor($company, $source)->all;
    expect($found)->toHaveCount(1)
        ->and($found[0]->strength)->toBe(DuplicateStrength::Likely)
        ->and($found[0]->reasons)->toContain('payment_differs');
});

test('照合：AIが読めず仮に入れた日付は、確かな一致にしない（ゆるい一致で根拠に書く）', function (): void {
    app(FakeClaudeClient::class)->withReading(['date' => null]);
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);
    $source = sendReceipt($company, $user);
    putEntry($company, now()->toDateString(), '消耗品', 3850);

    $found = matchFor($company, $source)->all;

    expect($found)->toHaveCount(1)
        ->and($found[0]->strength)->toBe(DuplicateStrength::Weak)
        ->and($found[0]->reasons)->toContain('date_unconfirmed');
});

test('照合：金額がまだない下書き・対象外の原資料・他社の仕訳は候補にしない', function (): void {
    ['company' => $company, 'source' => $source] = duplicateTarget();
    ['company' => $other] = createCompanyWithMember(Role::Editor);
    putEntry($other, now()->toDateString(), '消耗品', 3850);

    expect(matchFor($company, $source)->all)->toBe([]);

    // 対象外にした原資料（つながる下書きも消える）は候補にならない
    $second = sendReceipt($company, User::query()->findOrFail($source->submitted_by), 410);
    expect(matchFor($company, $source)->all)->toHaveCount(1);

    app(IgnoreSourceService::class)->ignore($company, User::query()->findOrFail($source->submitted_by), $second->id, '写真が重なった');
    expect(matchFor($company, $source)->all)->toBe([]);
});

test('照合：上限（5件）を超えても黙って切らず、「ほか n 件」と件数で知らせる。月またぎ・31件超も拾う', function (): void {
    ['company' => $company, 'user' => $editor, 'source' => $source, 'entry' => $entry] = duplicateTarget();
    $entry->forceFill(['entry_date' => '2026-03-01'])->save();

    foreach (range(1, 31) as $i) {
        putEntry($company, $i <= 6 ? '2026-02-28' : '2026-03-01', '消耗品', 3850);
    }

    $result = matchFor($company, $source);

    expect($result->all)->toHaveCount(31)
        ->and($result->shown())->toHaveCount(5)
        ->and($result->hiddenCount())->toBe(26);

    openShow($company, $editor, $source)->assertSeeHtml('data-test="duplicate-hidden"')->assertSee('ほか 26 件');
});

test('詳細画面：候補・根拠・強さを出し、重複と決めつけず、候補がないときも「ない」と出す', function (): void {
    ['company' => $company, 'user' => $editor, 'source' => $source] = duplicateTarget();

    openShow($company, $editor, $source)
        ->assertSeeHtml('data-test="duplicate-none"')
        ->assertSee('重複がないことを保証しません');

    putEntry($company, now()->toDateString(), '消耗品', 3850);

    openShow($company, $editor, $source)
        ->assertSeeHtml('data-test="duplicate-candidates"')
        ->assertSee('確かな一致')
        ->assertSee('金額が同じ')
        ->assertSee('日付が同じ')
        ->assertSee('重複と決めつけるものではありません')
        ->assertSeeHtml('data-test="duplicate-open"');
});

test('保存：候補があっても止めず、同じ日・店・金額の別取引を拒否しない。気になる印と監査に候補を残す', function (): void {
    ['company' => $company, 'user' => $editor, 'source' => $source, 'entry' => $entry] = duplicateTarget();
    $other = putEntry($company, now()->toDateString(), '消耗品', 3850);

    openShow($company, $editor, $source)->call('confirm')->assertHasNoErrors();

    $entry->refresh();
    $audit = AuditLog::where('action', 'entry.confirm')->where('subject_id', $entry->id)->firstOrFail();

    expect($entry->status)->toBe(EntryStatus::Confirmed)
        ->and($entry->concern_flags)->toContain(ConcernFlag::DuplicateCandidate->value)
        ->and($audit->after['duplicate_candidates'][0]['entry_id'] ?? null)->toBe($other->id)
        ->and(array_key_exists('decision', $audit->after['duplicate_candidates'][0]))->toBeTrue()
        ->and($audit->after['duplicate_candidates'][0]['decision'])->toBeNull()
        ->and($other->refresh()->status)->toBe(EntryStatus::Confirmed);
});

test('保存：確定の直前に候補が増えても、保存の時点の最新の候補で照合する', function (): void {
    ['company' => $company, 'user' => $editor, 'source' => $source, 'entry' => $entry] = duplicateTarget();

    $component = openShow($company, $editor, $source);
    putEntry($company, now()->toDateString(), '消耗品', 3850);
    $component->call('confirm')->assertHasNoErrors();

    expect($entry->refresh()->concern_flags)->toContain(ConcernFlag::DuplicateCandidate->value);
});

test('保存：ゆるい一致は表示だけで、気になる印は付けない。AIの重複の疑いは別の印として残る', function (): void {
    ['company' => $company, 'user' => $editor, 'source' => $source, 'entry' => $entry] = duplicateTarget();
    putEntry($company, now()->toDateString(), '消耗品', 3850, EntryStatus::Confirmed, null, null);
    $entry->forceFill(['ai_check' => [...($entry->ai_check ?? []), 'duplicate_suspected' => true]])->save();

    openShow($company, $editor, $source)
        ->assertSeeHtml('data-test="ai-duplicate-hint"')
        ->assertSee('どの取引かはAIでは特定できません')
        ->call('confirm')->assertHasNoErrors();

    expect($entry->refresh()->concern_flags)->toContain(ConcernFlag::AiDuplicate->value)
        ->and($entry->concern_flags)->not->toContain(ConcernFlag::DuplicateCandidate->value);
});

test('判断：別の取引は理由・強さ・根拠・対象と候補の値を記録し、候補は印に数えない。保留は印のまま', function (): void {
    ['company' => $company, 'user' => $editor, 'source' => $source, 'entry' => $entry] = duplicateTarget();
    $other = putEntry($company, now()->toDateString(), '消耗品', 3850);

    $component = openShow($company, $editor, $source)
        ->call('decideDuplicate', $other->id, 'different')
        ->assertHasErrors('duplicateReason');
    expect(DuplicateReview::count())->toBe(0);

    $component->set('duplicateReason', '同じ日に2回給油した')->call('decideDuplicate', $other->id, 'hold')
        ->assertHasNoErrors()->assertSeeHtml('data-test="duplicate-reviewed"')->assertSee('判断済み：保留');
    expect(matchFor($company, $source)->concerning())->toHaveCount(1);

    $component->set('duplicateReason', '同じ日に2回給油した')->call('decideDuplicate', $other->id, 'different')->assertHasNoErrors();

    $review = DuplicateReview::where('decision', DuplicateDecision::Different)->sole();
    expect($review->reason)->toBe('同じ日に2回給油した')
        ->and($review->decided_by)->toBe($editor->id)
        ->and($review->company_id)->toBe($company->id)
        ->and($review->candidate_entry_id)->toBe($other->id)
        ->and($review->target_entry_id)->toBe($entry->id)
        ->and($review->strength)->toBe(DuplicateStrength::Strong)
        ->and($review->reasons)->toBe(['amount_same', 'date_same', 'vendor_same'])
        ->and(strlen($review->target_fingerprint))->toBe(40)
        ->and(matchFor($company, $source)->concerning())->toBe([]);

    $component->call('confirm')->assertHasNoErrors();
    expect($entry->refresh()->concern_flags)->not->toContain(ConcernFlag::DuplicateCandidate->value);
});

test('判断：二重クリックで同じ判断の記録が増えない', function (): void {
    ['company' => $company, 'user' => $editor, 'source' => $source] = duplicateTarget();
    $other = putEntry($company, now()->toDateString(), '消耗品', 3850);
    $service = app(DuplicateReviewService::class);

    $first = $service->decide($company, $editor, $source->id, $other->id, DuplicateDecision::Different, '別の取引');
    $second = $service->decide($company, $editor, $source->id, $other->id, DuplicateDecision::Different, '別の取引');

    expect($second->id)->toBe($first->id)->and(DuplicateReview::count())->toBe(1);
});

test('判断：対象や候補の値が変わったら、古い判断は使わない（もう一度判断が要る）', function (): void {
    ['company' => $company, 'user' => $editor, 'source' => $source] = duplicateTarget();
    $other = putEntry($company, now()->toDateString(), '消耗品', 3850);
    app(DuplicateReviewService::class)->decide($company, $editor, $source->id, $other->id, DuplicateDecision::Different, '別の取引');
    expect(matchFor($company, $source)->concerning())->toBe([]);

    $other->forceFill(['entry_date' => now()->subDay()->toDateString()])->save();

    $found = matchFor($company, $source)->all;
    expect($found)->toHaveCount(1)
        ->and($found[0]->review)->toBeNull()
        ->and($found[0]->raisesConcern())->toBeTrue();
});

test('判断：画面を開いたあとに候補が変わっていたら、記録せずに知らせる', function (): void {
    ['company' => $company, 'user' => $editor, 'source' => $source] = duplicateTarget();
    $other = putEntry($company, now()->toDateString(), '消耗品', 3850);

    $component = openShow($company, $editor, $source);
    $other->lines()->get()->each->forceFill(['amount_yen' => 9999])->each->save();

    $component->set('duplicateReason', '別の取引')->call('decideDuplicate', $other->id, 'different')->assertHasErrors('duplicateReason');
    expect(DuplicateReview::count())->toBe(0);
});

test('判断：「重複なので今回を対象外」は既存の対象外の処理を使い、候補側は変えない', function (): void {
    ['company' => $company, 'user' => $editor, 'source' => $source, 'entry' => $entry] = duplicateTarget();
    $other = putEntry($company, now()->toDateString(), '消耗品', 3850);

    openShow($company, $editor, $source)
        ->set('duplicateReason', '同じレシートを撮り直した')
        ->call('decideDuplicate', $other->id, 'duplicate')
        ->assertHasNoErrors();

    $source->refresh();
    expect($source->status)->toBe(SourceStatus::Ignored)
        ->and($source->ignored_reason)->toBe('重複：同じレシートを撮り直した')
        ->and($source->ignored_by)->toBe($editor->id)
        ->and(JournalEntry::find($entry->id))->toBeNull()
        ->and($other->refresh()->status)->toBe(EntryStatus::Confirmed)
        ->and(DuplicateReview::where('decision', DuplicateDecision::Duplicate)->sole()->candidate_entry_id)->toBe($other->id)
        ->and(AuditLog::where('action', 'duplicate.decide')->count())->toBe(1)
        ->and(AuditLog::where('action', 'source.ignore')->count())->toBe(1);
});

test('判断：確定した原資料は対象外にも判断にもできず、確定済みの仕訳を取り消さない', function (): void {
    ['company' => $company, 'user' => $editor, 'source' => $source, 'entry' => $entry] = duplicateTarget();
    $other = putEntry($company, now()->toDateString(), '消耗品', 3850);
    openShow($company, $editor, $source)->call('confirm')->assertHasNoErrors();

    expect(fn () => app(DuplicateReviewService::class)->decide($company, $editor, $source->id, $other->id, DuplicateDecision::Duplicate, '重複'))
        ->toThrow(ValidationException::class);

    expect($entry->refresh()->status)->toBe(EntryStatus::Confirmed)
        ->and($source->refresh()->status)->toBe(SourceStatus::Confirmed)
        ->and(DuplicateReview::count())->toBe(0);
});

test('戻し・再確定：社長から戻された仕訳も候補になり、戻された仕訳の再確定で候補を照合し直す', function (): void {
    ['company' => $company, 'user' => $editor, 'source' => $source, 'entry' => $entry] = duplicateTarget();
    $returned = putEntry($company, now()->toDateString(), '消耗品', 3850, EntryStatus::Returned);

    expect(matchFor($company, $source)->all[0]->statusLabel)->toBe('社長から戻し');

    $returned->forceFill(['status' => EntryStatus::Confirmed])->save();
    openShow($company, $editor, $source)->call('confirm')->assertHasNoErrors();
    expect($entry->refresh()->concern_flags)->toContain(ConcernFlag::DuplicateCandidate->value);
});

test('並行して届いた同じレシート：互いを候補として見つけ、先に確定した側も後から候補を見られる', function (): void {
    ['company' => $company, 'user' => $editor, 'source' => $a] = duplicateTarget();
    $b = sendReceipt($company, $editor, 410);

    expect(matchFor($company, $a)->all)->toHaveCount(1)
        ->and(matchFor($company, $b)->all)->toHaveCount(1)
        ->and(matchFor($company, $a)->all[0]->sourceId)->toBe($b->id);

    openShow($company, $editor, $a)->call('confirm')->assertHasNoErrors();

    $found = matchFor($company, $b)->all;
    expect($found)->toHaveCount(1)->and($found[0]->statusLabel)->toBe('確定');
});

test('権限：他社の候補IDでは判断できず（404）、送るだけの人は確認の画面を開けない', function (): void {
    ['company' => $company, 'user' => $editor, 'source' => $source] = duplicateTarget();
    ['company' => $other] = createCompanyWithMember(Role::Editor);
    $foreign = putEntry($other, now()->toDateString(), '消耗品', 3850);
    ['user' => $worker] = createCompanyWithMember(Role::Editor, submitOnly: true, company: $company);

    expect(fn () => app(DuplicateReviewService::class)->decide($company, $editor, $source->id, $foreign->id, DuplicateDecision::Hold, ''))
        ->toThrow(ModelNotFoundException::class);
    expect(matchFor($company, $source)->all)->toBe([])
        ->and(DuplicateReview::count())->toBe(0);

    $this->actingAs($worker)->get(route('company.sources.show', [$company, $source->id]))->assertForbidden();
});

test('ライブ画面：強い・近い一致の候補があるときだけ件数と「見比べる」入口を出す', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    $source = sendReceipt($company, $owner);

    Livewire::actingAs($owner)->test('pages::company.live', ['company' => $company])->assertDontSeeHtml('data-test="live-duplicate"');

    putEntry($company, now()->toDateString(), '消耗品', 3850);

    Livewire::actingAs($owner)->test('pages::company.live', ['company' => $company])
        ->assertSeeHtml('data-test="live-duplicate"')
        ->assertSee('二重計上の候補が 1 件あります');

    expect($source->refresh()->status)->toBe(SourceStatus::Drafted);
});
