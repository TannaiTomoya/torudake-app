<?php

use App\Enums\AiTask;
use App\Enums\ConcernFlag;
use App\Enums\CorrectionReason;
use App\Enums\EntryStatus;
use App\Enums\Role;
use App\Models\AiRun;
use App\Models\AuditLog;
use App\Services\AiRecordSummary;
use App\Services\ConfirmEntryInput;
use App\Services\ConfirmEntryService;
use App\Services\OwnerReviewService;
use App\Services\PaymentMemory;
use App\Services\TrainingDataExporter;
use App\Services\WorkbookService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function (): void {
    Storage::fake('evidence');
    $this->freezeTime();
});

test('T12/T17: 再確定の変更はAI修正に混ぜず、初回の記録・印・支払い元の記憶を保つ', function (bool $initialCorrection, bool $staleReason): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    ['user' => $owner] = createCompanyWithMember(Role::Owner, company: $company);
    $source = sendReceipt($company, $editor);
    $firstCategory = $initialCorrection ? '部品・修理' : '消耗品';
    $firstPayment = $initialCorrection ? '社長の立替' : '現金';

    Livewire::actingAs($editor)
        ->test('pages::company.sources.show', ['company' => $company, 'source' => $source->id])
        ->set('categoryId', categoryId($company, $firstCategory))
        ->set('bankAccountId', bankAccountId($company, $firstPayment))
        ->set('reason', $initialCorrection ? CorrectionReason::WrongCategory->value : '')
        ->call('confirm')->assertHasNoErrors();

    $entry = $source->refresh()->journalEntry()->firstOrFail();
    $corrections = $company->aiCorrections()->orderBy('id')->get()->map->getAttributes()->all();
    expect($corrections)->toHaveCount($initialCorrection ? 2 : 0);
    $originalRow = $entry->workbook_row;
    $vendor = $entry->vendor;
    $originalDate = $entry->entry_date->toDateString();
    $nextDate = now()->subDay()->toDateString();
    app(OwnerReviewService::class)->returnToBookkeeper($company, $owner, $entry->id, '日付・分類・支払い元・金額を確認してください');
    $this->travel(1)->seconds();

    Livewire::actingAs($editor)
        ->test('pages::company.sources.show', ['company' => $company, 'source' => $source->id])
        ->assertDontSee('data-test="correction-reason"', false)
        ->set('entryDate', $nextDate)
        ->set('categoryId', categoryId($company, '燃料'))
        ->set('bankAccountId', bankAccountId($company, '事業用カード'))
        ->set('amountYen', '5000')
        ->set('reason', $staleReason ? CorrectionReason::Misread->value : '')
        ->call('confirm')->assertHasNoErrors();

    $entry->refresh();
    $sheet = IOFactory::load(Storage::disk('local')->path($company->workbook()->firstOrFail()->storage_key))
        ->getSheetByName(WorkbookService::JOURNAL_SHEET);
    expect($company->aiCorrections()->orderBy('id')->get()->map->getAttributes()->all())->toBe($corrections)
        ->and($entry->status)->toBe(EntryStatus::Confirmed)
        ->and(in_array(ConcernFlag::Corrected->value, $entry->concern_flags ?? [], true))->toBe($initialCorrection)
        ->and($entry->entry_date->toDateString())->toBe($nextDate)
        ->and($entry->category?->name)->toBe('燃料')
        ->and($entry->bankAccount?->name)->toBe('事業用カード')
        ->and($entry->amountYen())->toBe(5000)
        ->and($entry->workbook_row)->toBe($originalRow)
        ->and($source->refresh()->amount_yen)->toBe(5000)
        ->and($sheet?->getCell([6, $originalRow])->getValue())->toBe(5000)
        ->and($sheet?->getCell([13, $originalRow])->getValue())->toBe('直した');

    $log = AuditLog::query()->where('subject_id', $entry->id)->where('action', 'entry.confirm')->latest('id')->firstOrFail();
    expect($log->before)->toEqual(['date' => $originalDate, 'vendor' => $vendor, 'category' => $firstCategory, 'credit_account' => $firstPayment, 'amount' => 3850])
        ->and($log->after['category'])->toBe('燃料')
        ->and($log->after['credit_account'])->toBe('事業用カード')
        ->and($log->after['amount'])->toBe(5000)
        ->and($log->after['date'])->toBe($nextDate)
        ->and($log->user_id)->toBe($editor->id);

    $summary = app(AiRecordSummary::class)->for($company, CarbonImmutable::instance(now()));
    expect($summary['corrections'])->toBe(count($corrections))
        ->and($summary['unchanged'])->toBe($initialCorrection ? 0 : 1)
        ->and(app(PaymentMemory::class)->for($company, $vendor)?->name)->toBe($initialCorrection ? '社長の立替' : null);

    $export = app(TrainingDataExporter::class)->export($company, CarbonImmutable::instance(now()));
    $path = tempnam(sys_get_temp_dir(), 'reconfirmed-training-');
    file_put_contents($path, $export['contents']);
    $zip = new ZipArchive;
    try {
        expect($zip->open($path))->toBeTrue();
        $jsonl = (string) $zip->getFromName('ai_corrections.jsonl');
        $exported = array_map(fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), array_filter(explode("\n", $jsonl)));
        expect(array_column($exported, 'id'))->toEqualCanonicalizing(array_column($corrections, 'id'));
    } finally {
        $zip->close();
        unlink($path);
    }

    $next = sendReceipt($company, $editor, 410);
    $input = AiRun::query()->where('subject_id', $next->id)->where('task', AiTask::Journalize)->sole()->input;
    $examples = collect($input['corrections'])->map(fn (array $row): array => ['field' => $row['field'], 'before' => $row['before'], 'after' => $row['after']])->all();
    $expected = array_map(fn (array $row): array => ['field' => $row['field'], 'before' => $row['before'], 'after' => $row['after']], $corrections);
    expect($next->journalEntry?->bankAccount?->name)->toBe($firstPayment)
        ->and($examples)->toEqualCanonicalizing($expected);
})->with([
    '初回そのまま・理由なし' => [false, false],
    '初回そのまま・古い理由あり' => [false, true],
    '初回AI修正あり・理由なし' => [true, false],
    '初回AI修正あり・古い理由あり' => [true, true],
]);

