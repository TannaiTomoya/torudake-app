<?php

use App\Enums\AiTask;
use App\Enums\CorrectionReason;
use App\Enums\EntryStatus;
use App\Enums\Role;
use App\Models\AiRun;
use App\Models\Company;
use App\Models\EntryPostEvent;
use App\Models\FirstConfirmation;
use App\Models\JournalEntry;
use App\Models\SourceRecord;
use App\Models\User;
use App\Services\Ai\FakeClaudeClient;
use App\Services\ConfirmEntryInput;
use App\Services\ConfirmEntryService;
use App\Services\OwnerReviewService;
use App\Services\TrainingDataExporter;
use App\Support\FirstConfirmationMetrics;
use Carbon\CarbonImmutable;
use Database\Seeders\CompanyDefaultSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

// #32：「手を入れなかった率」は、初回確定の時点の記録から数える。測るのは行動で、AIの答えの正しさではない

beforeEach(function (): void {
    Storage::fake('evidence');
    Storage::fake('local');
    app(FakeClaudeClient::class)->withReading([]);
});

/**
 * 実際のAI（claude）が作った下書きとして扱う（テストのAIは fake なので、実行記録の driver を替える）。
 */
function realReceipt(Company $company, User $user, int $width, bool $real = true): SourceRecord
{
    $source = sendReceipt($company, $user, $width);

    if ($real) {
        AiRun::query()->where('subject_id', $source->id)->update(['driver' => 'claude']);
    }

    return $source->refresh();
}

/**
 * @param  array<string, mixed>  $change  categoryId・bankAccountId・amountYen・description・reason・vendor
 */
function confirmFirst(Company $company, User $user, SourceRecord $source, array $change = []): JournalEntry
{
    /** @var JournalEntry $draft */
    $draft = $source->journalEntry()->firstOrFail();

    return app(ConfirmEntryService::class)->confirm($company, $user, $source->id, new ConfirmEntryInput(
        entryDate: $draft->entry_date->toDateString(),
        vendor: array_key_exists('vendor', $change) ? $change['vendor'] : $draft->vendor,
        description: array_key_exists('description', $change) ? $change['description'] : $draft->description,
        categoryId: $change['categoryId'] ?? (string) $draft->category_id,
        bankAccountId: $change['bankAccountId'] ?? (string) $draft->bank_account_id,
        amountYen: $change['amountYen'] ?? $draft->amountYen(),
        reason: $change['reason'] ?? null,
        readingConfirmed: ['date'],
    ));
}

function otherCategoryId(Company $company, JournalEntry $draft): string
{
    $direction = $company->categories()->whereKey($draft->category_id)->value('direction');

    return (string) $company->categories()->where('direction', $direction)->whereKeyNot($draft->category_id)->value('id');
}

function thisMonth(): CarbonImmutable
{
    return CarbonImmutable::now()->startOfMonth();
}

function firstRow(JournalEntry $entry): FirstConfirmation
{
    return FirstConfirmation::query()->where('journal_entry_id', $entry->id)->sole();
}

/**
 * @return array{rows: list<array<string, mixed>>, manifest: array<string, mixed>}
 */
function exportedFirstRows(Company $company, ?CarbonImmutable $month = null): array
{
    $export = app(TrainingDataExporter::class)->export($company, $month ?? thisMonth());
    $path = tempnam(sys_get_temp_dir(), 'first-zip-');
    file_put_contents($path, $export['contents']);
    $zip = new ZipArchive;
    $zip->open($path);
    $text = (string) $zip->getFromName('first_confirmations.jsonl');
    $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $zip->close();
    unlink($path);

    $rows = array_map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), array_values(array_filter(explode("\n", $text))));

    return ['rows' => $rows, 'manifest' => $manifest];
}

