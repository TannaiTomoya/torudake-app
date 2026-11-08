<?php

use App\Enums\AiStatus;
use App\Enums\Role;
use App\Enums\SourceStatus;
use App\Jobs\ProcessEvidenceWithAi;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Evidence;
use App\Models\SourceRecord;
use App\Models\User;
use App\Services\PurgeExpiredEvidence;
use App\Services\SubmitEvidence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('evidence');
    Storage::fake('local');
});

test('T06: 写真を送ると原資料と証憑ができ、ファイルが置かれ、ジョブが積まれる', function (): void {
    Queue::fake();
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor, submitOnly: true);

    Livewire::actingAs($user)
        ->test('pages::company.submit', ['company' => $company])
        ->assertSet('state', 'waiting')
        ->set('photo', UploadedFile::fake()->image('receipt.jpg', 600, 800))
        ->assertHasNoErrors()
        ->assertSet('state', 'confirm')
        ->set('note', '現場の部品')
        ->call('send')
        ->assertSet('state', 'sent')
        ->assertSet('monthCount', 1)
        ->assertSee('送れました');

    $source = SourceRecord::firstOrFail();
    $evidence = Evidence::firstOrFail();

    expect($source->company_id)->toBe($company->id)
        ->and($source->status)->toBe(SourceStatus::New)
        ->and($source->ai_status)->toBe(AiStatus::Waiting)
        ->and($source->submitted_by)->toBe($user->id)
        ->and($source->note)->toBe('現場の部品')
        ->and($source->evidence_id)->toBe($evidence->id)
        ->and($evidence->mime)->toBe('image/jpeg')
        ->and($evidence->storage_key)->toStartWith("companies/{$company->id}/")
        ->and($evidence->purge_after->toDateString())->toBe(now()->addDays(30)->toDateString());

    Storage::disk('evidence')->assertExists((string) $evidence->storage_key);
    Queue::assertPushed(ProcessEvidenceWithAi::class, fn (ProcessEvidenceWithAi $job): bool => $job->sourceRecordId === $source->id);

    expect(AuditLog::where('action', 'evidence.submit')->where('company_id', $company->id)->exists())->toBeTrue();
});

test('T07: 画像・PDF以外と10MB超は弾かれ、行もファイルもできない', function (): void {
    Queue::fake();
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);

    Livewire::actingAs($user)
        ->test('pages::company.submit', ['company' => $company])
        ->set('photo', UploadedFile::fake()->create('memo.txt', 10, 'text/plain'))
        ->assertHasErrors(['photo'])
        ->assertSet('state', 'waiting')
        ->call('send')
        ->assertHasErrors(['photo']);

    foreach (['heic', 'heif'] as $format) {
        Livewire::actingAs($user)
            ->test('pages::company.submit', ['company' => $company])
            ->set('photo', UploadedFile::fake()->create("receipt.{$format}", 10, "image/{$format}"))
            ->assertHasErrors(['photo'])
            ->assertSet('state', 'waiting');
    }

    Livewire::actingAs($user)
        ->test('pages::company.submit', ['company' => $company])
        ->set('photo', UploadedFile::fake()->create('big.jpg', 10241, 'image/jpeg'))
        ->assertHasErrors(['photo'])
        ->assertSet('state', 'waiting');

    expect(SourceRecord::count())->toBe(0)
        ->and(Evidence::count())->toBe(0)
        ->and(Storage::disk('evidence')->allFiles())->toBe([]);

    Queue::assertNothingPushed();
});

test('T08: 同じ写真を2回送ると1件だけで、2回目は「もう届いています」', function (): void {
    Queue::fake();
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);

    $first = UploadedFile::fake()->image('receipt.jpg', 640, 480);
    $bytes = (string) file_get_contents((string) $first->getRealPath());

    Livewire::actingAs($user)
        ->test('pages::company.submit', ['company' => $company])
        ->set('photo', $first)
        ->call('send')
        ->assertSet('state', 'sent');

    Livewire::actingAs($user)
        ->test('pages::company.submit', ['company' => $company])
        ->set('photo', UploadedFile::fake()->createWithContent('same.jpg', $bytes))
        ->call('send')
        ->assertSet('state', 'duplicate')
        ->assertSee('この写真はもう届いています');

    expect(SourceRecord::count())->toBe(1)
        ->and(Evidence::count())->toBe(1)
        ->and(Storage::disk('evidence')->allFiles())->toHaveCount(1);

    Queue::assertPushed(ProcessEvidenceWithAi::class, 1);
});

