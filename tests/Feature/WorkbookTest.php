<?php

use App\Enums\CorrectionReason;
use App\Enums\EntryKind;
use App\Enums\EntryStatus;
use App\Enums\Role;
use App\Models\AiCorrection;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\SourceRecord;
use App\Models\User;
use App\Services\ConfirmEntryInput;
use App\Services\ConfirmEntryService;
use App\Services\JournalLineWriter;
use App\Services\OwnerReviewService;
use App\Services\WorkbookService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

beforeEach(function (): void {
    Storage::fake('evidence');
});

function confirmAsIs(Company $company, User $user, SourceRecord $source): JournalEntry
{
    /** @var JournalEntry $draft */
    $draft = $source->journalEntry()->firstOrFail();

    return app(ConfirmEntryService::class)->confirm($company, $user, $source->id, new ConfirmEntryInput(
        entryDate: $draft->entry_date->toDateString(),
        vendor: $draft->vendor,
        description: $draft->description,
        categoryId: (string) $draft->category_id,
        bankAccountId: (string) $draft->bank_account_id,
        amountYen: $draft->amountYen(),
    ));
}

function journalSheetFromBytes(string $bytes): Worksheet
{
    $path = tempnam(sys_get_temp_dir(), 'wbt');
    file_put_contents($path, $bytes);

    try {
        $sheet = IOFactory::createReader('Xlsx')->load($path)->getSheetByName(WorkbookService::JOURNAL_SHEET);
    } finally {
        unlink($path);
    }

    return $sheet ?? throw new RuntimeException('仕訳帳のシートがありません');
}

function journalSheet(Company $company): Worksheet
{
    return journalSheetFromBytes((string) Storage::disk('local')->get("workbooks/{$company->id}.xlsx"));
}

/**
 * @return list<string>
 */
function numbersInSheet(Worksheet $sheet): array
{
    $numbers = [];
    for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
        $value = (string) $sheet->getCell([1, $row])->getValue();
        if ($value !== '') {
            $numbers[] = $value;
        }
    }

    return $numbers;
}

function workbookEntryWithId(Company $company, string $id, int $amount): JournalEntry
{
    $category = $company->categories()->where('name', '消耗品')->firstOrFail();
    $bankAccount = $company->bankAccounts()->where('name', '現金')->firstOrFail();
    $entry = new JournalEntry;
    $entry->forceFill([
        'id' => $id,
        'company_id' => $company->id,
        'entry_date' => '2026-10-25',
        'kind' => EntryKind::Normal,
        'status' => EntryStatus::Confirmed,
        'category_id' => $category->id,
        'bank_account_id' => $bankAccount->id,
        'confirmed_at' => now(),
    ])->save();
    app(JournalLineWriter::class)->rewrite($entry, $category, $bankAccount, $amount);

    return $entry;
}

function replaceWorkbookNumber(Company $company, int $row, string $number): void
{
    $sheet = journalSheet($company);
    $sheet->setCellValue([1, $row], $number);
    $path = tempnam(sys_get_temp_dir(), 'wbt');

    try {
        (new Xlsx($sheet->getParentOrThrow()))->save($path);
        Storage::disk('local')->put($company->workbook()->firstOrFail()->storage_key, (string) file_get_contents($path));
    } finally {
        unlink($path);
    }
}