test('T17: 再確定画面を開いた後に閲覧ロールへ変わると保存できず、仕訳とAI修正と監査が変わらない', function (): void {
    ['company' => $company, 'user' => $editor, 'membership' => $membership] = createCompanyWithMember(Role::Editor);
    ['user' => $owner] = createCompanyWithMember(Role::Owner, company: $company);
    $source = sendReceipt($company, $editor);
    $component = Livewire::actingAs($editor)->test('pages::company.sources.show', ['company' => $company, 'source' => $source->id]);
    $component->call('confirm')->assertHasNoErrors();
    $entry = $source->refresh()->journalEntry()->firstOrFail();
    app(OwnerReviewService::class)->returnToBookkeeper($company, $owner, $entry->id, 'もう一度確認してください');
    $component = Livewire::actingAs($editor)->test('pages::company.sources.show', ['company' => $company, 'source' => $source->id]);
    $before = $entry->refresh()->getAttributes();
    $auditCount = AuditLog::query()->count();
    $membership->forceFill(['role' => Role::Viewer])->save();

    $component->set('amountYen', '5000')->call('confirm')->assertForbidden();

    expect($entry->refresh()->getAttributes())->toBe($before)
        ->and($company->aiCorrections()->exists())->toBeFalse()
        ->and(AuditLog::query()->count())->toBe($auditCount);
});

test('T17: 他社の戻された原資料IDは再確定できず、仕訳とAI修正と監査が変わらない', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    ['company' => $other, 'user' => $otherEditor] = createCompanyWithMember(Role::Editor);
    ['user' => $otherOwner] = createCompanyWithMember(Role::Owner, company: $other);
    $foreign = sendReceipt($other, $otherEditor);
    Livewire::actingAs($otherEditor)->test('pages::company.sources.show', ['company' => $other, 'source' => $foreign->id])
        ->call('confirm')->assertHasNoErrors();
    $entry = $foreign->refresh()->journalEntry()->firstOrFail();
    app(OwnerReviewService::class)->returnToBookkeeper($other, $otherOwner, $entry->id, '確認してください');
    $before = $entry->refresh()->getAttributes();
    $sourceBefore = $foreign->refresh()->getAttributes();
    $auditCount = AuditLog::query()->count();

    expect(fn () => app(ConfirmEntryService::class)->confirm($company, $editor, $foreign->id, new ConfirmEntryInput(
        entryDate: now()->toDateString(), vendor: '架空の取引先', description: null,
        categoryId: categoryId($company, '燃料'), bankAccountId: bankAccountId($company, '現金'), amountYen: 5000,
    )))->toThrow(ModelNotFoundException::class);

    expect($entry->refresh()->getAttributes())->toBe($before)
        ->and($foreign->refresh()->getAttributes())->toBe($sourceBefore)
        ->and($company->aiCorrections()->exists())->toBeFalse()
        ->and($other->aiCorrections()->exists())->toBeFalse()
        ->and(AuditLog::query()->count())->toBe($auditCount);
});

