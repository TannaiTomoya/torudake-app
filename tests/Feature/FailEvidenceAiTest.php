<?php

use App\Enums\AiStatus;
use App\Enums\EntryStatus;
use App\Enums\Role;
use App\Enums\SourceStatus;
use App\Jobs\ProcessEvidenceWithAi;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\SourceRecord;
use App\Models\User;
use App\Services\Ai\FakeClaudeClient;
use App\Services\FailEvidenceAi;
use Carbon\CarbonImmutable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('evidence');
});

function pendingRecoverySource(Company $company, User $user, SourceStatus $status, AiStatus $aiStatus): SourceRecord
{
    $source = sendReceipt($company, $user);
    $entry = $status === SourceStatus::Drafted
        ? putEntry($company, '2026-10-25', '消耗品', 1200, EntryStatus::Draft)
        : null;
    $source->forceFill([
        'status' => $status,
        'ai_status' => $aiStatus,
        'ai_stage' => '分類',
        'journal_entry_id' => $entry?->id,
    ])->save();

    return $source;
}

test('T34: キューからの最終失敗通知で読み取り中を手入力へ戻し、遅れたジョブはAIを呼ばない', function (): void {
    $queueManager = Queue::getFacadeRoot();
    Queue::fake();
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);
    $source = pendingRecoverySource($company, $user, SourceStatus::New, AiStatus::Reading);
    Queue::swap($queueManager);

    // シリアライズされたジョブをキューから取り出し、Laravelの失敗通知を通す。
    $queue = $queueManager->connection('database');
    $queue->push(new ProcessEvidenceWithAi($source->id));
    $job = $queue->pop();
    expect($job)->not->toBeNull();
    $job->fail(MaxAttemptsExceededException::forJob($job));

    expect($source->refresh()->ai_status)->toBe(AiStatus::Failed)
        ->and($source->status)->toBe(SourceStatus::New)
        ->and($source->ai_error)->toBe('AIの処理に失敗しました。')
        ->and($queue->size())->toBe(0)
        ->and(AuditLog::query()->where('action', 'evidence.ai_failed')->where('subject_id', $source->id)->count())->toBe(1);

    app()->call([new ProcessEvidenceWithAi($source->id), 'handle']);
    expect(app(FakeClaudeClient::class)->requests)->toBe([])
        ->and($source->refresh()->journal_entry_id)->toBeNull();
});

test('T34: 失敗通知を繰り返しても下書きと入力値を保ち、監査記録は一度だけ残す', function (SourceStatus $status, AiStatus $aiStatus): void {
    Queue::fake();
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);
    $source = pendingRecoverySource($company, $user, $status, $aiStatus);
    $entryId = $source->journal_entry_id;
    $source->forceFill(['vendor' => '架空の取引先', 'amount_yen' => 1200, 'occurred_on' => '2026-10-24'])->save();
    $job = new ProcessEvidenceWithAi($source->id);

    $job->failed(new RuntimeException('internal detail'));
    $job->failed(new RuntimeException('second failure'));

    expect($source->refresh()->status)->toBe($status)
        ->and($source->ai_status)->toBe(AiStatus::Failed)
        ->and($source->ai_error)->toBe('AIの処理に失敗しました。')
        ->and($source->journal_entry_id)->toBe($entryId)
        ->and($source->vendor)->toBe('架空の取引先')
        ->and($source->amount_yen)->toBe(1200)
        ->and($source->occurred_on?->toDateString())->toBe('2026-10-24')
        ->and(AuditLog::query()->where('action', 'evidence.ai_failed')->where('subject_id', $source->id)->count())->toBe(1);
    if ($entryId !== null) {
        expect($source->journalEntry?->status)->toBe(EntryStatus::Draft)
            ->and($source->journalEntry?->amountYen())->toBe(1200);
    }
})->with([
    [SourceStatus::New, AiStatus::Waiting],
    [SourceStatus::New, AiStatus::Reading],
    [SourceStatus::Drafted, AiStatus::Waiting],
    [SourceStatus::Drafted, AiStatus::Reading],
]);

test('T34: 確定・対象外・AI完了・失敗済みの原資料は失敗通知で変更しない', function (SourceStatus $status, AiStatus $aiStatus): void {
    Queue::fake();
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);
    $source = pendingRecoverySource($company, $user, $status, $aiStatus);
    $before = $source->getAttributes();

    (new ProcessEvidenceWithAi($source->id))->failed(new RuntimeException('late failure'));

    expect($source->refresh()->getAttributes())->toBe($before)
        ->and(AuditLog::query()->where('action', 'evidence.ai_failed')->exists())->toBeFalse();
})->with([
    [SourceStatus::Confirmed, AiStatus::Reading],
    [SourceStatus::Ignored, AiStatus::Waiting],
    [SourceStatus::New, AiStatus::Done],
    [SourceStatus::New, AiStatus::Failed],
    [SourceStatus::Drafted, AiStatus::Done],
    [SourceStatus::Drafted, AiStatus::Failed],
]);

