<?php

use App\Enums\AiTask;
use App\Enums\ConcernFlag;
use App\Enums\CorrectionReason;
use App\Enums\EntryStatus;
use App\Enums\Role;
use App\Enums\Side;
use App\Enums\SourceStatus;
use App\Models\AiCorrection;
use App\Models\AuditLog;
use App\Models\JournalEntry;
use App\Services\Ai\FakeClaudeClient;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('evidence');
});

test('T12: 経理が分類を直して保存すると確定になり、直した記録に前後・理由・間違いの型が残る', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $source = sendReceipt($company, $editor);

    Livewire::actingAs($editor)
        ->test('pages::company.sources.show', ['company' => $company, 'source' => $source->id])
        ->assertSet('amountYen', '3850')
        ->set('categoryId', categoryId($company, '燃料'))
        ->set('reason', CorrectionReason::WrongCategory->value)
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertRedirect();

    /** @var JournalEntry $entry */
    $entry = $source->refresh()->journalEntry()->with('lines.account')->firstOrFail();

    expect($entry->status)->toBe(EntryStatus::Confirmed)
        ->and($entry->confirmed_by)->toBe($editor->id)
        ->and($entry->confirmed_at)->not->toBeNull()
        ->and($entry->lines[0]->side)->toBe(Side::Debit)
        ->and($entry->lines[0]->account?->name)->toBe('車両費')
        ->and($entry->lines[1]->account?->name)->toBe('現金')
        ->and($entry->concern_flags)->toContain(ConcernFlag::Corrected->value)
        ->and($source->status)->toBe(SourceStatus::Confirmed);

    $correction = AiCorrection::where('journal_entry_id', $entry->id)->sole();
    expect($correction->field)->toBe('category')
        ->and($correction->before)->toBe('消耗品')
        ->and($correction->after)->toBe('燃料')
        ->and($correction->reason)->toBe(CorrectionReason::WrongCategory)
        ->and($correction->kernel)->toBe('vendor:〇〇石油 八幡平店 → category:燃料')
        ->and($correction->corrected_by)->toBe($editor->id);

    expect(AuditLog::where('action', 'entry.confirm')->where('subject_id', $entry->id)->exists())->toBeTrue();

    // 次に同じ店のレシートが来ると、AI は直した例を覚えている
    $next = sendReceipt($company, $editor, 410);
    expect($next->journalEntry()->firstOrFail()->ai_check['remembered'] ?? null)->toBeTrue();
});

test('T12: AI の下書きを直したのに理由がなければ確定しない', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $source = sendReceipt($company, $editor);

    Livewire::actingAs($editor)
        ->test('pages::company.sources.show', ['company' => $company, 'source' => $source->id])
        ->set('categoryId', categoryId($company, '燃料'))
        ->call('confirm')
        ->assertHasErrors('reason');

    expect($source->refresh()->journalEntry?->status)->toBe(EntryStatus::Draft)
        ->and(AiCorrection::count())->toBe(0);
});

test('T13: 2回保存しても確定は1回だけ。AI だけでは確定にならない', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $source = sendReceipt($company, $editor);

    expect($source->journalEntry?->status)->toBe(EntryStatus::Draft);

    $component = Livewire::actingAs($editor)
        ->test('pages::company.sources.show', ['company' => $company, 'source' => $source->id]);

    $component->call('confirm')->assertHasNoErrors();
    $entry = $source->refresh()->journalEntry()->firstOrFail();
    $confirmedAt = $entry->confirmed_at;

    $this->travel(5)->minutes();
    $component->call('confirm')->assertHasNoErrors();

    expect($entry->refresh()->confirmed_at?->equalTo($confirmedAt))->toBeTrue()
        ->and(AuditLog::where('action', 'entry.confirm')->count())->toBe(1)
        ->and($entry->lines()->count())->toBe(2);
});

test('金額の記載がない納品書は、経理が金額を入れたら確定できる（直しには数えない）', function (): void {
    app(FakeClaudeClient::class)->withReading([
        'vendor' => 'ENEOS 八幡平SS',
        'total_yen' => null,
        'amount_missing' => true,
        'payment_method' => 'invoice_later',
        'tax_breakdown' => [],
    ]);
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $source = sendReceipt($company, $editor);

    $component = Livewire::actingAs($editor)
        ->test('pages::company.sources.show', ['company' => $company, 'source' => $source->id])
        ->assertSet('amountYen', '')
        ->assertSet('bankAccountId', bankAccountId($company, '後日の請求書（未払金）'))
        ->assertSee('金額を入れてください');

    $component->call('confirm')->assertHasErrors('amountYen');
    expect($source->refresh()->journalEntry?->status)->toBe(EntryStatus::Draft);

    $component->set('amountYen', '6,820')->call('confirm')->assertHasNoErrors();

    /** @var JournalEntry $entry */
    $entry = $source->refresh()->journalEntry()->with('lines.account')->firstOrFail();

    expect($entry->status)->toBe(EntryStatus::Confirmed)
        ->and($entry->amountYen())->toBe(6820)
        ->and($entry->lines[1]->account?->name)->toBe('未払金')
        ->and($entry->concern_flags)->toContain(ConcernFlag::AmountMissing->value)
        ->and($entry->concern_flags)->not->toContain(ConcernFlag::Corrected->value)
        ->and($source->amount_yen)->toBe(6820)
        ->and(AiCorrection::count())->toBe(0);
});

