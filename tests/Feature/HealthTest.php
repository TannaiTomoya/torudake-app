<?php

use App\Console\Commands\HeartbeatCommand;
use App\Jobs\QueueHeartbeat;
use App\Services\HealthCheck;
use App\Support\Heartbeat;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

function freshHeartbeats(): void
{
    Heartbeat::touch(Heartbeat::SCHEDULER);
    Heartbeat::touch(Heartbeat::QUEUE);
}

test('稼働確認：印が新しく DB につながれば 200。応答はこのアプリの印と ok だけで、構成や件数は出さない', function (): void {
    freshHeartbeats();
    createCompanyWithMember();

    $response = $this->getJson(route('health'));

    $response->assertOk()->assertExactJson(['app' => 'torudake', 'ok' => true]);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('稼働確認：定期処理かキューの印が古い・一度もないときは 503（理由は応答に出さない）', function (string $stale): void {
    freshHeartbeats();
    $this->travel(15)->minutes();
    Heartbeat::touch($stale === 'scheduler' ? Heartbeat::QUEUE : Heartbeat::SCHEDULER);

    $this->getJson(route('health'))->assertStatus(503)->assertExactJson(['app' => 'torudake', 'ok' => false]);
})->with(['scheduler', 'queue']);

test('稼働確認：印が一度も書かれていなければ 503', function (): void {
    $this->getJson(route('health'))->assertStatus(503)->assertJson(['ok' => false]);
});

test('稼働確認：DB につながらなければ 503', function (): void {
    freshHeartbeats();
    $this->partialMock(HealthCheck::class, fn ($mock) => $mock->shouldReceive('database')->andReturn(false));

    $this->getJson(route('health'))->assertStatus(503)->assertExactJson(['app' => 'torudake', 'ok' => false]);
});

test('稼働確認：印は設定した秒数までは新しいとみなす', function (): void {
    freshHeartbeats();
    $this->travel((int) config('torudake.health.scheduler_max_age_seconds') - 5)->seconds();
    Heartbeat::touch(Heartbeat::QUEUE);

    $this->getJson(route('health'))->assertOk();

    $this->travel(10)->seconds();
    Heartbeat::touch(Heartbeat::QUEUE);

    $this->getJson(route('health'))->assertStatus(503);
});

test('生きている印：毎分のコマンドが定期処理の印を書き、キューの仕事を流す。キューが処理して初めてキューの印が書かれる', function (): void {
    Queue::fake();

    $this->artisan('torudake:heartbeat')->assertSuccessful();

    expect(Heartbeat::ageSeconds(Heartbeat::SCHEDULER))->toBe(0)
        ->and(Heartbeat::ageSeconds(Heartbeat::QUEUE))->toBeNull();
    Queue::assertPushed(QueueHeartbeat::class, 1);

    (new QueueHeartbeat)->handle();

    expect(Heartbeat::ageSeconds(Heartbeat::QUEUE))->toBe(0);
});

test('生きている印：毎分実行するよう予定に入っている', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'torudake:heartbeat'));

    expect($events)->toHaveCount(1)->and($events->first()?->expression)->toBe('* * * * *');
    expect(class_exists(HeartbeatCommand::class))->toBeTrue();
});
