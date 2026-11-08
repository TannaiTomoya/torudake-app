<?php

use App\Enums\AiTask;
use App\Enums\EntryStatus;
use App\Enums\Role;
use App\Enums\SourceStatus;
use App\Jobs\ProcessEvidenceWithAi;
use App\Models\JournalEntry;
use App\Models\ReadingConfirmation;
use App\Services\Ai\FakeClaudeClient;
use App\Services\ConfirmEntryInput;
use App\Services\ConfirmEntryService;
use App\Services\OwnerReviewService;
use App\Support\ReadingChecks;
use App\Support\ReadingReview;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('evidence');
});

function showReview(object $company, object $user, object $source)
{
    return Livewire::actingAs($user)->test('pages::company.sources.show', ['company' => $company, 'source' => $source->id]);
}

test('日付の理由：欠落・形式不正・存在しない日・未来日を区別し、今日と過去は使える', function (mixed $value, ?string $expected): void {
    expect(ReadingChecks::dateProblem(is_callable($value) ? $value() : $value))->toBe($expected);
})->with([
    'null' => [null, 'missing'],
    '空文字' => ['', 'missing'],
    '空白だけ' => ['  ', 'missing'],
    '文字' => ['先月', 'invalid'],
    '整数' => [20261001, 'invalid'],
    '月が13' => ['2026-13-01', 'invalid'],
    '存在しない日' => ['2026-02-30', 'invalid'],
    'ゼロ埋めなし' => ['2026-2-3', 'invalid'],
    '未来日' => [fn () => CarbonImmutable::tomorrow()->toDateString(), 'future'],
    '今日' => [fn () => CarbonImmutable::today()->toDateString(), null],
    '昨日' => [fn () => CarbonImmutable::yesterday()->toDateString(), null],
]);

test('自信度：閾値ちょうどは低くない、下回ると低い。欠落・範囲外・数字でないものは「確認不要」にしない', function (): void {
    $at = ['reading_confidence' => ['date' => 0.7, 'vendor' => 0.7, 'total' => 0.8]];
    $below = ['reading_confidence' => ['date' => 0.69, 'vendor' => 0.69, 'total' => 0.79]];

    expect(ReadingChecks::lowConfidenceFields($at))->toBe([])
        ->and(ReadingChecks::lowConfidenceFields($below))->toBe(['date', 'vendor', 'total'])
        ->and(ReadingChecks::unknownConfidenceFields($at))->toBe([]);

    expect(ReadingChecks::unknownConfidenceFields(['reading_confidence' => null]))->toBe(['date', 'vendor', 'total'])
        ->and(ReadingChecks::unknownConfidenceFields(['reading_confidence' => ['date' => 1.5, 'vendor' => -0.1, 'total' => 'high']]))->toBe(['date', 'vendor', 'total'])
        ->and(ReadingChecks::unknownConfidenceFields(['reading_confidence' => ['date' => 0, 'vendor' => 1]]))->toBe(['total'])
        ->and(ReadingChecks::unknownConfidenceFields(['reading_confidence' => ['date' => 0.9], 'amount_missing' => true]))->toBe(['vendor'])
        ->and(ReadingChecks::unknownConfidenceFields(null))->toBe([])
        ->and(ReadingChecks::unknownConfidenceFields(['remembered' => true]))->toBe([]);
});

test('欄の状態：読めた値・補った値・読めなかった値を分け、reading_review がない古い仕訳は何も求めない', function (): void {
    $review = ReadingReview::forDraft(['date' => '2026-02-30', 'total_yen' => null], null);
    $items = ReadingReview::items($review, ['reading_confidence' => ['date' => 0.99, 'vendor' => 0.5, 'total' => 0.9]]);

    expect($items['date']['origin'])->toBe('defaulted')
        ->and($items['date']['reasons'])->toBe(['date_invalid'])
        ->and($items['date']['needs_confirmation'])->toBeTrue()
        ->and($items['vendor']['origin'])->toBe('missing')
        ->and($items['vendor']['needs_confirmation'])->toBeFalse()
        ->and($items['amount']['reasons'])->toBe(['amount_missing']);

    expect(ReadingReview::items(null, ['reading_confidence' => null]))->toBe([]);
});