test('T16: 気になる印（会社の金額の目安・AI の重複の疑い）', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $company->forceFill(['concern_amount_yen' => 3000])->save();

    $source = sendReceipt($company, $editor);
    $entry = $source->journalEntry()->firstOrFail();
    expect($entry->concern_flags)->toContain(ConcernFlag::Amount->value);

    $company->forceFill(['concern_amount_yen' => 100000])->save();
    $entry->forceFill(['ai_check' => [...$entry->ai_check ?? [], 'duplicate_suspected' => true]])->save();

    Livewire::actingAs($editor)
        ->test('pages::company.sources.show', ['company' => $company, 'source' => $source->id])
        ->call('confirm')
        ->assertHasNoErrors();

    expect($entry->refresh()->concern_flags)->toBe([ConcernFlag::AiDuplicate->value]);
});

test('対象外にすると、原資料は残り、下書きは消え、確定したものは対象外にできない', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $source = sendReceipt($company, $editor);
    $entryId = $source->journal_entry_id;

    Livewire::actingAs($editor)
        ->test('pages::company.sources.show', ['company' => $company, 'source' => $source->id])
        ->call('ignore')
        ->assertHasErrors('ignoreReason')
        ->set('ignoreReason', '私的な買い物')
        ->call('ignore')
        ->assertHasNoErrors();

    expect($source->refresh()->status)->toBe(SourceStatus::Ignored)
        ->and($source->ignored_reason)->toBe('私的な買い物')
        ->and(JournalEntry::find($entryId))->toBeNull();

    $confirmed = sendReceipt($company, $editor, 420);
    Livewire::actingAs($editor)
        ->test('pages::company.sources.show', ['company' => $company, 'source' => $confirmed->id])
        ->call('confirm');

    Livewire::actingAs($editor)
        ->test('pages::company.sources.show', ['company' => $company, 'source' => $confirmed->id])
        ->set('ignoreReason', 'まちがい')
        ->call('ignore')
        ->assertHasErrors('ignoreReason');

    expect($confirmed->refresh()->status)->toBe(SourceStatus::Confirmed);
});

test('AI が読めなかった原資料は、経理が手で入れて確定できる', function (): void {
    app(FakeClaudeClient::class)->failWith(AiTask::ReadReceipt, 'error');
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $source = sendReceipt($company, $editor);

    Livewire::actingAs($editor)
        ->test('pages::company.sources.show', ['company' => $company, 'source' => $source->id])
        ->set('vendor', '△△商店')
        ->set('categoryId', categoryId($company, '部品・修理'))
        ->set('amountYen', '12000')
        ->call('confirm')
        ->assertHasNoErrors();

    $entry = $source->refresh()->journalEntry()->firstOrFail();
    expect($entry->status)->toBe(EntryStatus::Confirmed)
        ->and($entry->created_by)->toBe($editor->id)
        ->and($entry->amountYen())->toBe(12000);
});

test('他社の原資料は404、閲覧のみの人は直して確定の画面に入れず、アクションも403', function (): void {
    ['company' => $companyA, 'user' => $editorA] = createCompanyWithMember(Role::Editor);
    ['company' => $companyB, 'user' => $editorB] = createCompanyWithMember(Role::Editor);
    $sourceB = sendReceipt($companyB, $editorB);

    $this->actingAs($editorA)
        ->get(route('company.sources.show', [$companyA, $sourceB]))
        ->assertNotFound();
    $this->actingAs($editorA)
        ->get(route('company.entries.show', [$companyA, $sourceB->journal_entry_id]))
        ->assertNotFound();

    ['user' => $viewer] = createCompanyWithMember(Role::Viewer, company: $companyB);
    $this->actingAs($viewer)
        ->get(route('company.sources.show', [$companyB, $sourceB]))
        ->assertForbidden();

    $component = Livewire::actingAs($editorB)
        ->test('pages::company.sources.show', ['company' => $companyB, 'source' => $sourceB->id]);
    $this->actingAs($viewer);
    $component->call('confirm')->assertForbidden();

    expect($sourceB->refresh()->journalEntry?->status)->toBe(EntryStatus::Draft);
});

test('他社の分類・口座の ID を渡しても確定しない', function (): void {
    ['company' => $companyA, 'user' => $editorA] = createCompanyWithMember(Role::Editor);
    ['company' => $companyB] = createCompanyWithMember(Role::Editor);
    $source = sendReceipt($companyA, $editorA);

    Livewire::actingAs($editorA)
        ->test('pages::company.sources.show', ['company' => $companyA, 'source' => $source->id])
        ->set('categoryId', categoryId($companyB, '燃料'))
        ->set('bankAccountId', bankAccountId($companyB, '現金'))
        ->call('confirm')
        ->assertHasErrors(['categoryId', 'bankAccountId']);

    expect($source->refresh()->journalEntry?->status)->toBe(EntryStatus::Draft);
});