test('T43: 末尾8桁が同じ仕訳も別行に入り、行番号を失って再同期しても互いの行を上書きしない', function (bool $wrongRow): void {
    ['company' => $company] = createCompanyWithMember(Role::Editor);
    $first = workbookEntryWithId($company, '0199aaaa-0000-7000-8000-0000deadbeef', 1200);
    $second = workbookEntryWithId($company, '0199bbbb-0000-7000-8000-0000deadbeef', 9800);
    $workbooks = app(WorkbookService::class);

    $workbooks->upsertRow($company, $first->id);
    $second->forceFill(['workbook_row' => $wrongRow ? 2 : null])->save();
    $workbooks->upsertRow($company, $second->id);
    $first->forceFill(['workbook_row' => null])->save();
    $second->refresh()->forceFill(['workbook_row' => null])->save();
    $workbooks->syncMissing($company);
    $workbooks->upsertRow($company, $second->id);

    $sheet = journalSheet($company);
    expect(numbersInSheet($sheet))->toBe([strtoupper($first->id), strtoupper($second->id)])
        ->and($sheet->getCell([6, 2])->getValue())->toBe(1200)
        ->and($sheet->getCell([6, 3])->getValue())->toBe(9800)
        ->and($first->refresh()->workbook_row)->toBe(2)
        ->and($second->refresh()->workbook_row)->toBe(3)
        ->and($company->workbook()->firstOrFail()->next_row)->toBe(4);
})->with([false, true]);

test('T43: 旧8桁番号が会社内で一意なら同じ行をUUID全体に移行し、他社の同番号は影響しない', function (bool $rowKnown): void {
    ['company' => $company] = createCompanyWithMember();
    ['company' => $other] = createCompanyWithMember();
    $entry = workbookEntryWithId($company, '0199aaaa-0000-7000-8000-0000deadbeef', 1200);
    $foreign = workbookEntryWithId($other, '0199bbbb-0000-7000-8000-0000deadbeef', 9800);
    $workbooks = app(WorkbookService::class);
    $workbooks->upsertRow($company, $entry->id);
    $workbooks->upsertRow($other, $foreign->id);
    $foreignBytes = $workbooks->contents($other);
    replaceWorkbookNumber($company, 2, 'DEADBEEF');
    $entry->refresh()->forceFill(['workbook_row' => $rowKnown ? 2 : null])->save();

    $workbooks->upsertRow($company, $entry->id);

    expect(numbersInSheet(journalSheet($company)))->toBe([strtoupper($entry->id)])
        ->and($entry->refresh()->workbook_row)->toBe(2)
        ->and($company->workbook()->firstOrFail()->next_row)->toBe(3)
        ->and($workbooks->contents($other))->toBe($foreignBytes);
})->with([false, true]);

test('T43: 衝突する旧8桁番号は行番号が残っていても上書きせず、別行へ保存する', function (): void {
    ['company' => $company] = createCompanyWithMember();
    $first = workbookEntryWithId($company, '0199aaaa-0000-7000-8000-0000deadbeef', 1200);
    $workbooks = app(WorkbookService::class);
    $workbooks->upsertRow($company, $first->id);
    replaceWorkbookNumber($company, 2, 'DEADBEEF');
    $second = workbookEntryWithId($company, '0199bbbb-0000-7000-8000-0000deadbeef', 9800);

    $workbooks->upsertRow($company, $second->id);
    $workbooks->upsertRow($company, $first->id);
    $workbooks->upsertRow($company, $first->id);

    $sheet = journalSheet($company);
    expect(numbersInSheet($sheet))->toBe(['DEADBEEF', strtoupper($second->id), strtoupper($first->id)])
        ->and($sheet->getCell([6, 2])->getValue())->toBe(1200)
        ->and($sheet->getCell([6, 3])->getValue())->toBe(9800)
        ->and($sheet->getCell([6, 4])->getValue())->toBe(1200)
        ->and($first->refresh()->workbook_row)->toBe(4)
        ->and($second->refresh()->workbook_row)->toBe(3)
        ->and($company->workbook()->firstOrFail()->next_row)->toBe(5);
});

