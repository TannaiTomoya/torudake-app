<?php

use App\Enums\Role;
use App\Models\JournalEntry;
use App\Models\SourceRecord;
use App\Services\Ai\FakeClaudeClient;
use App\Services\ConfirmEntryInput;
use App\Services\ConfirmEntryService;
use App\Support\OwnerConcerns;
use App\Support\ReadingChecks;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('evidence');
});

/**
 * @param  array<string, mixed>  $reading
 */
function readWith(array $reading, string $company = 'テスト建機'): JournalEntry
{
    ['company' => $c, 'user' => $user] = createCompanyWithMember(Role::Owner);
    $c->forceFill(['name' => $company])->save();
    app(FakeClaudeClient::class)->withReading($reading);

    return sendReceipt($c, $user)->journalEntry()->firstOrFail();
}

test('宛名の種類：別の会社・個人は印、上様・空欄・自分の会社（「(株)」「株式会社」の違いも）は印なし', function (?string $addressee, string $aiKind, string $kind, ?string $flag): void {
    $entry = readWith(['addressee' => $addressee, 'addressee_kind' => $aiKind]);

    expect($entry->ai_check['addressee_kind'] ?? null)->toBe($kind)
        ->and($entry->ai_check['addressee'] ?? null)->toBe($addressee);

    $addresseeFlags = array_values(array_filter($entry->concern_flags ?? [], fn (string $f): bool => str_starts_with($f, 'addressee_')));
    expect($addresseeFlags)->toBe($flag === null ? [] : [$flag]);
})->with([
    '別の会社' => ['株式会社 山田商事', 'other_company', 'other_company', 'addressee_other_company'],
    '個人' => ['山田 太郎', 'person', 'person', 'addressee_person'],
    '上様（AIが個人と言っても）' => ['上様', 'person', 'none', null],
    '空欄' => [null, 'none', 'none', null],
    '(株)が前（AIが別の会社と言っても）' => ['(株)テスト建機', 'other_company', 'own', null],
    '株式会社が後ろ・御中' => ['テスト建機株式会社 御中', 'other_company', 'own', null],
    '全角の（株）・様' => ['（株）テスト建機 様', 'own', 'own', null],
]);

test('ReadingChecks の宛名の判定は会社名と突き合わせる', function (): void {
    expect(ReadingChecks::addresseeKind('㈱テスト建機', 'other_company', '株式会社テスト建機'))->toBe('own')
        ->and(ReadingChecks::addresseeKind('ﾃｽﾄ建機', 'other_company', 'テスト建機'))->toBe('own')
        ->and(ReadingChecks::addresseeKind('テスト建設', 'other_company', 'テスト建機'))->toBe('other_company')
        ->and(ReadingChecks::addresseeKind('　', 'person', 'テスト建機'))->toBe('none');
});

test('宛名の印は、確定のあとも残り、社長の気になる仕訳に出る。直して確定とライブ画面にも出る', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    app(FakeClaudeClient::class)->withReading(['addressee' => '山田 太郎', 'addressee_kind' => 'person']);
    $source = sendReceipt($company, $owner);
    /** @var JournalEntry $draft */
    $draft = $source->journalEntry()->firstOrFail();

    Livewire::actingAs($owner)
        ->test('pages::company.sources.show', ['company' => $company, 'source' => $source->id])
        ->assertSeeHtml('data-test="addressee-flag">　宛名が個人名（私的な支出かも）</span>')
        ->assertSee('宛名：山田 太郎');

    Livewire::actingAs($owner)
        ->test('pages::company.live', ['company' => $company])
        ->assertSeeHtml('data-test="live-addressee-flag">宛名が個人名（私的な支出かも）：山田 太郎</p>');

    $entry = app(ConfirmEntryService::class)->confirm($company, $owner, $source->id, new ConfirmEntryInput(
        entryDate: $draft->entry_date->toDateString(),
        vendor: $draft->vendor,
        description: $draft->description,
        categoryId: (string) $draft->category_id,
        bankAccountId: (string) $draft->bank_account_id,
        amountYen: $draft->amountYen(),
    ));

    expect($entry->concern_flags)->toContain('addressee_person')
        ->and(OwnerConcerns::count($company))->toBe(1);

    $this->actingAs($owner)->get(route('company.owner', $company))
        ->assertOk()
        ->assertSee('宛名が個人名（私的な支出かも）');
});

test('自分の会社の宛名は、印なしで宛名だけ出る', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    $source = sendReceipt($company, $owner);

    Livewire::actingAs($owner)
        ->test('pages::company.sources.show', ['company' => $company, 'source' => $source->id])
        ->assertSee('宛名：テスト建機')
        ->assertDontSeeHtml('data-test="addressee-flag"');

    Livewire::actingAs($owner)
        ->test('pages::company.live', ['company' => $company])
        ->assertSeeHtml('data-test="live-addressee">宛名：テスト建機</p>')
        ->assertDontSeeHtml('data-test="live-addressee-flag"');
});

test('合計の自信が0.75なら「確かめて」と「読み取りの自信が低い」の印。0.85なら付かない', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);

    app(FakeClaudeClient::class)->withReading(['total_yen' => 800, 'confidence' => ['date' => 0.95, 'vendor' => 0.9, 'total' => 0.75]]);
    $low = sendReceipt($company, $owner, 400);
    app(FakeClaudeClient::class)->withReading(['confidence' => ['date' => 0.95, 'vendor' => 0.9, 'total' => 0.85]]);
    $ok = sendReceipt($company, $owner, 500);

    expect($low->journalEntry?->concern_flags)->toContain('low_confidence')
        ->and($ok->journalEntry?->concern_flags)->not->toContain('low_confidence');

    Livewire::actingAs($owner)
        ->test('pages::company.sources.show', ['company' => $company, 'source' => $low->id])
        ->assertSeeHtml('data-test="check-total"')
        ->assertSee('確かめて（AIの読み取りの自信が低い）')
        ->assertDontSeeHtml('data-test="check-vendor"');

    Livewire::actingAs($owner)
        ->test('pages::company.sources.show', ['company' => $company, 'source' => $ok->id])
        ->assertDontSeeHtml('data-test="check-total"');
});

test('店名・日付は0.7未満で「確かめて」。金額の記載がない書類は合計の自信0でも印を付けない', function (): void {
    expect(ReadingChecks::lowConfidenceFields(['reading_confidence' => ['date' => 0.69, 'vendor' => 0.7, 'total' => 0.8]]))->toBe(['date'])
        ->and(ReadingChecks::lowConfidenceFields(['reading_confidence' => ['date' => 0.9, 'vendor' => 0.65, 'total' => 0.79]]))->toBe(['vendor', 'total'])
        ->and(ReadingChecks::lowConfidenceFields(['amount_missing' => true, 'reading_confidence' => ['date' => 0.9, 'vendor' => 0.9, 'total' => 0]]))->toBe([]);

    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    app(FakeClaudeClient::class)->withReading(['confidence' => ['date' => 0.95, 'vendor' => 0.65, 'total' => 0.97]]);
    $source = sendReceipt($company, $owner);

    Livewire::actingAs($owner)
        ->test('pages::company.live', ['company' => $company])
        ->assertSeeHtml('data-test="live-check-vendor">確かめて</span>')
        ->assertDontSeeHtml('data-test="live-check-total"');

    expect(SourceRecord::findOrFail($source->id)->journalEntry?->concern_flags)->toContain('low_confidence');
});
