<?php

use App\Enums\AiStatus;
use App\Enums\Role;
use App\Enums\SourceStatus;
use App\Jobs\ProcessEvidenceWithAi;
use App\Models\AuditLog;
use App\Models\Evidence;
use App\Models\SourceRecord;
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

    Livewire::actingAs($user)
        ->test('pages::company.submit', ['company' => $company])
        ->set('photo', UploadedFile::fake()->create('receipt.heic', 10, 'image/heic'))
        ->assertHasErrors(['photo'])
        ->assertSet('state', 'waiting');

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
