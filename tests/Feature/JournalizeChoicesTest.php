<?php

use App\Enums\AiStatus;
use App\Enums\AiTask;
use App\Enums\CorrectionReason;
use App\Enums\EntryStatus;
use App\Enums\SourceStatus;
use App\Models\AiCorrection;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AiResult;
use App\Services\Ai\FakeClaudeClient;
use App\Services\Ai\Tasks\Journalize;
use App\Services\CompanySettingsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('evidence');
    Http::preventStrayRequests();
});

test('T33: AIが旧名を返したら1回だけ再試行し、現行名で下書きを作る。2回とも旧名なら保存しない', function (string $field, bool $recovers): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember();
    app(CompanySettingsService::class)->renameCategory($company, $user, categoryId($company, '消耗品'), '架空の備品');
    $company->bankAccounts()->where('name', '現金')->firstOrFail()->forceFill(['name' => '架空の現金'])->save();
    $oldName = $field === 'category' ? '消耗品' : '現金';
    $fake = app(FakeClaudeClient::class);
    $attempts = 0;
    $client = Mockery::mock(AiClient::class);
    $client->shouldReceive('driverName')->andReturn('fake');
    $client->shouldReceive('modelName')->andReturn('fake');
    $client->shouldReceive('complete')->andReturnUsing(function (AiRequest $request) use ($fake, $field, $oldName, $recovers, &$attempts): AiResult {
        if ($request->task === AiTask::Journalize) {
            $attempts++;
            $fake->withJournalize($recovers && $attempts === 2 ? [] : [$field => $oldName]);
        }

        return $fake->complete($request);
    });
    app()->instance(AiClient::class, $client);

    $source = sendReceipt($company, $user);
    $runs = $company->aiRuns()->where('task', AiTask::Journalize)->orderBy('id')->get();
    expect($attempts)->toBe(2)
        ->and($runs)->toHaveCount(2)
        ->and($runs[0]->valid)->toBeFalse()
        ->and($runs[0]->error)->toContain('$.'.$field.' の値が選択肢にありません')
        ->and($runs[1]->valid)->toBe($recovers);

    if ($recovers) {
        $entry = $source->journalEntry()->with('category', 'bankAccount')->sole();
        expect($source->ai_status)->toBe(AiStatus::Done)
            ->and($source->status)->toBe(SourceStatus::Drafted)
            ->and($entry->category?->name)->not->toBe('消耗品')
            ->and($entry->bankAccount?->name)->toBe('架空の現金')
            ->and($entry->status)->toBe(EntryStatus::Draft)
            ->and($entry->ai_run_id)->toBe($runs[1]->id)
            ->and($entry->confirmed_at)->toBeNull();
    } else {
        expect($source->ai_status)->toBe(AiStatus::Failed)
            ->and($source->status)->toBe(SourceStatus::New)
            ->and($source->ai_error)->toContain('選択肢にありません')
            ->and($company->journalEntries()->count())->toBe(0)
            ->and($company->aiRuns()->where('task', AiTask::CheckEntry)->count())->toBe(0);
    }
})->with([
    '分類・再試行で回復' => ['category', true],
    '口座・再試行で回復' => ['bank_account', true],
    '分類・2回失敗' => ['category', false],
    '口座・2回失敗' => ['bank_account', false],
]);

test('T33: 使用停止の分類・口座とその直し例は選択肢にもプロンプトにも含めない', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember();
    $company->categories()->where('name', '燃料')->firstOrFail()->forceFill(['archived_at' => now()])->save();
    $company->bankAccounts()->where('name', '社長の立替')->firstOrFail()->forceFill(['archived_at' => now()])->save();
    foreach (['category' => '燃料', 'credit_account' => '社長の立替'] as $field => $name) {
        (new AiCorrection)->forceFill([
            'company_id' => $company->id, 'corrected_by' => $user->id,
            'field' => $field, 'before' => null, 'after' => $name,
            'reason' => CorrectionReason::Other, 'kernel' => '使用停止の例',
        ])->save();
    }

    $source = sendReceipt($company, $user);
    $request = collect(app(FakeClaudeClient::class)->requests)->firstWhere('task', AiTask::Journalize);
    expect($source->ai_status)->toBe(AiStatus::Done)
        ->and($request->schema['properties']['category']['enum'])->not->toContain('燃料')
        ->and($request->schema['properties']['bank_account']['enum'])->not->toContain('社長の立替')
        ->and($request->input['corrections'])->toBe([]);
});