test('固定の期待値：無変更・分類変更・金額補完・摘要のみ・日付補完・手入力・fake が、それぞれの欄に入る', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);

    // A 無変更
    $a = confirmFirst($company, $editor, realReceipt($company, $editor, 400));

    // B 分類変更（理由つき）
    $sourceB = realReceipt($company, $editor, 410);
    $b = confirmFirst($company, $editor, $sourceB, ['categoryId' => otherCategoryId($company, $sourceB->journalEntry()->firstOrFail()), 'reason' => CorrectionReason::WrongCategory]);

    // C 金額の記載なし → 人が入れた
    app(FakeClaudeClient::class)->withReading(['total_yen' => null, 'amount_missing' => true, 'payment_method' => 'invoice_later', 'tax_breakdown' => []]);
    $sourceC = realReceipt($company, $editor, 420);
    app(FakeClaudeClient::class)->withReading([]);
    $c = confirmFirst($company, $editor, $sourceC, ['amountYen' => 6820]);

    // D 摘要だけ変更
    $d = confirmFirst($company, $editor, realReceipt($company, $editor, 430), ['description' => '人が書き直した摘要']);

    // G 日付を読めず、確かめただけで保存（手を入れていないが必須項目が揃っていない）
    app(FakeClaudeClient::class)->withReading(['date' => null]);
    $g = confirmFirst($company, $editor, realReceipt($company, $editor, 440));
    app(FakeClaudeClient::class)->withReading([]);

    // E AIが読めず、人が最初から入力
    app(FakeClaudeClient::class)->failWith(AiTask::ReadReceipt, 'error');
    $sourceE = realReceipt($company, $editor, 450);
    app(FakeClaudeClient::class)->failWith(AiTask::ReadReceipt, 'none');
    $e = app(ConfirmEntryService::class)->confirm($company, $editor, $sourceE->id, new ConfirmEntryInput(
        entryDate: now()->toDateString(),
        vendor: '△△商店',
        description: null,
        categoryId: categoryId($company, '部品・修理'),
        bankAccountId: (string) $company->bankAccounts()->orderBy('created_at')->value('id'),
        amountYen: 12000,
        readingConfirmed: ['date'],
    ));

    // F fake（実際のAIではない）で無変更
    $f = confirmFirst($company, $editor, realReceipt($company, $editor, 460, real: false));

    expect(firstRow($a)->isUnchanged())->toBeTrue()
        ->and(firstRow($a)->evaluation_class)->toBe('real')
        ->and(firstRow($a)->changed_fields)->toBe([])
        ->and(firstRow($b)->changed_fields)->toBe(['category'])
        ->and(firstRow($b)->reason)->toBe('wrong_category')
        ->and(firstRow($b)->isUnchanged())->toBeFalse()
        ->and(firstRow($c)->filled_fields)->toBe(['amount'])
        ->and(firstRow($c)->changed_fields)->toBe([])
        ->and(firstRow($c)->required_complete)->toBeFalse()
        ->and(firstRow($c)->draft_origin['amount'])->toBe('missing')
        ->and(firstRow($d)->description_changed)->toBeTrue()
        ->and(firstRow($d)->isUnchanged())->toBeTrue()
        ->and(firstRow($g)->required_complete)->toBeFalse()
        ->and(firstRow($g)->draft_origin['date'])->toBe('defaulted')
        ->and(firstRow($g)->isUnchanged())->toBeFalse()
        ->and(firstRow($e)->kind)->toBe('manual')
        ->and(firstRow($e)->ai_run_id)->toBeNull()
        ->and(firstRow($e)->changed_fields)->toBe([])
        ->and(firstRow($f)->evaluation_class)->toBe('demo')
        ->and(firstRow($f)->driver)->toBe('fake');

    expect(FirstConfirmationMetrics::for($company, thisMonth()))->toMatchArray([
        'class' => 'real',
        'ai_first' => 5,
        'unchanged' => 2,
        'unchanged_pct' => 40,
        'amount_filled' => 1,
        'amount_filled_pct' => 20,
        'description_changed' => 1,
        'description_changed_pct' => 20,
        'history_applied' => 0,
        'manual' => 1,
        'manual_pct' => 17,
        'post_corrected' => 0,
        'post_corrected_pct' => 0,
        'ai_runs' => 11,
        'ai_failed' => 1,
        'ai_failed_pct' => 9,
        'legacy' => 0,
        'other_class' => 1,
    ]);
});