test('T08: 別の会社なら同じ写真でも受け付ける', function (): void {
    Queue::fake();
    ['company' => $companyA, 'user' => $userA] = createCompanyWithMember(Role::Editor);
    ['company' => $companyB, 'user' => $userB] = createCompanyWithMember(Role::Editor);

    $first = UploadedFile::fake()->image('receipt.jpg', 640, 480);
    $bytes = (string) file_get_contents((string) $first->getRealPath());

    Livewire::actingAs($userA)->test('pages::company.submit', ['company' => $companyA])
        ->set('photo', $first)->call('send')->assertSet('state', 'sent');

    Livewire::actingAs($userB)->test('pages::company.submit', ['company' => $companyB])
        ->set('photo', UploadedFile::fake()->createWithContent('same.jpg', $bytes))->call('send')->assertSet('state', 'sent');

    expect(SourceRecord::count())->toBe(2);
});

test('写真は権限のある人だけに返り、他社は404・送るだけの人は自分のものだけ・消えた写真は410', function (): void {
    Queue::fake();
    ['company' => $company, 'user' => $submitter] = createCompanyWithMember(Role::Editor, submitOnly: true);
    ['user' => $otherSubmitter] = createCompanyWithMember(Role::Editor, submitOnly: true, company: $company);
    ['user' => $editor] = createCompanyWithMember(Role::Editor, company: $company);
    ['user' => $outsider] = createCompanyWithMember(Role::Owner);

    Livewire::actingAs($submitter)->test('pages::company.submit', ['company' => $company])
        ->set('photo', UploadedFile::fake()->image('receipt.jpg', 300, 300))->call('send')->assertSet('state', 'sent');

    $evidence = Evidence::firstOrFail();
    $url = route('company.evidences.image', [$company, $evidence->id]);

    $this->actingAs($submitter)->get($url)
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');
    expect((string) $this->actingAs($submitter)->get($url)->headers->get('Cache-Control'))->toContain('no-store');

    $this->actingAs($editor)->get($url)->assertOk();
    $this->actingAs($otherSubmitter)->get($url)->assertForbidden();
    $this->actingAs($outsider)->get($url)->assertNotFound();

    Storage::disk('evidence')->delete((string) $evidence->storage_key);
    $evidence->forceFill(['storage_key' => null, 'purged_at' => now()])->save();

    $this->actingAs($editor)->get($url)->assertStatus(410);
});

test('閲覧のみの人は送る画面を開けず、送る操作も403', function (): void {
    ['company' => $company, 'user' => $viewer] = createCompanyWithMember(Role::Viewer);

    $this->actingAs($viewer)->get(route('company.submit', $company))->assertForbidden();

    expect(SourceRecord::count())->toBe(0);
});

test('原資料の一覧はその会社のものだけを出す', function (): void {
    Queue::fake();
    ['company' => $companyA, 'user' => $userA] = createCompanyWithMember(Role::Editor);
    ['company' => $companyB, 'user' => $userB] = createCompanyWithMember(Role::Editor);

    Livewire::actingAs($userA)->test('pages::company.submit', ['company' => $companyA])
        ->set('photo', UploadedFile::fake()->image('a.jpg', 200, 210))->set('note', 'A社のメモ')->call('send');
    Livewire::actingAs($userB)->test('pages::company.submit', ['company' => $companyB])
        ->set('photo', UploadedFile::fake()->image('b.jpg', 220, 230))->call('send');

    $sourceA = SourceRecord::where('company_id', $companyA->id)->firstOrFail();
    $sourceB = SourceRecord::where('company_id', $companyB->id)->firstOrFail();

    $this->actingAs($userA)->get(route('company.sources.index', $companyA))
        ->assertOk()
        ->assertSee($sourceA->evidence_id)
        ->assertDontSee($sourceB->evidence_id);
});

/**
 * 送るだけの人 A が先に送った写真と、同じ中身のファイルを返す。
 *
 * @return array{company: Company, a: User, bytes: string, source: SourceRecord}
 */
function companyWithWorkerPhoto(): array
{
    ['company' => $company, 'user' => $a] = createCompanyWithMember(Role::Editor, submitOnly: true);

    $file = UploadedFile::fake()->image('a.jpg', 321, 456);
    $bytes = (string) file_get_contents((string) $file->getRealPath());
    $source = app(SubmitEvidence::class)->submit($company, $a, $file)->source?->fresh() ?? throw new RuntimeException('原資料ができていません');

    expect($source->vendor)->not->toBeNull();

    return ['company' => $company, 'a' => $a, 'bytes' => $bytes, 'source' => $source];
}

