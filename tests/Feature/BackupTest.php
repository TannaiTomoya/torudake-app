<?php

use App\Enums\EntryStatus;
use App\Models\JournalEntry;
use App\Services\BackupManifest;
use App\Services\WorkbookService;
use App\Support\Heartbeat;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/**
 * T55：サーバーの外へのバックアップと復元の確認（#35）。数字はすべて架空。
 * 暗号化・送る・戻すの通しは docs/server/restore-drill.sh（開発の Sail で動かす練習）が確かめる。
 */
function writeManifest(?array $manifest = null): string
{
    $path = tempnam(sys_get_temp_dir(), 'manifest');
    file_put_contents($path, json_encode($manifest ?? app(BackupManifest::class)->build(), JSON_THROW_ON_ERROR));

    return $path;
}

function backupFixture(): array
{
    $fixture = createCompanyWithMember();
    putEntry($fixture['company'], '2026-08-03', '燃料', 5200);
    putEntry($fixture['company'], '2026-08-21', '売上', 220000);
    putEntry($fixture['company'], '2026-09-20', '雑費', 640, EntryStatus::Draft);

    return $fixture;
}

function freshSchedulerAndQueue(): void
{
    Heartbeat::touch(Heartbeat::SCHEDULER);
    Heartbeat::touch(Heartbeat::QUEUE);
}

test('控え：件数と貸借の合計だけで、名前・メール・取引先は入らない', function (): void {
    $fixture = backupFixture();

    $manifest = app(BackupManifest::class)->build();
    $json = (string) json_encode($manifest, JSON_UNESCAPED_UNICODE);

    expect($manifest['version'])->toBe(BackupManifest::VERSION)
        ->and($manifest['tables']['companies'])->toBe(1)
        ->and($manifest['tables']['users'])->toBe(1)
        ->and($manifest['tables']['journal_entries'])->toBe(3)
        ->and($manifest['tables']['journal_lines'])->toBe(6)
        ->and($manifest['journal_entries_by_status'])->toBe(['confirmed' => 2, 'draft' => 1])
        ->and($manifest['memberships_by_role'])->toBe(['owner' => 1])
        ->and($manifest['confirmed_debit_yen'])->toBe(225200)
        ->and($manifest['confirmed_credit_yen'])->toBe(225200)
        ->and($manifest['migrations'])->toBeGreaterThan(0)
        ->and($manifest['last_migration'])->toBeString();

    expect($json)->not->toContain($fixture['user']->email)
        ->not->toContain($fixture['user']->name)
        ->not->toContain('テスト建機')
        ->not->toContain('八幡平店');

    // 入っているのは数と、表・状態・役割・移行ファイルの名前だけ
    $flat = collect($manifest)->flatten()->reject(fn ($value) => is_int($value))->values();
    expect($flat->all())->toBe([$manifest['last_migration']]);
});

test('控え：動き続ける表と、作り直せる Excel の台帳は数えない', function (): void {
    expect(BackupManifest::TABLES)
        ->not->toContain('cache')
        ->not->toContain('jobs')
        ->not->toContain('sessions')
        ->not->toContain('password_reset_tokens')
        ->not->toContain('workbooks');
});

test('控えのコマンド：JSON だけを出す', function (): void {
    backupFixture();

    Artisan::call('torudake:backup-manifest');
    $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($decoded)->toBe(app(BackupManifest::class)->build());
});

test('控えの比べ方：同じなら空、違えばどの項目がいくつからいくつになったかを返す', function (): void {
    $fixture = backupFixture();
    $service = app(BackupManifest::class);
    $before = $service->build();

    expect($service->differences($before, $service->build()))->toBe([]);

    putEntry($fixture['company'], '2026-09-02', '消耗品', 1980);
    $differences = $service->differences($before, $service->build());

    expect($differences)->toContain('confirmed_credit_yen：バックアップ 225200 → 復元後 227180')
        ->toContain('journal_entries_by_status.confirmed：バックアップ 2 → 復元後 3')
        ->toContain('tables.journal_entries：バックアップ 3 → 復元後 4');

    // 片方にしかない項目も違いになる
    expect($service->differences(['tables' => ['users' => 1]], ['tables' => []]))
        ->toBe(['tables.users：バックアップ 1 → 復元後 （なし）']);
});

test('復元の確認：控えと同じ・アプリの鍵が合う・Excel を作り直せるなら成功し、2回目も成功する', function (): void {
    $fixture = backupFixture();
    $manifest = writeManifest();

    $this->artisan('torudake:verify-restore', ['manifest' => $manifest, '--rebuild-workbooks' => true])
        ->expectsOutputToContain('OK 件数と貸借の合計')
        ->expectsOutputToContain('OK アプリの鍵')
        ->expectsOutputToContain('OK Excel の作り直し：1 社')
        ->expectsOutputToContain('すべて合っています')
        ->assertSuccessful();

    $workbook = $fixture['company']->workbook()->firstOrFail();
    expect($workbook->next_row)->toBe(4)
        ->and(Storage::disk(WorkbookService::DISK)->exists($workbook->storage_key))->toBeTrue();

    // 確認が Excel の台帳を作っても、控えとの比べは変わらない
    $this->artisan('torudake:verify-restore', ['manifest' => $manifest])->assertSuccessful();
});