test('母数0は0%にも100%にもしない（率は null）。手入力だけの月も、AIの率は null', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);

    expect(FirstConfirmationMetrics::for($company, thisMonth()))->toMatchArray([
        'ai_first' => 0,
        'unchanged_pct' => null,
        'amount_filled_pct' => null,
        'description_changed_pct' => null,
        'manual_pct' => null,
        'post_corrected_pct' => null,
        'ai_failed_pct' => null,
    ])->and(FirstConfirmationMetrics::pct(0, 0))->toBeNull();

    app(FakeClaudeClient::class)->failWith(AiTask::ReadReceipt, 'error');
    $source = realReceipt($company, $editor, 400);
    app(FakeClaudeClient::class)->failWith(AiTask::ReadReceipt, 'none');
    app(ConfirmEntryService::class)->confirm($company, $editor, $source->id, new ConfirmEntryInput(
        entryDate: now()->toDateString(),
        vendor: null,
        description: null,
        categoryId: categoryId($company, '部品・修理'),
        bankAccountId: (string) $company->bankAccounts()->orderBy('created_at')->value('id'),
        amountYen: 3000,
        readingConfirmed: ['date'],
    ));

    expect(FirstConfirmationMetrics::for($company, thisMonth()))->toMatchArray([
        'ai_first' => 0,
        'unchanged_pct' => null,
        'manual' => 1,
        'manual_pct' => 100,
        'ai_runs' => 1,
        'ai_failed' => 1,
        'ai_failed_pct' => 100,
    ]);
});

test('記録のない過去の仕訳は「評価不能」に分け、率に入れない（0%にも100%にもしない）', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $entry = confirmFirst($company, $editor, realReceipt($company, $editor, 400));

    // この機能の前に確定した仕訳＝初回確定の記録がない
    DB::table('first_confirmations')->where('journal_entry_id', $entry->id)->delete();

    expect(FirstConfirmationMetrics::for($company, thisMonth()))->toMatchArray([
        'ai_first' => 0,
        'unchanged_pct' => null,
        'legacy' => 1,
    ]);

    Livewire::actingAs(createCompanyWithMember(Role::Owner, company: $company)['user'])
        ->test('pages::company.ai', ['company' => $company])
        ->assertSeeHtml('data-test="first-none"')
        ->assertSeeHtml('data-test="first-legacy"')
        ->assertSee('評価不能');
});