test('T43: 別会社の仕訳IDではExcelを更新できず、両社のファイルと行番号が変わらない', function (): void {
    ['company' => $company] = createCompanyWithMember();
    ['company' => $other] = createCompanyWithMember();
    $entry = workbookEntryWithId($company, '0199aaaa-0000-7000-8000-0000deadbeef', 1200);
    $foreign = workbookEntryWithId($other, '0199bbbb-0000-7000-8000-0000deadbeef', 9800);
    $workbooks = app(WorkbookService::class);
    $workbooks->upsertRow($company, $entry->id);
    $workbooks->upsertRow($other, $foreign->id);
    $bytes = $workbooks->contents($company);
    $foreignBytes = $workbooks->contents($other);
    $version = $company->workbook()->firstOrFail()->version;
    $foreignVersion = $other->workbook()->firstOrFail()->version;

    expect(fn () => $workbooks->upsertRow($company, $foreign->id))->toThrow(ModelNotFoundException::class);

    expect($workbooks->contents($company))->toBe($bytes)
        ->and($workbooks->contents($other))->toBe($foreignBytes)
        ->and($entry->refresh()->workbook_row)->toBe(2)
        ->and($foreign->refresh()->workbook_row)->toBe(2)
        ->and($company->workbook()->firstOrFail()->version)->toBe($version)
        ->and($other->workbook()->firstOrFail()->version)->toBe($foreignVersion);
});

test('T14: 確定すると Excel の仕訳帳に1行入り、2回保存しても行は増えない。次の確定は次の行', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);

    $first = confirmAsIs($company, $editor, sendReceipt($company, $editor));
    confirmAsIs($company, $editor, $first->sources()->firstOrFail());
    $second = confirmAsIs($company, $editor, sendReceipt($company, $editor, 410));

    $sheet = journalSheet($company);

    expect($sheet->getCell([1, 1])->getValue())->toBe('管理番号')
        ->and($sheet->getCell([10, 1])->getValue())->toBe('状態')
        ->and(numbersInSheet($sheet))->toBe([WorkbookService::numberFor($first), WorkbookService::numberFor($second)])
        ->and($first->workbook_row)->toBe(2)
        ->and($second->workbook_row)->toBe(3)
        ->and($sheet->getCell([3, 2])->getValue())->toBe('〇〇石油 八幡平店')
        ->and($sheet->getCell([4, 2])->getValue())->toBe('消耗品')
        ->and($sheet->getCell([6, 2])->getValue())->toBe(3850)
        ->and($sheet->getCell([7, 2])->getValue())->toBe('現金')
        ->and($sheet->getCell([8, 2])->getValue())->toBe(3850)
        ->and($sheet->getCell([10, 2])->getValue())->toBe('確定')
        ->and($sheet->getCell([11, 2])->getValue())->toBe($editor->name)
        ->and($sheet->getCell([13, 2])->getValue())->toBe('AIのまま')
        ->and($sheet->getStyle([6, 2])->getNumberFormat()->getFormatCode())->toBe('#,##0')
        ->and($company->workbook()->firstOrFail()->next_row)->toBe(4);
});

test('T14: 社長から戻すと同じ行の状態が「社長から戻し」に、経理が再確定すると同じ行が上書きされる', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    ['user' => $owner] = createCompanyWithMember(Role::Owner, company: $company);
    $source = sendReceipt($company, $editor);
    $entry = confirmAsIs($company, $editor, $source);
    confirmAsIs($company, $editor, sendReceipt($company, $editor, 410));

    app(OwnerReviewService::class)->returnToBookkeeper($company, $owner, $entry->id, '金額を確かめて');

    $sheet = journalSheet($company);
    expect($entry->refresh()->status)->toBe(EntryStatus::Returned)
        ->and($sheet->getCell([10, 2])->getValue())->toBe('社長から戻し')
        ->and(count(numbersInSheet($sheet)))->toBe(2);

    app(ConfirmEntryService::class)->confirm($company, $editor, $source->id, new ConfirmEntryInput(
        entryDate: $entry->entry_date->toDateString(),
        vendor: $entry->vendor,
        description: $entry->description,
        categoryId: (string) $entry->category_id,
        bankAccountId: (string) $entry->bank_account_id,
        amountYen: 4000,
        reason: CorrectionReason::Misread,
    ));

    $sheet = journalSheet($company);
    expect($sheet->getCell([1, 2])->getValue())->toBe(WorkbookService::numberFor($entry))
        ->and($sheet->getCell([10, 2])->getValue())->toBe('確定')
        ->and($sheet->getCell([6, 2])->getValue())->toBe(4000)
        ->and($sheet->getCell([13, 2])->getValue())->toBe('直した')
        ->and(count(numbersInSheet($sheet)))->toBe(2)
        ->and($entry->refresh()->workbook_row)->toBe(2)
        ->and(AiCorrection::query()->where('field', 'amount')->value('kernel'))->toBeNull();
});

