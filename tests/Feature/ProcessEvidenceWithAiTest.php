<?php

use App\Enums\AiStatus;
use App\Enums\AiTask;
use App\Enums\CorrectionReason;
use App\Enums\EntryStatus;
use App\Enums\Role;
use App\Enums\Side;
use App\Enums\SourceStatus;
use App\Models\AiCorrection;
use App\Models\AiRun;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\SourceRecord;
use App\Models\User;
use App\Services\Ai\FakeClaudeClient;
use App\Services\SubmitEvidence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('evidence');
});

function submitReceipt(Company $company, User $user, int $width = 400, int $height = 600): SourceRecord
{
    $result = app(SubmitEvidence::class)->submit(
        $company,
        $user,
        UploadedFile::fake()->image('receipt.jpg', $width, $height),
    );

    expect($result->duplicate)->toBeFalse();

    return $result->source?->fresh() ?? throw new RuntimeException('原資料ができていません');
}

test('T09: 偽物の AI で L1→L2→L3 が走り、下書きができ、ai_runs が3件、ai_status=done', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);

    $source = submitReceipt($company, $user);

    expect($source->ai_status)->toBe(AiStatus::Done)
        ->and($source->status)->toBe(SourceStatus::Drafted)
        ->and($source->vendor)->toBe('〇〇石油 八幡平店')
        ->and($source->amount_yen)->toBe(3850)
        ->and($source->evidence?->extracted_text)->toContain('合計 3,850円');

    $runs = AiRun::where('company_id', $company->id)->orderBy('created_at')->get();
    expect($runs)->toHaveCount(3)
        ->and($runs->pluck('task')->all())->toEqualCanonicalizing([AiTask::ReadReceipt, AiTask::Journalize, AiTask::CheckEntry])
        ->and($runs->every(fn (AiRun $run): bool => $run->valid && $run->driver === 'fake'))->toBeTrue();

    $readRun = $runs->firstOrFail(fn (AiRun $run): bool => $run->task === AiTask::ReadReceipt);
    expect(json_encode($readRun->input))->not->toContain('base64');

    /** @var JournalEntry $entry */
    $entry = $source->journalEntry()->with('lines.account', 'category')->firstOrFail();

    expect($entry->status)->toBe(EntryStatus::Draft)
        ->and($entry->created_by)->toBeNull()
        ->and($entry->approved_by)->toBeNull()
        ->and($entry->category?->name)->toBe('消耗品')
        ->and($entry->ai_run_id)->toBe($runs->firstOrFail(fn (AiRun $run): bool => $run->task === AiTask::Journalize)->id)
        ->and($entry->ai_check['balanced'] ?? null)->toBeTrue()
        ->and($entry->ai_check['debit_total'] ?? null)->toBe(3850)
        ->and($entry->ai_check['remembered'] ?? null)->toBeFalse();

    expect($entry->lines)->toHaveCount(2)
        ->and($entry->lines[0]->side)->toBe(Side::Debit)
        ->and($entry->lines[0]->account?->name)->toBe('消耗品費')
        ->and($entry->lines[1]->side)->toBe(Side::Credit)
        ->and($entry->lines[1]->account?->name)->toBe('現金')
        ->and($entry->lines->sum('amount_yen'))->toBe(7700);
});

test('T10: AI がエラーを返したら原資料は残り failed、下書きはできない', function (): void {
    app(FakeClaudeClient::class)->failWith(AiTask::ReadReceipt, 'error');
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);

    $source = submitReceipt($company, $user);

    expect($source->ai_status)->toBe(AiStatus::Failed)
        ->and($source->status)->toBe(SourceStatus::New)
        ->and($source->ai_error)->not->toBeNull()
        ->and(JournalEntry::count())->toBe(0)
        ->and(AiRun::where('valid', false)->count())->toBe(1);
});