test('月またぎ：翌月に再確定しても、初回確定は元の月に1回だけ。翌月の母数は増えず、過去データ扱いにもならない', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 10:00'));
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    ['user' => $owner] = createCompanyWithMember(Role::Owner, company: $company);
    $source = realReceipt($company, $editor, 400);
    $entry = confirmFirst($company, $editor, $source);
    $october = CarbonImmutable::parse('2026-10-01');
    $november = CarbonImmutable::parse('2026-11-01');

    expect(FirstConfirmationMetrics::for($company, $october))->toMatchArray(['ai_first' => 1, 'unchanged' => 1, 'unchanged_pct' => 100, 'post_corrected' => 0]);

    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00'));
    app(OwnerReviewService::class)->returnToBookkeeper($company, $owner, $entry->id, 'もう一度見てください');
    confirmFirst($company, $editor, $source, ['categoryId' => otherCategoryId($company, $entry), 'reason' => CorrectionReason::WrongCategory]);

    $row = firstRow($entry->refresh());
    expect($entry->status)->toBe(EntryStatus::Confirmed)
        ->and($entry->confirmed_at?->toDateString())->toBe('2026-11-02')
        ->and($row->first_confirmed_at->toDateString())->toBe('2026-10-31')
        ->and($row->isUnchanged())->toBeTrue()
        ->and($row->changed_fields)->toBe([])
        ->and(FirstConfirmation::query()->count())->toBe(1);

    // 初回確定の月：率は変わらず、後から戻した・直した件数だけ別に出る
    expect(FirstConfirmationMetrics::for($company, $october))->toMatchArray(['ai_first' => 1, 'unchanged' => 1, 'unchanged_pct' => 100, 'post_corrected' => 1, 'post_corrected_pct' => 100]);
    // 翌月：二重に数えない。確定した月は11月だが、初回の記録があるので評価不能にもならない
    expect(FirstConfirmationMetrics::for($company, $november))->toMatchArray(['ai_first' => 0, 'unchanged_pct' => null, 'legacy' => 0, 'post_corrected' => 0]);

    $events = EntryPostEvent::query()->where('journal_entry_id', $entry->id)->orderBy('occurred_at')->orderBy('id')->get();
    expect($events->pluck('kind')->sort()->values()->all())->toBe(['reconfirmed', 'returned'])
        ->and($events->firstWhere('kind', 'reconfirmed')->changed_fields)->toBe(['category'])
        ->and($events->firstWhere('kind', 'returned')->changed_fields)->toBe([]);

    // 再ダウンロードしても同じ。10月の書き出しにだけ載り、11月には載らない
    $first = exportedFirstRows($company, $october);
    $again = exportedFirstRows($company, $october);
    expect(array_column($first['rows'], 'id'))->toBe(array_column($again['rows'], 'id'))
        ->and($first['rows'])->toHaveCount(1)
        ->and($first['rows'][0]['unchanged'])->toBeTrue()
        ->and(array_column($first['rows'][0]['post_events'], 'kind'))->toBe(['returned', 'reconfirmed'])
        ->and(exportedFirstRows($company, $november)['rows'])->toBe([]);
});

test('戻し中：戻したが再確定していない仕訳は、事後修正に数える。初回の記録は変わらない', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    ['user' => $owner] = createCompanyWithMember(Role::Owner, company: $company);
    $entry = confirmFirst($company, $editor, realReceipt($company, $editor, 400));

    app(OwnerReviewService::class)->returnToBookkeeper($company, $owner, $entry->id, '確かめてください');
    app(OwnerReviewService::class)->returnToBookkeeper($company, $owner, $entry->id, '確かめてください');

    expect($entry->refresh()->status)->toBe(EntryStatus::Returned)
        ->and(EntryPostEvent::query()->count())->toBe(1)
        ->and(firstRow($entry)->isUnchanged())->toBeTrue()
        ->and(FirstConfirmationMetrics::for($company, thisMonth()))->toMatchArray(['ai_first' => 1, 'unchanged_pct' => 100, 'post_corrected' => 1, 'post_corrected_pct' => 100]);
});

test('二重クリック：2回保存しても、初回確定の記録も確定後の出来事も増えない', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $source = realReceipt($company, $editor, 400);

    confirmFirst($company, $editor, $source);
    confirmFirst($company, $editor, $source);

    expect(FirstConfirmation::query()->count())->toBe(1)
        ->and(EntryPostEvent::query()->count())->toBe(0);
});

test('店名が読めず人が入れた：必須項目は揃っていても「手を入れず」には数えない', function (): void {
    app(FakeClaudeClient::class)->withReading(['vendor' => null]);
    app(FakeClaudeClient::class)->withJournalize(['vendor' => null]);
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $source = realReceipt($company, $editor, 400);
    app(FakeClaudeClient::class)->withReading([]);
    app(FakeClaudeClient::class)->withJournalize([]);

    $entry = confirmFirst($company, $editor, $source, ['vendor' => '△△商店']);
    $row = firstRow($entry);

    expect($row->required_complete)->toBeTrue()
        ->and($row->filled_fields)->toBe(['vendor'])
        ->and($row->changed_fields)->toBe([])
        ->and($row->draft_origin['vendor'])->toBe('missing')
        ->and($row->isUnchanged())->toBeFalse()
        ->and(FirstConfirmationMetrics::for($company, thisMonth()))->toMatchArray(['ai_first' => 1, 'unchanged' => 0, 'unchanged_pct' => 0]);
});