test('復元の確認：中身が控えと違えば失敗し、違う項目を出す（名前は出さない）', function (): void {
    $fixture = backupFixture();
    $manifest = writeManifest();

    // 戻したあとに、下書きの1件が確定に変わった
    /** @var JournalEntry $entry */
    $entry = $fixture['company']->journalEntries()->where('status', EntryStatus::Draft)->firstOrFail();
    $entry->forceFill(['status' => EntryStatus::Confirmed, 'confirmed_at' => now()])->save();

    Artisan::call('torudake:verify-restore', ['manifest' => $manifest, '--skip-workbooks' => true]);
    $output = Artisan::output();

    expect($output)->toContain('NG 件数か貸借の合計が、控えと違う')
        ->toContain('journal_entries_by_status.confirmed：バックアップ 2 → 復元後 3')
        ->toContain('confirmed_debit_yen：バックアップ 225200 → 復元後 225840')
        ->toContain('問題が')
        ->not->toContain($fixture['user']->email)
        ->not->toContain('テスト建機');

    $this->artisan('torudake:verify-restore', ['manifest' => $manifest, '--skip-workbooks' => true])->assertFailed();
});

test('復元の確認：アプリの鍵が違うと失敗する（2段階認証の秘密を読めない）', function (): void {
    backupFixture();
    $manifest = writeManifest();

    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    app()->forgetInstance('encrypter');

    $this->artisan('torudake:verify-restore', ['manifest' => $manifest, '--skip-workbooks' => true])
        ->expectsOutputToContain('NG アプリの鍵（APP_KEY）')
        ->assertFailed();
});

test('復元の確認：2段階認証を設定した人がいなければ、鍵は確かめられないと出して先へ進む', function (): void {
    createCompanyWithMember(withTwoFactor: false);

    $this->artisan('torudake:verify-restore', ['manifest' => writeManifest(), '--skip-workbooks' => true])
        ->expectsOutputToContain('確かめられない')
        ->assertSuccessful();
});

test('復元の確認：Excel のファイルが壊れていれば失敗し、作り直すと通る', function (): void {
    $fixture = backupFixture();
    $manifest = writeManifest();
    $workbook = app(WorkbookService::class)->ensureWorkbook($fixture['company']);
    app(WorkbookService::class)->contents($fixture['company']);

    Storage::disk(WorkbookService::DISK)->put($workbook->storage_key, 'これは Excel ではない');

    $this->artisan('torudake:verify-restore', ['manifest' => $manifest])
        ->expectsOutputToContain('NG Excel')
        ->assertFailed();

    $this->artisan('torudake:verify-restore', ['manifest' => $manifest, '--rebuild-workbooks' => true])->assertSuccessful();

    expect(Storage::disk(WorkbookService::DISK)->get($workbook->storage_key))->toStartWith('PK')
        ->and($workbook->refresh()->next_row)->toBe(4);
});

test('復元の確認：控えがない・JSON でない・版が違うときは失敗する', function (mixed $contents): void {
    $path = tempnam(sys_get_temp_dir(), 'manifest');
    if ($contents === null) {
        unlink($path);
    } else {
        file_put_contents($path, $contents);
    }

    $this->artisan('torudake:verify-restore', ['manifest' => $path])->assertFailed();
})->with([
    'ない' => [null],
    'JSON でない' => ['これは控えではない'],
    '版が違う' => ['{"version":999,"tables":{}}'],
    '配列でない' => ['"version"'],
]);

test('Excel の作り直し：ファイルを捨てて DB から入れ直し、下書きは入らない', function (): void {
    $fixture = backupFixture();
    $service = app(WorkbookService::class);
    $service->contents($fixture['company']);
    $workbook = $fixture['company']->workbook()->firstOrFail();
    $versionBefore = $workbook->version;

    $contents = $service->rebuild($fixture['company']);

    expect($contents)->toStartWith('PK')
        ->and($workbook->refresh()->next_row)->toBe(4)
        ->and($workbook->version)->toBeGreaterThan($versionBefore)
        ->and($fixture['company']->journalEntries()->whereNotNull('workbook_row')->count())->toBe(2);
});

test('成功の記録：バックアップの印を書く', function (): void {
    expect(Heartbeat::ageSeconds(Heartbeat::BACKUP))->toBeNull();

    $this->artisan('torudake:backup-recorded')->assertSuccessful();

    expect(Heartbeat::ageSeconds(Heartbeat::BACKUP))->toBe(0);
});

test('稼働確認：バックアップを見ない設定（0）なら、印がなくても 200', function (): void {
    freshSchedulerAndQueue();
    config(['torudake.health.backup_max_age_hours' => 0]);

    $this->getJson(route('health'))->assertOk();
});

test('稼働確認：バックアップを見る設定で、一度も成功していなければ 503（理由は応答に出さない）', function (): void {
    freshSchedulerAndQueue();
    config(['torudake.health.backup_max_age_hours' => 26]);

    $this->getJson(route('health'))->assertStatus(503)->assertExactJson(['app' => 'torudake', 'ok' => false]);
});

test('稼働確認：バックアップの印が期限内なら 200、過ぎたら 503、取り直すと 200 に戻る', function (): void {
    config(['torudake.health.backup_max_age_hours' => 26]);
    $this->artisan('torudake:backup-recorded');

    $this->travel(25)->hours();
    freshSchedulerAndQueue();
    $this->getJson(route('health'))->assertOk();

    $this->travel(2)->hours();
    freshSchedulerAndQueue();
    $this->getJson(route('health'))->assertStatus(503);

    $this->artisan('torudake:backup-recorded');
    $this->getJson(route('health'))->assertOk();
});

test('稼働確認：バックアップを見る時間は、設定しなければ 0（見ない）', function (): void {
    expect(config('torudake.health.backup_max_age_hours'))->toBe(0);
});