test('仮の日付：読めなかった日付は今日を「未確認の初期値」として出し、確かめるか直すまで保存しない', function (mixed $date, string $reason, string $label): void {
    app(FakeClaudeClient::class)->withReading(['date' => is_callable($date) ? $date() : $date]);
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $source = sendReceipt($company, $editor);
    /** @var JournalEntry $entry */
    $entry = $source->journalEntry()->firstOrFail();

    expect($entry->entry_date->toDateString())->toBe(now()->toDateString())
        ->and($entry->reading_review['date'] ?? null)->toBe(['origin' => 'defaulted', 'problem' => $reason]);

    $component = showReview($company, $editor, $source)
        ->assertSeeHtml('data-test="reading-item-date"')
        ->assertSee('未確認の初期値')
        ->assertSee($label)
        ->assertSeeHtml('data-test="confirm-date"')
        ->call('confirm')
        ->assertHasErrors('confirmDate');

    expect($entry->refresh()->status)->toBe(EntryStatus::Draft)
        ->and(ReadingConfirmation::count())->toBe(0);

    $component->set('confirmDate', true)->call('confirm')->assertHasNoErrors();

    $row = ReadingConfirmation::where('field', 'date')->sole();
    expect($entry->refresh()->status)->toBe(EntryStatus::Confirmed)
        ->and($row->method)->toBe('confirmed')
        ->and($row->value)->toBe(now()->toDateString())
        ->and($row->reasons)->toBe(['date_'.$reason])
        ->and($row->confirmed_by)->toBe($editor->id)
        ->and($row->company_id)->toBe($company->id)
        ->and($row->ai_run_id)->toBe($entry->ai_run_id)
        ->and($row->confirmed_at)->not->toBeNull();
})->with([
    '日付なし' => [null, 'missing', '日付が読めませんでした'],
    '形式不正（自信は高い）' => ['2026-02-30', 'invalid', '日付として読めませんでした'],
    '未来日' => [fn () => CarbonImmutable::tomorrow()->toDateString(), 'future', '未来の日付が読み取られました'],
]);

test('仮の日付：人が日付を直せば確認のチェックは要らず、「人が直した」と記録する', function (): void {
    app(FakeClaudeClient::class)->withReading(['date' => null]);
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $source = sendReceipt($company, $editor);
    $entry = $source->journalEntry()->firstOrFail();

    showReview($company, $editor, $source)
        ->set('entryDate', now()->subDays(3)->toDateString())
        ->set('reason', 'other')
        ->call('confirm')
        ->assertHasNoErrors();

    $row = ReadingConfirmation::where('field', 'date')->sole();
    expect($row->method)->toBe('changed')
        ->and($row->value)->toBe(now()->subDays(3)->toDateString())
        ->and($entry->refresh()->status)->toBe(EntryStatus::Confirmed);
});

test('読めた日付・高い自信度：確認を求めず、確認の記録も作らない', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $source = sendReceipt($company, $editor);

    showReview($company, $editor, $source)
        ->assertSee('AIが読んだ値')
        ->assertDontSeeHtml('data-test="confirm-date"')
        ->assertSee('AIの自信が高くても、読み違いはあります')
        ->call('confirm')
        ->assertHasNoErrors();

    expect(ReadingConfirmation::count())->toBe(0);
});

