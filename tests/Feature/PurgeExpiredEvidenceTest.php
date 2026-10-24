<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Evidence;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('evidence');
    Storage::fake('local');
});

test('T22: 30日を過ぎた写真のファイルだけが消え、行・全文・仕訳は残り、監査記録が付く', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Admin);

    $old = sendReceipt($company, $user);
    $new = sendReceipt($company, $user, 500);
    $oldEvidence = Evidence::findOrFail($old->evidence_id);
    $newEvidence = Evidence::findOrFail($new->evidence_id);
    $oldKey = (string) $oldEvidence->storage_key;
    $oldEvidence->forceFill(['purge_after' => now()->subMinute()])->save();

    $this->artisan('torudake:purge-evidence')
        ->expectsOutput('写真を1枚消しました。')
        ->assertSuccessful();

    $oldEvidence->refresh();
    Storage::disk('evidence')->assertMissing($oldKey);
    Storage::disk('evidence')->assertExists((string) $newEvidence->fresh()?->storage_key);

    expect($oldEvidence->storage_key)->toBeNull()
        ->and($oldEvidence->purged_at)->not->toBeNull()
        ->and($oldEvidence->isPurged())->toBeTrue()
        ->and($oldEvidence->sha256)->not->toBe('')
        ->and($old->fresh()?->journalEntry)->not->toBeNull()
        ->and($newEvidence->fresh()?->purged_at)->toBeNull();

    $log = AuditLog::where('action', 'evidence.purge')->sole();
    expect($log->company_id)->toBe($company->id)
        ->and($log->subject_id)->toBe($oldEvidence->id)
        ->and($log->user_id)->toBeNull();

    $this->artisan('torudake:purge-evidence')->expectsOutput('写真を0枚消しました。');
    expect(AuditLog::where('action', 'evidence.purge')->count())->toBe(1);
});

test('消した写真は、原資料とライブ画面で「保存期間が過ぎたため削除」と出て、写真の URL は 410', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Admin);

    $source = sendReceipt($company, $user);
    Evidence::findOrFail($source->evidence_id)->forceFill(['purge_after' => now()->subMinute()])->save();
    $this->artisan('torudake:purge-evidence')->assertSuccessful();

    $this->actingAs($user)->get(route('company.sources.show', [$company, $source]))
        ->assertOk()
        ->assertSee('写真は保存期間が過ぎたため削除しました')
        ->assertDontSee('PDF を開く');

    $this->actingAs($user)->get(route('company.live', $company))
        ->assertOk()
        ->assertSee('data-test="live-photo-purged"', false)
        ->assertDontSee('>PDF<', false);

    $this->actingAs($user)->get(route('company.evidences.image', [$company, $source->evidence_id]))->assertStatus(410);
});

test('写真の削除は毎日3時に動く', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'torudake:purge-evidence'));

    expect($event)->not->toBeNull()
        ->and($event?->expression)->toBe('0 3 * * *');
});
