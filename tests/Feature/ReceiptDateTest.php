<?php

use App\Enums\AiStatus;
use App\Enums\AiTask;
use App\Enums\CorrectionReason;
use App\Enums\EntryStatus;
use App\Enums\Role;
use App\Enums\SourceStatus;
use App\Models\AiRun;
use App\Services\Ai\FakeClaudeClient;
use App\Services\AiDraftBuilder;
use App\Services\WorkbookService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

beforeEach(function (): void {
    Storage::fake('evidence');
    $this->travelTo(now()->setDate(2026, 10, 25)->setTime(0, 30));
});

test('T36: 有効なAI日付は日本時間の今日以前なら原資料と下書きで一致する', function (string $date): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $fake = app(FakeClaudeClient::class);
    $fake->withReading(['date' => $date]);

    $source = sendReceipt($company, $editor);
    $entry = $source->journalEntry()->firstOrFail();

    expect($fake->requests[0]->input['today'])->toBe('2026-10-25')
        ->and($source->ai_status)->toBe(AiStatus::Done)
        ->and($source->occurred_on?->toDateString())->toBe($date)
        ->and($entry->entry_date->toDateString())->toBe($date)
        ->and($entry->status)->toBe(EntryStatus::Draft);
})->with(['今日' => '2026-10-25', '前日' => '2026-10-24', 'うるう日' => '2024-02-29', '400年周期のうるう日' => '2000-02-29']);

test('T36: 不正なAI日付の下書きを経理が直して確定すると原資料とExcelにも正しい日付が入る', function (?string $date): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    app(FakeClaudeClient::class)->withReading(['date' => $date]);
    $source = sendReceipt($company, $editor);

    Livewire::actingAs($editor)
        ->test('pages::company.sources.show', ['company' => $company, 'source' => $source->id])
        ->assertSet('entryDate', '2026-10-25')
        ->set('entryDate', '2026-10-24')
        ->set('reason', CorrectionReason::Misread->value)
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertRedirect(route('company.sources.index', $company));

    $entry = $source->refresh()->journalEntry()->firstOrFail();
    $sheet = IOFactory::load(Storage::disk('local')->path($company->workbook()->firstOrFail()->storage_key))
        ->getSheetByName(WorkbookService::JOURNAL_SHEET);
    expect($source->status)->toBe(SourceStatus::Confirmed)
        ->and($source->occurred_on?->toDateString())->toBe('2026-10-24')
        ->and($entry->entry_date->toDateString())->toBe('2026-10-24')
        ->and($entry->status)->toBe(EntryStatus::Confirmed)
        ->and($entry->confirmed_by)->toBe($editor->id)
        ->and($entry->workbook_row)->toBe(2)
        ->and($sheet?->getCell([2, 2])->getValue())->toBe(Date::PHPToExcel($entry->entry_date));
})->with(['和暦' => '令和8年10月24日', '不明' => null]);

test('T36: 日付を補正する下書き作成でも他社の原資料IDは拒否しデータを変えない', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $source = sendReceipt($company, $editor);
    $run = AiRun::query()->where('subject_id', $source->id)->where('task', AiTask::Journalize)->sole();
    ['company' => $other, 'user' => $otherUser] = createCompanyWithMember(Role::Editor);
    Queue::fake();
    $foreign = sendReceipt($other, $otherUser);
    $before = $foreign->getAttributes();
    $entryCount = $company->journalEntries()->count();

    expect(fn () => app(AiDraftBuilder::class)->build(
        $company,
        $foreign->id,
        ['date' => '令和8年10月24日', 'total_yen' => 3850],
        ['category' => '消耗品', 'vendor' => '架空の取引先'],
        $run,
    ))->toThrow(ModelNotFoundException::class);

    expect($foreign->refresh()->getAttributes())->toBe($before)
        ->and($other->journalEntries()->exists())->toBeFalse()
        ->and($company->journalEntries()->count())->toBe($entryCount);
});
