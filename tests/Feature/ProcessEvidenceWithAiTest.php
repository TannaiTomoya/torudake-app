<?php

use App\Enums\AiStatus;
use App\Enums\AiTask;
use App\Enums\ConcernFlag;
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
use App\Services\Ai\Tasks\Journalize;
use App\Services\FailEvidenceAi;
use App\Services\SubmitEvidence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
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

    expect(app(FakeClaudeClient::class)->requests[0]->attachments[0]['media_type'])->toBe('image/jpeg');

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
        ->and($entry->confirmed_by)->toBeNull()
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

    (new AiCorrection)->forceFill([
        'company_id' => $companyA->id,
        'ai_run_id' => $entryA->ai_run_id,
        'journal_entry_id' => $entryA->id,
        'field' => 'category',
        'before' => '消耗品',
        'after' => '燃料',
        'reason' => CorrectionReason::WrongCategory,
        'kernel' => 'vendor:〇〇石油 八幡平店 → category:燃料',
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
        ->and($journalizeInputA['corrections'][0]['kernel'])->toBe('vendor:〇〇石油 八幡平店 → category:燃料');

    $entrySecondA = $secondA->journalEntry()->with('category', 'lines.account')->firstOrFail();
    expect($entrySecondA->category?->name)->toBe('燃料')
        ->and($entrySecondA->ai_check['remembered'] ?? null)->toBeTrue()
        ->and($entrySecondA->lines[0]->account?->name)->toBe('車両費');

    $entryB = $sourceB->journalEntry()->with('category')->firstOrFail();
    expect($entryB->category?->name)->toBe('消耗品')
        ->and($entryB->ai_check['remembered'] ?? null)->toBeFalse();
});

test('T33: 分類名を変更した後は古い分類の直しを除き、現在の分類と口座だけをAIの選択肢にする', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);
    $category = $company->categories()->where('name', '消耗品')->firstOrFail();
    $category->forceFill(['name' => '消耗品費'])->save();

    (new AiCorrection)->forceFill([
        'company_id' => $company->id,
        'journal_entry_id' => null,
        'field' => 'category',
        'before' => '仕入',
        'after' => '消耗品',
        'reason' => CorrectionReason::WrongCategory,
        'kernel' => 'vendor:〇〇石油 八幡平店 → category:消耗品',
        'vendor' => '〇〇石油 八幡平店',
        'corrected_by' => $user->id,
    ])->save();
    (new AiCorrection)->forceFill([
        'company_id' => $company->id,
        'journal_entry_id' => null,
        'field' => 'credit_account',
        'before' => '現金',
        'after' => '古い口座',
        'reason' => CorrectionReason::WrongPayment,
        'kernel' => 'vendor:〇〇石油 八幡平店 → payment:古い口座',
        'vendor' => '〇〇石油 八幡平店',
        'corrected_by' => $user->id,
    ])->save();

    $input = Journalize::buildInput($company, ['vendor' => '〇〇石油 八幡平店']);
    $schema = Journalize::schema(array_column($input['categories'], 'name'), array_column($input['bank_accounts'], 'name'));

    expect($input['corrections'])->toBe([])
        ->and($schema['properties']['category']['enum'])->toContain('消耗品費')->not->toContain('消耗品')
        ->and($schema['properties']['bank_account']['enum'])->toContain('現金');

    $source = submitReceipt($company, $user);
    $entry = $source->journalEntry()->with('category')->firstOrFail();
    $journalizeRun = AiRun::query()->where('task', AiTask::Journalize)->where('subject_id', $source->id)->firstOrFail();

    expect($source->ai_status)->toBe(AiStatus::Done)
        ->and(in_array($entry->category?->name, array_column($input['categories'], 'name'), true))->toBeTrue()
        ->and($entry->category?->name)->not->toBe('消耗品')
        ->and($journalizeRun->input['corrections'])->toBe([]);
});

test('T35: 10分以上止まったAI処理だけを失敗にして、手入力へ戻す', function (): void {
    Queue::fake();
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);

    $stale = submitReceipt($company, $user);
    $staleDraft = submitReceipt($company, $user, 410);
    $fresh = submitReceipt($company, $user, 420);
    $stale->forceFill(['updated_at' => now()->subMinutes(11)])->save();
    $staleDraft->forceFill(['status' => SourceStatus::Drafted, 'updated_at' => now()->subMinutes(11)])->save();

    expect(app(FailEvidenceAi::class)->failStale())->toBe(2)
        ->and($stale->refresh()->ai_status)->toBe(AiStatus::Failed)
        ->and($stale->ai_error)->toContain('10分以上止まった')
        ->and($staleDraft->refresh()->ai_status)->toBe(AiStatus::Failed)
        ->and($fresh->refresh()->ai_status)->toBe(AiStatus::Waiting);
});

test('T36: 形が違う・存在しない・未来の日付でもAIの下書きを作り、原資料の日付は空にする', function (string $date): void {
    app(FakeClaudeClient::class)->withReading(['date' => $date]);
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);

    $source = submitReceipt($company, $user);
    $entry = $source->journalEntry()->firstOrFail();

    expect($source->ai_status)->toBe(AiStatus::Done)
        ->and($source->occurred_on)->toBeNull()
        ->and($entry->entry_date->toDateString())->toBe(now()->toDateString());
})->with([
    '和暦形式' => '2026年10月24日',
    '存在しない日付' => '2026-02-30',
    '未来の日付' => '2099-01-01',
]);

test('金額の記載がない納品書（後日請求）でも、行のない下書きができ、支払い元は未払金、気になる印が付く', function (): void {
    app(FakeClaudeClient::class)->withReading([
        'full_text' => "ENEOS 八幡平SS\n納品書（お客様控）\n軽油 40.0L\nカード払い・後日ご請求",
        'vendor' => 'ENEOS 八幡平SS',
        'total_yen' => null,
        'amount_missing' => true,
        'payment_method' => 'invoice_later',
        'tax_breakdown' => [],
        'confidence' => ['date' => 0.95, 'vendor' => 0.95, 'total' => 0.0],
    ]);
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);

    $source = submitReceipt($company, $user);

    /** @var JournalEntry $entry */
    $entry = $source->journalEntry()->with(['lines', 'bankAccount.account'])->firstOrFail();

    expect($source->ai_status)->toBe(AiStatus::Done)
        ->and($source->status)->toBe(SourceStatus::Drafted)
        ->and($source->amount_yen)->toBeNull()
        ->and($entry->status)->toBe(EntryStatus::Draft)
        ->and($entry->lines)->toHaveCount(0)
        ->and($entry->amountYen())->toBeNull()
        ->and($entry->bankAccount?->account?->name)->toBe('未払金')
        ->and($entry->ai_check['amount_missing'] ?? null)->toBeTrue()
        ->and($entry->concern_flags)->toContain(ConcernFlag::AmountMissing->value);

    // 行がないので検算（L3）は呼ばない
    expect(AiRun::where('task', AiTask::CheckEntry)->count())->toBe(0);

    $this->actingAs($user)
        ->get(route('company.sources.show', [$company, $source]))
        ->assertOk()
        ->assertSee('金額を入れてください');
});

test('確定した仕訳は Model から削除できず、下書きにも戻せない', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);
    $entry = submitReceipt($company, $user)->journalEntry()->firstOrFail();

    $entry->forceFill(['status' => EntryStatus::Confirmed, 'confirmed_at' => now()])->save();

    expect(fn () => $entry->delete())->toThrow(LogicException::class);
    expect(fn () => $entry->refresh()->forceFill(['status' => EntryStatus::Draft])->save())->toThrow(LogicException::class);
});