test('T10: AI の返事の形が違えば1回だけやり直し、それでも違えば failed', function (): void {
    app(FakeClaudeClient::class)->failWith(AiTask::Journalize, 'invalid');
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);

    $source = submitReceipt($company, $user);

    expect($source->ai_status)->toBe(AiStatus::Failed)
        ->and($source->status)->toBe(SourceStatus::New)
        ->and($source->ai_error)->toContain('形が違います')
        ->and(JournalEntry::count())->toBe(0)
        ->and(AiRun::where('task', AiTask::Journalize)->where('valid', false)->count())->toBe(2)
        ->and(AiRun::where('task', AiTask::ReadReceipt)->where('valid', true)->count())->toBe(1);
});

test('T10: 1日の上限を超えたら AI を呼ばずに failed（理由：上限）', function (): void {
    config(['torudake.ai.daily_limit' => 0]);
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);

    $source = submitReceipt($company, $user);

    expect($source->ai_status)->toBe(AiStatus::Failed)
        ->and($source->ai_error)->toContain('上限')
        ->and(AiRun::count())->toBe(0)
        ->and(app(FakeClaudeClient::class)->requests)->toBe([]);
});

test('T11: 会社Aの直した例は会社Aの L2 にだけ入り、会社Bの L2 の入力に入らない', function (): void {
    ['company' => $companyA, 'user' => $userA] = createCompanyWithMember(Role::Editor);
    ['company' => $companyB, 'user' => $userB] = createCompanyWithMember(Role::Editor);

    $firstA = submitReceipt($companyA, $userA, 400, 600);
    $entryA = $firstA->journalEntry()->firstOrFail();

    $correction = new AiCorrection;
    $correction->forceFill([
        'company_id' => $companyA->id,
        'ai_run_id' => $entryA->ai_run_id,
        'journal_entry_id' => $entryA->id,
        'field' => 'category',
        'before' => '消耗品',
        'after' => '燃料',
        'reason' => CorrectionReason::WrongCategory,
        'kernel' => 'vendor:〇〇石油 八幡平店→category:燃料',
        'vendor' => '〇〇石油 八幡平店',
        'corrected_by' => $userA->id,
    ])->save();

    $secondA = submitReceipt($companyA, $userA, 410, 610);
    $sourceB = submitReceipt($companyB, $userB, 420, 620);

    $journalizeInputB = AiRun::where('company_id', $companyB->id)->where('task', AiTask::Journalize)->firstOrFail()->input;
    expect($journalizeInputB['corrections'])->toBe([])
        ->and(json_encode($journalizeInputB, JSON_UNESCAPED_UNICODE))->not->toContain('category:燃料');

    $journalizeInputA = AiRun::where('company_id', $companyA->id)->where('task', AiTask::Journalize)
        ->where('subject_id', $secondA->id)->firstOrFail()->input;
    expect($journalizeInputA['corrections'])->toHaveCount(1)
        ->and($journalizeInputA['corrections'][0]['kernel'])->toBe('vendor:〇〇石油 八幡平店→category:燃料');

    $entrySecondA = $secondA->journalEntry()->with('category', 'lines.account')->firstOrFail();
    expect($entrySecondA->category?->name)->toBe('燃料')
        ->and($entrySecondA->ai_check['remembered'] ?? null)->toBeTrue()
        ->and($entrySecondA->lines[0]->account?->name)->toBe('車両費');

    $entryB = $sourceB->journalEntry()->with('category')->firstOrFail();
    expect($entryB->category?->name)->toBe('消耗品')
        ->and($entryB->ai_check['remembered'] ?? null)->toBeFalse();
});

test('締めた期間の日付が返ってきたら、締めた次の日にして「遅れて届いた」にする', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);
    $company->forceFill(['closed_through' => now()->toDateString()])->save();

    $source = submitReceipt($company, $user);
    $entry = $source->journalEntry()->firstOrFail();

    expect($source->late)->toBeTrue()
        ->and($entry->entry_date->toDateString())->toBe(now()->addDay()->toDateString());
});

test('承認済みの仕訳は Model から変更・削除できない', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);
    $entry = submitReceipt($company, $user)->journalEntry()->firstOrFail();

    $entry->forceFill(['status' => EntryStatus::Approved, 'approved_at' => now()])->save();

    expect(fn () => $entry->refresh()->forceFill(['description' => '改ざん'])->save())->toThrow(LogicException::class)
        ->and(fn () => $entry->refresh()->delete())->toThrow(LogicException::class);
});