test('T33: 直し後の名前が現行でも、直し前が旧名なら旧名とkernelをAIへ渡さず、履歴自体は保持する', function (string $field, string $after): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember();
    $correction = new AiCorrection;
    $correction->forceFill([
        'company_id' => $company->id, 'corrected_by' => $user->id,
        'field' => $field, 'before' => '廃止した名前', 'after' => $after,
        'reason' => CorrectionReason::Other, 'kernel' => '廃止した名前からの直し',
    ])->save();

    $input = Journalize::buildInput($company, []);
    expect($input['corrections'])->toHaveCount(1)
        ->and($input['corrections'][0]['before'])->toBeNull()
        ->and($input['corrections'][0]['kernel'])->toBeNull()
        ->and($input['corrections'][0]['after'])->toBe($after)
        ->and($correction->fresh()->before)->toBe('廃止した名前')
        ->and($correction->fresh()->kernel)->toBe('廃止した名前からの直し');
})->with(['分類' => ['category', '燃料'], '口座' => ['credit_account', '社長の立替']]);

test('T33: 旧名の例が最近20件を占めても、その前の現行の例を入力に残す', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember();
    config(['torudake.ai.corrections_in_prompt' => 20]);
    foreach (range(0, 20) as $index) {
        (new AiCorrection)->forceFill([
            'company_id' => $company->id, 'corrected_by' => $user->id,
            'field' => 'category', 'before' => '消耗品', 'after' => $index === 0 ? '燃料' : '旧分類',
            'vendor' => '架空の店', 'reason' => CorrectionReason::WrongCategory,
            'kernel' => $index === 0 ? '現行の例' : '旧名の例',
            'created_at' => now()->subMinutes(21 - $index),
        ])->save();
    }

    $input = Journalize::buildInput($company, []);
    expect($input['corrections'])->toHaveCount(1)
        ->and($input['corrections'][0]['kernel'])->toBe('現行の例')
        ->and($company->aiCorrections()->count())->toBe(21);
});

test('T33: 他社だけの分類・口座・直し例は会社の選択肢にもAI入力にも混ざらず、他社のデータを変えない', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember();
    ['company' => $other, 'user' => $otherUser] = createCompanyWithMember();
    $other->categories()->where('name', '消耗品')->firstOrFail()->forceFill(['name' => '他社専用分類'])->save();
    $other->bankAccounts()->where('name', '現金')->firstOrFail()->forceFill(['name' => '他社専用口座'])->save();
    $correction = new AiCorrection;
    $correction->forceFill([
        'company_id' => $other->id, 'corrected_by' => $otherUser->id,
        'field' => 'category', 'before' => '消耗品', 'after' => '燃料',
        'reason' => CorrectionReason::WrongCategory, 'kernel' => '他社だけの直し',
    ])->save();
    $snapshot = $correction->fresh()->getAttributes();

    $source = sendReceipt($company, $user);
    $request = collect(app(FakeClaudeClient::class)->requests)->firstWhere('task', AiTask::Journalize);
    expect($source->ai_status)->toBe(AiStatus::Done)
        ->and($request->schema['properties']['category']['enum'])->not->toContain('他社専用分類')
        ->and($request->schema['properties']['bank_account']['enum'])->not->toContain('他社専用口座')
        ->and($request->input['corrections'])->toBe([])
        ->and($correction->fresh()->getAttributes())->toBe($snapshot)
        ->and($other->aiRuns()->count())->toBe(0)
        ->and($other->journalEntries()->count())->toBe(0);
});