test('書けなかった行はダウンロードの前に足され、ファイルにだけ入っていた行は二重にならない', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    ['user' => $viewer] = createCompanyWithMember(Role::Viewer, company: $company);

    $written = confirmAsIs($company, $editor, sendReceipt($company, $editor));
    $missing = confirmAsIs($company, $editor, sendReceipt($company, $editor, 410));

    // 1件目：ファイルには入ったが、行番号を残す前に止まった
    $written->forceFill(['workbook_row' => null])->save();
    // 2件目：ファイルに書く前に止まった
    $missing->forceFill(['workbook_row' => null])->save();
    $workbook = $company->workbook()->firstOrFail();
    $workbook->forceFill(['next_row' => 3])->save();
    $sheet = journalSheet($company);
    $sheet->removeRow(3);
    $writer = new Xlsx($sheet->getParentOrThrow());
    $path = tempnam(sys_get_temp_dir(), 'wbt');
    $writer->save($path);
    Storage::disk('local')->put($workbook->storage_key, (string) file_get_contents($path));
    unlink($path);

    $response = $this->actingAs($viewer)->get(route('company.workbook', $company));
    $response->assertOk();
    expect((string) $response->headers->get('Content-Disposition'))->toContain("filename*=utf-8''".rawurlencode('テスト建機_帳簿.xlsx'));

    $downloaded = journalSheetFromBytes((string) $response->getContent());
    expect(numbersInSheet($downloaded))->toBe([WorkbookService::numberFor($written), WorkbookService::numberFor($missing)])
        ->and($written->refresh()->workbook_row)->toBe(2)
        ->and($missing->refresh()->workbook_row)->toBe(3);
});

test('Excel のファイルがなくなっても、ダウンロードのときに確定の仕訳がすべて入り直す', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $first = confirmAsIs($company, $editor, sendReceipt($company, $editor));
    $second = confirmAsIs($company, $editor, sendReceipt($company, $editor, 410));

    Storage::disk('local')->delete("workbooks/{$company->id}.xlsx");

    $response = $this->actingAs($editor)->get(route('company.workbook', $company))->assertOk();

    expect(numbersInSheet(journalSheetFromBytes((string) $response->getContent())))
        ->toBe([WorkbookService::numberFor($first), WorkbookService::numberFor($second)])
        ->and($company->workbook()->firstOrFail()->next_row)->toBe(4);
});

test('確定がまだなくても Excel はダウンロードでき、見出しだけが入っている', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);

    $response = $this->actingAs($owner)->get(route('company.workbook', $company));
    $response->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $sheet = journalSheetFromBytes((string) $response->getContent());
    expect($sheet->getCell([1, 1])->getValue())->toBe('管理番号')
        ->and(numbersInSheet($sheet))->toBe([]);
});

test('Excel：送るだけの人は403、他社の Excel は404、下書きは入らない', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    ['user' => $submitter] = createCompanyWithMember(Role::Editor, submitOnly: true, company: $company);
    ['company' => $other] = createCompanyWithMember(Role::Owner);

    sendReceipt($company, $editor);

    $this->actingAs($submitter)->get(route('company.workbook', $company))->assertForbidden();
    $this->actingAs($editor)->get(route('company.workbook', $other))->assertNotFound();

    $response = $this->actingAs($editor)->get(route('company.workbook', $company))->assertOk();
    expect(numbersInSheet(journalSheetFromBytes((string) $response->getContent())))->toBe([])
        ->and($other->workbook()->exists())->toBeFalse();
});
