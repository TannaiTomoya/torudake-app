<?php

use App\Enums\EntryKind;
use App\Enums\EntryStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\OpeningEntryService;
use App\Services\OwnerReviewService;
use App\Services\TrainingDataExporter;
use App\Services\WorkbookService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;

function openingAccountId(Company $company, string $code): string
{
    return (string) $company->accounts()->where('code', $code)->value('id');
}

/**
 * @return list<array{account_id: string, side: string, amount: int}>
 */
function openingLines(Company $company, int $bank = 1000000, int $capital = 600000, int $retained = 400000): array
{
    return [
        ['account_id' => openingAccountId($company, '102'), 'side' => 'debit', 'amount' => $bank],
        ['account_id' => openingAccountId($company, '301'), 'side' => 'credit', 'amount' => $capital],
        ['account_id' => openingAccountId($company, '302'), 'side' => 'credit', 'amount' => $retained],
    ];
}

function saveOpening(Company $company, User $user, array $lines): JournalEntry
{
    return app(OpeningEntryService::class)->save($company, $user, $lines);
}

test('T52 画面から入れると、期首の日付で確定の開始仕訳が1件でき、監査に行が残る', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);

    Livewire::actingAs($user)->test('pages::company.opening', ['company' => $company])
        ->assertSee('2026年8月1日(土)')
        ->set('lines', [
            ['account_id' => openingAccountId($company, '102'), 'side' => 'debit', 'amount' => '1,000,000'],
            ['account_id' => openingAccountId($company, '301'), 'side' => 'credit', 'amount' => '600000'],
            ['account_id' => openingAccountId($company, '302'), 'side' => 'credit', 'amount' => '400000円'],
            ['account_id' => '', 'side' => 'debit', 'amount' => ''],
        ])
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('開始仕訳を保存しました。');

    $entry = $company->openingEntries()->sole();
    expect($entry->kind)->toBe(EntryKind::Opening)
        ->and($entry->status)->toBe(EntryStatus::Confirmed)
        ->and($entry->entry_date->toDateString())->toBe('2026-08-01')
        ->and($entry->confirmed_by)->toBe($user->id)
        ->and($entry->lines()->count())->toBe(3);

    $log = AuditLog::query()->where('action', 'opening_entry.enter')->sole();
    expect($log->after['lines'])->toEqual([
        ['account_code' => '102', 'side' => 'debit', 'amount_yen' => 1000000],
        ['account_code' => '301', 'side' => 'credit', 'amount_yen' => 600000],
        ['account_code' => '302', 'side' => 'credit', 'amount_yen' => 400000],
    ]);
});

test('T52 2回目は同じ1件を直し、前後の行を監査に残す。同じ中身なら記録しない', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Owner);
    $first = saveOpening($company, $user, openingLines($company));

    $second = saveOpening($company, $user, openingLines($company, 1200000, 600000, 600000));
    saveOpening($company, $user, openingLines($company, 1200000, 600000, 600000));

    expect($second->id)->toBe($first->id)
        ->and($company->openingEntries()->count())->toBe(1)
        ->and($second->entry_date->toDateString())->toBe('2026-08-01');

    $update = AuditLog::query()->where('action', 'opening_entry.update')->sole();
    expect($update->before['lines'][0]['amount_yen'])->toBe(1000000)
        ->and($update->after['lines'][0]['amount_yen'])->toBe(1200000);
});

test('T52 借方と貸方が違う・0円・使っていない科目は入れられない', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);

    expect(fn () => saveOpening($company, $user, openingLines($company, 1000000, 600000, 300000)))->toThrow(ValidationException::class);
    expect(fn () => saveOpening($company, $user, openingLines($company, 0, 0, 0)))->toThrow(ValidationException::class);
    expect(fn () => saveOpening($company, $user, []))->toThrow(ValidationException::class);

    $company->accounts()->where('code', '302')->sole()->forceFill(['archived_at' => now()])->save();
    expect(fn () => saveOpening($company, $user, openingLines($company)))->toThrow(ValidationException::class);

    Livewire::actingAs($user)->test('pages::company.opening', ['company' => $company])
        ->set('lines', [['account_id' => openingAccountId($company, '102'), 'side' => 'debit', 'amount' => '12a']])
        ->call('save')
        ->assertHasErrors('lines.0.amount');

    expect($company->openingEntries()->count())->toBe(0);
});