test('低い自信度：保存は止めず、注意が付いたまま保存したことを記録する。直せば「人が直した」', function (): void {
    app(FakeClaudeClient::class)->withReading(['confidence' => ['date' => 0.95, 'vendor' => 0.5, 'total' => 0.79]]);
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $source = sendReceipt($company, $editor);

    showReview($company, $editor, $source)
        ->assertSee('AIの読み取りの自信が低めです')
        ->set('amountYen', '3900')
        ->set('reason', 'misread')
        ->call('confirm')
        ->assertHasNoErrors();

    $rows = ReadingConfirmation::all()->keyBy('field');
    expect($rows->keys()->sort()->values()->all())->toBe(['amount', 'vendor'])
        ->and($rows['vendor']->method)->toBe('saved_as_is')
        ->and($rows['vendor']->reasons)->toBe(['low_confidence'])
        ->and($rows['amount']->method)->toBe('changed')
        ->and($rows['amount']->value)->toBe('3900');
});

test('自信度が範囲外・欠落でも「確認不要」と出さず、保存は止めない', function (): void {
    app(FakeClaudeClient::class)->withReading(['confidence' => ['date' => 1.5, 'vendor' => -0.2, 'total' => 0.97]]);
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $source = sendReceipt($company, $editor);

    showReview($company, $editor, $source)
        ->assertSee('AIの読み取りの自信が不明です')
        ->call('confirm')
        ->assertHasNoErrors();

    $rows = ReadingConfirmation::all()->keyBy('field');
    expect($rows->keys()->sort()->values()->all())->toBe(['date', 'vendor'])
        ->and($rows['date']->method)->toBe('saved_as_is')
        ->and($rows['date']->reasons)->toBe(['confidence_unknown']);
});

test('金額の記載なし：読めなかった欄として扱い、人が入れた金額は「人が入れた」と記録する（日付の確認は求めない）', function (): void {
    app(FakeClaudeClient::class)->withReading(['total_yen' => null, 'amount_missing' => true, 'payment_method' => 'invoice_later', 'tax_breakdown' => []]);
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $source = sendReceipt($company, $editor);

    showReview($company, $editor, $source)
        ->assertDontSeeHtml('data-test="confirm-date"')
        ->set('amountYen', '6,820')
        ->call('confirm')
        ->assertHasNoErrors();

    $row = ReadingConfirmation::where('field', 'amount')->sole();
    expect($row->method)->toBe('entered')->and($row->value)->toBe('6820')->and($row->reasons)->toBe(['amount_missing']);
});

test('AIが読めなかったとき：写真を見て手入力できる。日付の初期値は確かめるまで保存せず、入れた欄は「人が入れた」と記録する', function (): void {
    app(FakeClaudeClient::class)->failWith(AiTask::ReadReceipt, 'error');
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $source = sendReceipt($company, $editor);

    $component = showReview($company, $editor, $source)
        ->assertSeeHtml('data-test="confirm-date"')
        ->set('vendor', '△△商店')
        ->set('categoryId', categoryId($company, '部品・修理'))
        ->set('amountYen', '12000')
        ->call('confirm')
        ->assertHasErrors('confirmDate');

    expect($source->refresh()->journal_entry_id)->toBeNull()
        ->and(ReadingConfirmation::count())->toBe(0);

    $component->set('confirmDate', true)->call('confirm')->assertHasNoErrors();

    $rows = ReadingConfirmation::all()->keyBy('field');
    expect($source->refresh()->status)->toBe(SourceStatus::Confirmed)
        ->and($rows['date']->method)->toBe('confirmed')
        ->and($rows['vendor']->method)->toBe('entered')
        ->and($rows['amount']->method)->toBe('entered')
        ->and($rows->pluck('ai_run_id')->filter()->all())->toBe([]);
});

test('戻したあとの再確定：前の確認は使い回さず、もう一度確かめると新しい記録が増える', function (): void {
    app(FakeClaudeClient::class)->withReading(['date' => null]);
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    ['user' => $owner] = createCompanyWithMember(Role::Owner, company: $company);
    $source = sendReceipt($company, $editor);

    showReview($company, $editor, $source)->set('confirmDate', true)->call('confirm')->assertHasNoErrors();
    $entry = $source->refresh()->journalEntry()->firstOrFail();
    app(OwnerReviewService::class)->returnToBookkeeper($company, $owner, $entry->id, '日付を確かめて');

    $component = showReview($company, $editor, $source)->call('confirm')->assertHasErrors('confirmDate');
    expect($entry->refresh()->status)->toBe(EntryStatus::Returned)
        ->and(ReadingConfirmation::where('field', 'date')->count())->toBe(1);

    $component->set('confirmDate', true)->call('confirm')->assertHasNoErrors();

    expect($entry->refresh()->status)->toBe(EntryStatus::Confirmed)
        ->and(ReadingConfirmation::where('field', 'date')->count())->toBe(2);
});