test('初回確定の記録は、書き換えも削除もできない', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $entry = confirmFirst($company, $editor, realReceipt($company, $editor, 400));
    $row = firstRow($entry);

    expect(fn () => $row->forceFill(['required_complete' => false])->save())->toThrow(LogicException::class)
        ->and(fn () => $row->delete())->toThrow(LogicException::class)
        ->and(firstRow($entry)->required_complete)->toBeTrue();
});

test('由来を混ぜない：同じ店で直した支払い元の履歴を使った下書きは「履歴」と記録し、履歴なしの率を別に出す', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);

    $sourceA = realReceipt($company, $editor, 400);
    $draftA = $sourceA->journalEntry()->firstOrFail();
    $otherBank = (string) $company->bankAccounts()->whereKeyNot($draftA->bank_account_id)->orderBy('created_at')->value('id');
    $a = confirmFirst($company, $editor, $sourceA, ['bankAccountId' => $otherBank, 'reason' => CorrectionReason::WrongPayment]);

    // 履歴1件では当てない（#54）。同じ店で2件そろってから、支払い元に履歴を当てる
    $sourceA2 = realReceipt($company, $editor, 405);
    $a2 = confirmFirst($company, $editor, $sourceA2); // AI は直した記録を例として受け取り、同じ支払い元を選ぶ

    $sourceB = realReceipt($company, $editor, 410);
    $b = confirmFirst($company, $editor, $sourceB);

    expect(firstRow($a)->draft_origin['payment'])->toBeIn(['ai', 'default'])
        ->and(firstRow($a)->changed_fields)->toBe(['credit_account'])
        ->and(firstRow($a2)->draft_origin['payment'])->toBe('ai')
        ->and(firstRow($b)->draft_origin['payment'])->toBe('history')
        ->and(firstRow($b)->isUnchanged())->toBeTrue()
        ->and(FirstConfirmationMetrics::for($company, thisMonth()))->toMatchArray([
            'ai_first' => 3,
            'unchanged' => 2,
            'history_applied' => 1,
            'without_history' => ['total' => 2, 'unchanged' => 1, 'pct' => 50],
        ]);
});