test('重複通知：別の送るだけの人 B には、A の日付・店名・ID・画像の道を返さない（Livewire の状態にも入らない）', function (): void {
    ['company' => $company, 'bytes' => $bytes, 'source' => $source] = companyWithWorkerPhoto();
    ['user' => $b] = createCompanyWithMember(Role::Editor, submitOnly: true, company: $company);

    $result = app(SubmitEvidence::class)->submit($company, $b, UploadedFile::fake()->createWithContent('same.jpg', $bytes));

    expect($result->duplicate)->toBeTrue()->and($result->source)->toBeNull();

    $component = Livewire::actingAs($b)
        ->test('pages::company.submit', ['company' => $company])
        ->set('photo', UploadedFile::fake()->createWithContent('same2.jpg', $bytes))
        ->call('send')
        ->assertSet('state', 'duplicate')
        ->assertSet('duplicateOf', 'すでに受け付けた写真です')
        ->assertSee('すでに受け付けた写真です')
        ->assertDontSee((string) $source->vendor)
        ->assertDontSee($source->id)
        ->assertDontSee((string) $source->evidence_id);

    expect(json_encode($component->instance()->all()))->not->toContain($source->id)
        ->and(SourceRecord::count())->toBe(1)
        ->and(Evidence::count())->toBe(1);
});

test('重複通知：同じ送るだけの人 A には、自分が送った日付と店名を出す', function (): void {
    ['company' => $company, 'a' => $a, 'bytes' => $bytes, 'source' => $source] = companyWithWorkerPhoto();

    Livewire::actingAs($a)
        ->test('pages::company.submit', ['company' => $company])
        ->set('photo', UploadedFile::fake()->createWithContent('same.jpg', $bytes))
        ->call('send')
        ->assertSet('state', 'duplicate')
        ->assertSee((string) $source->vendor)
        ->assertSee('に送った');

    expect(SourceRecord::count())->toBe(1);
});

test('重複通知：資料を読める経理には、送るだけの人が送った日付と店名も出す', function (): void {
    ['company' => $company, 'bytes' => $bytes, 'source' => $source] = companyWithWorkerPhoto();
    ['user' => $editor] = createCompanyWithMember(Role::Editor, company: $company);

    Livewire::actingAs($editor)
        ->test('pages::company.submit', ['company' => $company])
        ->set('photo', UploadedFile::fake()->createWithContent('same.jpg', $bytes))
        ->call('send')
        ->assertSet('state', 'duplicate')
        ->assertSee((string) $source->vendor);

    expect(SourceRecord::count())->toBe(1);
});

test('重複通知：別の会社の人には、同じ写真でも他社の存在や情報を出さず、独立して受け付ける', function (): void {
    ['bytes' => $bytes, 'source' => $source] = companyWithWorkerPhoto();
    ['company' => $other, 'user' => $c] = createCompanyWithMember(Role::Editor, submitOnly: true);

    $result = app(SubmitEvidence::class)->submit($other, $c, UploadedFile::fake()->createWithContent('same.jpg', $bytes));

    expect($result->duplicate)->toBeFalse()
        ->and($result->source?->company_id)->toBe($other->id)
        ->and($result->source?->id)->not->toBe($source->id);
});

test('重複通知：同じバッチの同じ写真は1件だけ受け付け、写真が30日で消えたあとに送り直しても原資料もAIも増えない', function (): void {
    Queue::fake();
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor, submitOnly: true);

    $file = UploadedFile::fake()->image('a.jpg', 210, 310);
    $bytes = (string) file_get_contents((string) $file->getRealPath());

    Livewire::actingAs($user)
        ->test('pages::company.submit', ['company' => $company])
        ->set('photos', [UploadedFile::fake()->createWithContent('1.jpg', $bytes), UploadedFile::fake()->createWithContent('2.jpg', $bytes)])
        ->call('send')
        ->assertSet('sentCount', 1)
        ->assertSet('duplicateCount', 1);

    $evidence = Evidence::query()->firstOrFail();
    $evidence->forceFill(['purge_after' => now()->subDay()])->save();
    expect(app(PurgeExpiredEvidence::class)->run())->toBe(1);

    Livewire::actingAs($user)
        ->test('pages::company.submit', ['company' => $company])
        ->set('photo', UploadedFile::fake()->createWithContent('3.jpg', $bytes))
        ->call('send')
        ->assertSet('state', 'duplicate');

    expect(SourceRecord::count())->toBe(1)
        ->and(Evidence::count())->toBe(1);
    Queue::assertPushed(ProcessEvidenceWithAi::class, 1);
});

test('重複通知：所属を止められた人は、送る画面も更新の通信も開けず、原資料は増えない', function (): void {
    ['company' => $company, 'bytes' => $bytes] = companyWithWorkerPhoto();
    ['user' => $b, 'membership' => $membership] = createCompanyWithMember(Role::Editor, submitOnly: true, company: $company);

    $component = Livewire::actingAs($b)->test('pages::company.submit', ['company' => $company]);

    $membership->forceFill(['disabled_at' => now()])->save();

    $this->actingAs($b)->get(route('company.submit', $company))->assertNotFound();
    Livewire::actingAs($b)->test('pages::company.submit', ['company' => $company])->assertForbidden();

    expect(fn () => $component->set('photo', UploadedFile::fake()->createWithContent('same.jpg', $bytes))->call('send'))
        ->toThrow(Exception::class);
    expect(SourceRecord::count())->toBe(1)->and(Evidence::count())->toBe(1);
});