test('T34: 他社の原資料IDは失敗処理でも変更せず、監査記録も残さない', function (): void {
    Queue::fake();
    ['company' => $company] = createCompanyWithMember();
    ['company' => $other, 'user' => $otherUser] = createCompanyWithMember(Role::Editor);
    $foreign = pendingRecoverySource($other, $otherUser, SourceStatus::New, AiStatus::Reading);
    $before = $foreign->getAttributes();

    expect(app(FailEvidenceAi::class)->markFailed($company, $foreign->id, new RuntimeException('failure')))->toBeFalse()
        ->and($foreign->refresh()->getAttributes())->toBe($before)
        ->and(AuditLog::query()->where('action', 'evidence.ai_failed')->exists())->toBeFalse();
});

test('T35: 停滞候補を選んだ後でも更新があればロック内で失敗処理を止める', function (): void {
    Queue::fake();
    $this->freezeTime();
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);
    $source = pendingRecoverySource($company, $user, SourceStatus::New, AiStatus::Reading);
    $cutoff = CarbonImmutable::instance(now()->subMinutes(10));
    $before = $source->getAttributes();

    expect(app(FailEvidenceAi::class)->markFailed($company, $source->id, new RuntimeException('stale'), $cutoff))->toBeFalse()
        ->and($source->refresh()->getAttributes())->toBe($before)
        ->and(AuditLog::query()->where('action', 'evidence.ai_failed')->exists())->toBeFalse();
});

test('T35: 定期復旧コマンドは10分境界の停滞だけを各社で復旧し、再実行では変更しない', function (): void {
    Queue::fake();
    $this->freezeTime();
    $expected = [];
    $preserved = [];

    foreach ([Role::Owner, Role::Editor] as $role) {
        ['company' => $company, 'user' => $user] = createCompanyWithMember($role);
        foreach ([
            [SourceStatus::New, AiStatus::Waiting, 600, true],
            [SourceStatus::Drafted, AiStatus::Reading, 660, true],
            [SourceStatus::New, AiStatus::Reading, 599, false],
            [SourceStatus::New, AiStatus::Done, 660, false],
            [SourceStatus::Drafted, AiStatus::Failed, 660, false],
            [SourceStatus::Confirmed, AiStatus::Reading, 660, false],
            [SourceStatus::Ignored, AiStatus::Waiting, 660, false],
        ] as $index => [$status, $aiStatus, $age, $shouldFail]) {
            // 写真のサイズを変えて重複送信扱いを避ける。
            $source = sendReceipt($company, $user, 400 + $index);
            $source->forceFill(['status' => $status, 'ai_status' => $aiStatus, 'updated_at' => now()->subSeconds($age)])->save();
            if ($shouldFail) {
                $expected[] = $source;
            } else {
                $preserved[] = [$source, $source->getAttributes()];
            }
        }
    }

    $this->artisan('torudake:fail-stale-evidence-ai')
        ->expectsOutput('停滞していたAI処理を4件、失敗にしました。')->assertSuccessful();
    foreach ($expected as $source) {
        expect($source->refresh()->ai_status)->toBe(AiStatus::Failed)
            ->and($source->ai_error)->toContain('10分以上止まった');
        $audit = AuditLog::query()->where('action', 'evidence.ai_failed')->where('subject_id', $source->id)->sole();
        expect($audit->company_id)->toBe($source->company_id);
    }
    foreach ($preserved as [$source, $before]) {
        expect($source->refresh()->getAttributes())->toBe($before);
    }
    $this->artisan('torudake:fail-stale-evidence-ai')
        ->expectsOutput('停滞していたAI処理を0件、失敗にしました。')->assertSuccessful();
    expect(AuditLog::query()->where('action', 'evidence.ai_failed')->count())->toBe(4)
        ->and(app(FakeClaudeClient::class)->requests)->toBe([]);
});

test('T35: 停滞復旧コマンドは5分ごとに重複実行を防いで登録されている', function (): void {
    $this->artisan('schedule:list')->assertSuccessful();
    $events = collect(Schedule::events())->filter(fn ($event) => str_contains((string) $event->command, 'torudake:fail-stale-evidence-ai'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('*/5 * * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue();
});