test('二重送信：確定済みへの2回目の保存は、確認の記録を増やさない', function (): void {
    app(FakeClaudeClient::class)->withReading(['date' => null]);
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $source = sendReceipt($company, $editor);
    $entry = $source->journalEntry()->firstOrFail();
    $input = new ConfirmEntryInput(
        entryDate: $entry->entry_date->toDateString(),
        vendor: $entry->vendor,
        description: $entry->description,
        categoryId: (string) $entry->category_id,
        bankAccountId: (string) $entry->bank_account_id,
        amountYen: $entry->amountYen(),
        readingConfirmed: ['date'],
    );

    app(ConfirmEntryService::class)->confirm($company, $editor, $source->id, $input);
    app(ConfirmEntryService::class)->confirm($company, $editor, $source->id, $input);

    expect(ReadingConfirmation::count())->toBe(1);
});

test('後着のAI：手で確定したあとに遅れて動いても、読み取りの記録と確認は変わらない', function (): void {
    Queue::fake();
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $source = sendReceipt($company, $editor);

    showReview($company, $editor, $source)
        ->set('categoryId', categoryId($company, '部品・修理'))
        ->set('amountYen', '12000')
        ->set('confirmDate', true)
        ->call('confirm')
        ->assertHasNoErrors();

    $entry = $source->refresh()->journalEntry()->firstOrFail();
    $reviewBefore = $entry->reading_review;
    $rowsBefore = ReadingConfirmation::count();

    app()->call([new ProcessEvidenceWithAi($source->id), 'handle']);

    expect($entry->refresh()->reading_review)->toBe($reviewBefore)
        ->and($entry->ai_check)->toBeNull()
        ->and(ReadingConfirmation::count())->toBe($rowsBefore);
});

test('ライブ画面：仮の日付は S で保存できず、理由と「直して確定」への道を出す', function (): void {
    app(FakeClaudeClient::class)->withReading(['date' => '2026-02-30']);
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Owner);
    $source = sendReceipt($company, $editor);

    Livewire::actingAs($editor)
        ->test('pages::company.live', ['company' => $company])
        ->assertSeeHtml('data-test="live-reading-reason-date"')
        ->assertSee('日付として読めませんでした')
        ->call('save', $source->id)
        ->assertHasErrors('confirmDate')
        ->assertSeeHtml('data-test="live-confirm-date-error"')
        ->assertSee('直して確定の画面を開く');

    expect($source->refresh()->status)->toBe(SourceStatus::Drafted)
        ->and(ReadingConfirmation::count())->toBe(0);
});

test('権限：送るだけの人は確認の画面を開けず、他社の確認記録は見えない', function (): void {
    app(FakeClaudeClient::class)->withReading(['date' => null]);
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    ['user' => $worker] = createCompanyWithMember(Role::Editor, submitOnly: true, company: $company);
    ['company' => $other, 'user' => $otherEditor] = createCompanyWithMember(Role::Editor);
    $source = sendReceipt($company, $editor);

    $this->actingAs($worker)->get(route('company.sources.show', [$company, $source->id]))->assertForbidden();
    $this->actingAs($otherEditor)->get(route('company.sources.show', [$other, $source->id]))->assertNotFound();

    showReview($company, $editor, $source)->set('confirmDate', true)->call('confirm')->assertHasNoErrors();

    expect(ReadingConfirmation::where('company_id', $other->id)->count())->toBe(0)
        ->and(ReadingConfirmation::where('company_id', $company->id)->count())->toBe(1);
});