test('実際のAIとデモ・fakeを混ぜない：デモ会社は demo だけを出し、実際の会社は real だけを出して他方は件数で知らせる', function (): void {
    ['company' => $real, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    confirmFirst($real, $editor, realReceipt($real, $editor, 400));
    confirmFirst($real, $editor, realReceipt($real, $editor, 410, real: false));

    $demo = Company::factory()->demo()->create();
    CompanyDefaultSeeder::seedForCompany($demo);
    ['user' => $demoEditor] = createCompanyWithMember(Role::Editor, company: $demo);
    confirmFirst($demo, $demoEditor, realReceipt($demo, $demoEditor, 400));

    expect(FirstConfirmationMetrics::for($real, thisMonth()))->toMatchArray(['class' => 'real', 'ai_first' => 1, 'other_class' => 1])
        ->and(FirstConfirmationMetrics::for($demo, thisMonth()))->toMatchArray(['class' => 'demo', 'ai_first' => 1, 'other_class' => 0])
        ->and(FirstConfirmation::query()->where('company_id', $demo->id)->sole()->evaluation_class)->toBe('demo');

    ['user' => $owner] = createCompanyWithMember(Role::Owner, company: $real);
    Livewire::actingAs($owner)->test('pages::company.ai', ['company' => $real])
        ->assertSeeHtml('data-test="first-other-class"')
        ->assertSee('数字が混ざらないよう別にしています');
});

test('他社の数字は混ざらない', function (): void {
    ['company' => $a, 'user' => $editorA] = createCompanyWithMember(Role::Editor);
    ['company' => $b, 'user' => $editorB] = createCompanyWithMember(Role::Editor);
    confirmFirst($a, $editorA, realReceipt($a, $editorA, 400));
    confirmFirst($b, $editorB, realReceipt($b, $editorB, 400));
    confirmFirst($b, $editorB, realReceipt($b, $editorB, 410));

    expect(FirstConfirmationMetrics::for($a, thisMonth())['ai_first'])->toBe(1)
        ->and(FirstConfirmationMetrics::for($b, thisMonth())['ai_first'])->toBe(2)
        ->and(exportedFirstRows($a)['rows'])->toHaveCount(1);
});

test('画面・書き出しの manifest・JSONL から数え直した値が、母数も分子も一致する', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    ['user' => $owner] = createCompanyWithMember(Role::Owner, company: $company);

    confirmFirst($company, $editor, realReceipt($company, $editor, 400));
    $sourceB = realReceipt($company, $editor, 410);
    confirmFirst($company, $editor, $sourceB, ['categoryId' => otherCategoryId($company, $sourceB->journalEntry()->firstOrFail()), 'reason' => CorrectionReason::WrongCategory]);
    confirmFirst($company, $editor, realReceipt($company, $editor, 420), ['description' => '摘要を直した']);
    confirmFirst($company, $editor, realReceipt($company, $editor, 430, real: false));

    $metrics = FirstConfirmationMetrics::for($company, thisMonth());
    $export = exportedFirstRows($company);
    $rows = collect($export['rows']);

    // JSONL から、定義どおり数え直す（evaluation_class が real の AI 由来）
    $ai = $rows->where('evaluation_class', 'real')->where('kind', 'ai');

    expect($export['manifest']['metrics'])->toBe(json_decode((string) json_encode($metrics), true))
        ->and($export['manifest']['metric_version'])->toBe('analysis-metrics.v2')
        ->and($export['manifest']['schema_version'])->toBe(5)
        ->and($ai->count())->toBe($metrics['ai_first'])
        ->and($ai->where('unchanged', true)->count())->toBe($metrics['unchanged'])
        ->and($ai->where('description_changed', true)->count())->toBe($metrics['description_changed'])
        ->and($rows->where('evaluation_class', 'demo')->count())->toBe($metrics['other_class'])
        ->and($export['manifest']['files']['first_confirmations.jsonl']['rows'])->toBe($rows->count());

    Livewire::actingAs($owner)->test('pages::company.ai', ['company' => $company])
        ->assertSeeHtml('data-test="first-total">'.$metrics['ai_first'].'</span>')
        ->assertSeeHtml('data-test="first-unchanged">'.$metrics['unchanged'].'</span>')
        ->assertSeeHtml('data-test="first-unchanged-pct">'.$metrics['unchanged_pct'].'</span>')
        ->assertSee('正解率）ではありません')
        ->assertSee('従来の数え方（参考）');
});

test('読み取りの確認・重複候補の判断・理由は、初回確定の記録に構造化して残る（本文や自由文は持たない）', function (): void {
    app(FakeClaudeClient::class)->withReading(['date' => null]);
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $source = realReceipt($company, $editor, 400);
    app(FakeClaudeClient::class)->withReading([]);
    $entry = confirmFirst($company, $editor, $source);
    $row = firstRow($entry);

    expect($row->reading_methods)->toBe(['date' => 'confirmed'])
        ->and($row->duplicate)->toEqual(['shown' => 0, 'concerning' => 0, 'decided' => 0])
        ->and($row->reason)->toBeNull()
        ->and(array_keys($row->getAttributes()))->not->toContain('vendor', 'description', 'note', 'full_text');
});
