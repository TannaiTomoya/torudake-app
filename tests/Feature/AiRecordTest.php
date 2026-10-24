<?php

use App\Enums\CorrectionReason;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\SourceRecord;
use App\Models\User;
use App\Services\AiRecordSummary;
use App\Services\ConfirmEntryInput;
use App\Services\ConfirmEntryService;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('evidence');
    Storage::fake('local');
});

function confirmForAiRecord(Company $company, User $user, SourceRecord $source, bool $changeCategory = false): JournalEntry
{
    /** @var JournalEntry $draft */
    $draft = $source->journalEntry()->firstOrFail();
    $categoryId = (string) $draft->category_id;

    if ($changeCategory) {
        $direction = $company->categories()->whereKey($categoryId)->value('direction');
        $categoryId = (string) $company->categories()
            ->where('direction', $direction)
            ->whereKeyNot($categoryId)
            ->value('id');
    }

    return app(ConfirmEntryService::class)->confirm($company, $user, $source->id, new ConfirmEntryInput(
        entryDate: $draft->entry_date->toDateString(),
        vendor: $draft->vendor,
        description: $draft->description,
        categoryId: $categoryId,
        bankAccountId: (string) $draft->bank_account_id,
        amountYen: $draft->amountYen(),
        reason: $changeCategory ? CorrectionReason::WrongCategory : null,
    ));
}

/**
 * @return array{company: Company, owner: User}
 */
function aiRecordCompany(): array
{
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);

    confirmForAiRecord($company, $owner, sendReceipt($company, $owner, 400));
    confirmForAiRecord($company, $owner, sendReceipt($company, $owner, 410));
    confirmForAiRecord($company, $owner, sendReceipt($company, $owner, 420), changeCategory: true);

    return ['company' => $company, 'owner' => $owner];
}

test('T27: AIの記録に「AIが作った仕訳 n件のうち、そのまま確定 m件」と一番多い直しが出て、他社の数は混ざらない', function (): void {
    ['company' => $company, 'owner' => $owner] = aiRecordCompany();

    ['company' => $other, 'user' => $otherOwner] = createCompanyWithMember(Role::Owner);
    confirmForAiRecord($other, $otherOwner, sendReceipt($other, $otherOwner, 430), changeCategory: true);
    confirmForAiRecord($other, $otherOwner, sendReceipt($other, $otherOwner, 440), changeCategory: true);

    $runs = $company->aiRuns()->count();

    Livewire::actingAs($owner)
        ->test('pages::company.ai', ['company' => $company])
        ->assertSeeHtml('data-test="ai-entries">3</span>')
        ->assertSeeHtml('data-test="ai-unchanged">2</span>')
        ->assertSeeHtml('data-test="ai-unchanged-pct">67</span>')
        ->assertSee('一番多い直し：分類違い（1件）')
        ->assertSee('直した内訳（1か所）')
        ->assertSee("AIの実行（{$runs}回）")
        ->assertSee('読み取り')
        ->assertSee('→ 分類を『')
        ->assertDontSee('→ category:')
        ->assertSee('学習データを書き出す（JSONL）')
        ->call('previousMonth')
        ->assertSee('この月は、AIが作って確定した仕訳がまだありません')
        ->call('nextMonth')
        ->call('nextMonth')
        ->assertSet('month', now()->format('Y-m'));
});

test('T27: 学習データは JSONL 3つの zip で、その会社の分だけ。名前・メールは入らず、書き出しは監査記録に残る', function (): void {
    ['company' => $company, 'owner' => $owner] = aiRecordCompany();
    ['company' => $other, 'user' => $otherOwner] = createCompanyWithMember(Role::Owner);
    $otherEntry = confirmForAiRecord($other, $otherOwner, sendReceipt($other, $otherOwner, 430));

    $response = $this->actingAs($owner)
        ->get(route('company.exports.training', ['company' => $company, 'month' => now()->format('Y-m')]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/zip');

    $path = tempnam(sys_get_temp_dir(), 'test-zip-');
    file_put_contents($path, $response->getContent());
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();

    $files = [];
    foreach (['ai_runs.jsonl', 'ai_corrections.jsonl', 'journal_entries.jsonl'] as $name) {
        $text = (string) $zip->getFromName($name);
        $files[$name] = array_map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), array_values(array_filter(explode("\n", $text))));
        expect($text)->not->toContain($owner->email)
            ->not->toContain($owner->name)
            ->not->toContain($otherEntry->id);
    }
    $zip->close();
    unlink($path);

    expect($files['ai_runs.jsonl'])->toHaveCount($company->aiRuns()->count())
        ->and($files['ai_corrections.jsonl'])->toHaveCount(1)
        ->and($files['ai_corrections.jsonl'][0]['reason'])->toBe('wrong_category')
        ->and($files['ai_corrections.jsonl'][0]['corrected_by'])->toStartWith('u_')
        ->and($files['journal_entries.jsonl'])->toHaveCount(3)
        ->and(collect($files['journal_entries.jsonl'])->pluck('origin')->countBy()->all())->toBe(['ai_unchanged' => 2, 'corrected' => 1])
        ->and($files['journal_entries.jsonl'][0]['lines'])->toHaveCount(2);

    $log = AuditLog::where('action', 'training.export')->sole();
    expect($log->company_id)->toBe($company->id)
        ->and($log->after)->toMatchArray(['ai_corrections' => 1, 'journal_entries' => 3]);
});

test('間違いの型は表示だけ日本語にし、記録の kernel は変えない', function (): void {
    expect(AiRecordSummary::kernelLabel('vendor:〇〇石油 八幡平店 → category:燃料'))->toBe('〇〇石油 八幡平店 → 分類を『燃料』に')
        ->and(AiRecordSummary::kernelLabel('vendor:△△商店 → payment:社長の立替'))->toBe('△△商店 → 支払い元を『社長の立替』に')
        ->and(AiRecordSummary::kernelLabel('amount:3850 → 3580'))->toBe('金額を『3850』から『3580』に')
        ->and(AiRecordSummary::kernelLabel('そのほかのメモ'))->toBe('そのほかのメモ');

    ['company' => $company] = aiRecordCompany();
    expect($company->aiCorrections()->sole()->kernel)->toStartWith('vendor:')->toContain(' → category:');
});

test('経理は AIの記録も書き出しも 403、他社の人は 404', function (): void {
    ['company' => $company] = createCompanyWithMember(Role::Owner);
    ['user' => $editor] = createCompanyWithMember(Role::Editor, company: $company);
    ['user' => $outsider] = createCompanyWithMember(Role::Owner);

    $this->actingAs($editor)->get(route('company.ai', $company))->assertForbidden();
    $this->actingAs($editor)->get(route('company.exports.training', $company))->assertForbidden();
    $this->actingAs($outsider)->get(route('company.ai', $company))->assertNotFound();
    $this->actingAs($outsider)->get(route('company.exports.training', $company))->assertNotFound();
});