test('T12/T17: AIの下書きがない手入力仕訳の再確定もAI修正の印を付けない', function (): void {
    Queue::fake();
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    ['user' => $owner] = createCompanyWithMember(Role::Owner, company: $company);
    $source = sendReceipt($company, $editor);
    Livewire::actingAs($editor)->test('pages::company.sources.show', ['company' => $company, 'source' => $source->id])
        ->set('categoryId', categoryId($company, '消耗品'))->set('amountYen', '1200')
        ->call('confirm')->assertHasNoErrors();
    $entry = $source->refresh()->journalEntry()->firstOrFail();
    app(OwnerReviewService::class)->returnToBookkeeper($company, $owner, $entry->id, '金額を確認してください');

    Livewire::actingAs($editor)->test('pages::company.sources.show', ['company' => $company, 'source' => $source->id])
        ->set('categoryId', categoryId($company, '燃料'))->set('amountYen', '1500')
        ->call('confirm')->assertHasNoErrors();

    expect($entry->refresh()->ai_run_id)->toBeNull()
        ->and($entry->concern_flags)->not->toContain(ConcernFlag::Corrected->value)
        ->and($company->aiCorrections()->exists())->toBeFalse();
});

test('T14: 他社・別仕訳の変更監査はExcelの直した判定に使わない', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    ['company' => $other] = createCompanyWithMember();
    $source = sendReceipt($company, $editor);
    Livewire::actingAs($editor)->test('pages::company.sources.show', ['company' => $company, 'source' => $source->id])
        ->call('confirm')->assertHasNoErrors();
    $entry = $source->refresh()->journalEntry()->firstOrFail();
    $anotherEntry = putEntry($company, now()->toDateString(), '消耗品', 1200);

    // 会社の異なる監査と、同じ会社の別仕訳の監査を混ぜても、この行には使わない。
    AuditLog::record($editor, 'entry.confirm', $entry, after: ['corrected' => ['amount']], company: $other);
    AuditLog::record($editor, 'entry.confirm', $anotherEntry, after: ['corrected' => ['amount']], company: $company);
    app(WorkbookService::class)->upsertRow($company, $entry->id);

    $sheet = IOFactory::load(Storage::disk('local')->path($company->workbook()->firstOrFail()->storage_key))
        ->getSheetByName(WorkbookService::JOURNAL_SHEET);
    expect($sheet?->getCell([13, $entry->workbook_row])->getValue())->toBe('AIのまま');
});

test('T17: ライブ画面でも再確定にはAI修正理由を表示せず保存できる', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    $source = sendReceipt($company, $owner);
    Livewire::actingAs($owner)->test('pages::company.live', ['company' => $company])
        ->call('startEdit', $source->id)->assertSee('aria-label="直した理由"', false)
        ->call('save', $source->id)->assertHasNoErrors();
    $entry = $source->refresh()->journalEntry()->firstOrFail();
    app(OwnerReviewService::class)->returnToBookkeeper($company, $owner, $entry->id, '分類を確認してください');

    Livewire::actingAs($owner)->test('pages::company.live', ['company' => $company])
        ->call('startEdit', $source->id)->assertDontSee('aria-label="直した理由"', false)
        ->set('categoryId', categoryId($company, '燃料'))->call('save', $source->id)->assertHasNoErrors();

    expect($entry->refresh()->status)->toBe(EntryStatus::Confirmed)
        ->and($entry->concern_flags)->not->toContain(ConcernFlag::Corrected->value)
        ->and($company->aiCorrections()->exists())->toBeFalse();
});
