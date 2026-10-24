<?php

use App\Enums\AiTask;
use App\Enums\Role;
use App\Models\AiRun;
use App\Models\Company;
use App\Services\Ai\AiClient;
use App\Services\Ai\FakeClaudeClient;
use Carbon\CarbonInterval;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('evidence');
    Http::preventStrayRequests();
    Sleep::fake();
});

/**
 * デモ用の会社で練習し、その記録を「本物の Claude が返したもの」に見立てる。
 *
 * @return array{company: Company, runs: Collection<int, AiRun>}
 */
function practiceInDemoCompany(int $width = 400): array
{
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Owner);
    $company->forceFill(['is_demo' => true])->save();

    app(FakeClaudeClient::class)->withReading(['vendor' => '練習の店', 'total_yen' => 4120]);
    sendReceipt($company, $user, $width);
    app(FakeClaudeClient::class)->withReading([]);

    $runs = $company->aiRuns()->get()->each(fn (AiRun $run) => $run->forceFill(['driver' => 'claude', 'model' => 'claude-練習', 'latency_ms' => 1500])->save());

    return ['company' => $company, 'runs' => $runs];
}

function useReplayDriver(): FakeClaudeClient
{
    config(['torudake.ai.driver' => 'replay']);
    $fake = app(FakeClaudeClient::class);
    $fake->requests = [];
    app()->instance(AiClient::class, $fake);

    return $fake;
}

test('T23: 再生モードでは、練習用のデモの会社の記録を、本番用のデモの会社で同じ間隔で再生し、AI を呼ばない', function (): void {
    ['runs' => $practice] = practiceInDemoCompany();
    expect($practice->pluck('photo_sha256')->filter()->unique())->toHaveCount(1)
        ->and($practice->pluck('task')->map->value->all())->toContain('read_receipt', 'journalize', 'check_entry');

    ['company' => $stage, 'user' => $user] = createCompanyWithMember(Role::Owner);
    $stage->forceFill(['is_demo' => true])->save();
    $fake = useReplayDriver();

    $source = sendReceipt($stage, $user);

    expect($fake->requests)->toBe([])
        ->and($source->vendor)->toBe('練習の店')
        ->and($source->amount_yen)->toBe(4120);

    $runs = $stage->aiRuns()->get();
    expect($runs)->toHaveCount(3)
        ->and($runs->pluck('driver')->unique()->all())->toBe(['replay'])
        ->and($runs->pluck('model')->unique()->all())->toBe(['claude-練習'])
        ->and($runs->firstWhere('task', AiTask::ReadReceipt)?->output)->toBe($practice->firstWhere('task', AiTask::ReadReceipt)?->output);

    Sleep::assertSleptTimes(3);
    Sleep::assertSlept(fn (CarbonInterval $duration): bool => (int) $duration->totalMilliseconds === 1500, 3);

    Livewire::actingAs($user)
        ->test('pages::company.live', ['company' => $stage])
        ->assertSeeHtml('data-test="live-replay">再生</span>');
});

test('再生モードでも、練習の記録がない写真は本物（ここでは偽物）の AI を呼び、「再生」は出ない', function (): void {
    practiceInDemoCompany(400);

    ['company' => $stage, 'user' => $user] = createCompanyWithMember(Role::Owner);
    $stage->forceFill(['is_demo' => true])->save();
    $fake = useReplayDriver();

    sendReceipt($stage, $user, 500);

    expect($fake->requests)->toHaveCount(3)
        ->and($stage->aiRuns()->pluck('driver')->unique()->all())->toBe(['fake']);
    Sleep::assertNeverSlept();

    Livewire::actingAs($user)
        ->test('pages::company.live', ['company' => $stage])
        ->assertDontSeeHtml('data-test="live-replay"');
});

test('実在の会社では再生しない（デモの記録が同じ写真でも AI を呼ぶ）', function (): void {
    practiceInDemoCompany(400);

    ['company' => $real, 'user' => $user] = createCompanyWithMember(Role::Owner);
    $fake = useReplayDriver();

    sendReceipt($real, $user, 400);

    expect($fake->requests)->toHaveCount(3)
        ->and($real->aiRuns()->where('driver', 'replay')->exists())->toBeFalse();
});

test('実在の会社の記録は、デモの会社でも再生に使わない', function (): void {
    ['company' => $real, 'user' => $realUser] = createCompanyWithMember(Role::Owner);
    sendReceipt($real, $realUser, 400);
    $real->aiRuns()->get()->each(fn (AiRun $run) => $run->forceFill(['driver' => 'claude'])->save());

    ['company' => $stage, 'user' => $user] = createCompanyWithMember(Role::Owner);
    $stage->forceFill(['is_demo' => true])->save();
    $fake = useReplayDriver();

    sendReceipt($stage, $user, 400);

    expect($fake->requests)->toHaveCount(3)
        ->and($stage->aiRuns()->where('driver', 'replay')->exists())->toBeFalse();
});

test('AI_DRIVER が claude・fake のときは再生しない', function (): void {
    practiceInDemoCompany(400);

    ['company' => $stage, 'user' => $user] = createCompanyWithMember(Role::Owner);
    $stage->forceFill(['is_demo' => true])->save();
    $fake = app(FakeClaudeClient::class);
    $fake->requests = [];

    sendReceipt($stage, $user, 400);

    expect($fake->requests)->toHaveCount(3);
});