test('T52 収益・費用の科目は入れられず、画面の選択肢にも出ない', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);
    $lines = openingLines($company);
    $lines[2]['account_id'] = openingAccountId($company, '401');

    expect(fn () => saveOpening($company, $user, $lines))->toThrow(ValidationException::class);
    Livewire::actingAs($user)->test('pages::company.opening', ['company' => $company])
        ->assertSee('302 繰越利益剰余金')
        ->assertDontSee('401 売上高')
        ->assertDontSee('505 消耗品費');
});

test('T52 入れたあとで使わなくした科目も、開始仕訳にあれば直せる', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);
    saveOpening($company, $user, openingLines($company));
    $company->accounts()->where('code', '302')->sole()->forceFill(['archived_at' => now()])->save();

    saveOpening($company, $user, openingLines($company, 1100000, 600000, 500000));

    expect($company->openingEntries()->sole()->lines()->sum('amount_yen'))->toEqual(2200000);
    Livewire::actingAs($user)->test('pages::company.opening', ['company' => $company])->assertSee('302 繰越利益剰余金');
});

test('T52 他社の科目は404、他社の画面も404', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);
    ['company' => $other] = createCompanyWithMember(Role::Editor);
    $lines = openingLines($company);
    $lines[0]['account_id'] = openingAccountId($other, '102');

    expect(fn () => saveOpening($company, $user, $lines))->toThrow(ModelNotFoundException::class);
    $this->actingAs($user)->get(route('company.opening', $other))->assertNotFound();
    expect(JournalEntry::query()->where('kind', EntryKind::Opening->value)->count())->toBe(0);
});

test('T52 開始仕訳を入れる・直すのは owner・editor だけ（admin も403）', function (Role $role, bool $submitOnly, int $status): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember($role, $submitOnly);

    $this->actingAs($user)->get(route('company.opening', $company))->assertStatus($status);
})->with([
    'owner' => [Role::Owner, false, 200],
    'editor' => [Role::Editor, false, 200],
    'admin' => [Role::Admin, false, 403],
    'approver' => [Role::Approver, false, 403],
    'viewer' => [Role::Viewer, false, 403],
    '送るだけ' => [Role::Editor, true, 403],
]);

test('T52 画面を開いたあとで admin になったら、保存は403', function (): void {
    ['company' => $company, 'user' => $user, 'membership' => $membership] = createCompanyWithMember(Role::Editor);
    $page = Livewire::actingAs($user)->test('pages::company.opening', ['company' => $company])
        ->set('lines', array_map(fn (array $line): array => [...$line, 'amount' => (string) $line['amount']], openingLines($company)));

    $membership->forceFill(['role' => Role::Admin])->save();

    $page->call('save')->assertForbidden();
    expect($company->openingEntries()->count())->toBe(0);
});

test('T52 開始仕訳は仕訳の一覧・詳細・戻す・帳簿の Excel・分析用の書き出しに出ない', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Owner);
    $entry = saveOpening($company, $user, openingLines($company));

    expect($company->journalEntries()->count())->toBe(0);

    $this->actingAs($user)->get(route('company.entries.index', $company))->assertOk()->assertDontSee('開始仕訳');
    $this->actingAs($user)->get(route('company.entries.show', [$company, $entry->id]))->assertNotFound();
    expect(fn () => app(OwnerReviewService::class)->returnToBookkeeper($company, $user, $entry->id, '違う'))->toThrow(ModelNotFoundException::class);

    $path = tempnam(sys_get_temp_dir(), 'wb-test');
    file_put_contents($path, app(WorkbookService::class)->contents($company));
    try {
        $journal = IOFactory::load($path)->getSheetByName(WorkbookService::JOURNAL_SHEET);
        expect($journal?->getHighestDataRow())->toBeLessThanOrEqual(1);
    } finally {
        @unlink($path);
    }

    $export = app(TrainingDataExporter::class)->export($company, CarbonImmutable::now()->startOfMonth());
    expect($export['counts']['journal_entries'])->toBe(0);
    expect($entry->refresh()->status)->toBe(EntryStatus::Confirmed);
});
