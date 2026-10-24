<?php

use App\Enums\Role;
use App\Models\SourceRecord;
use App\Services\SubmitEvidence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('evidence');
});

test('T18: 3枚まとめて送り、1枚が重複でもほかの2枚は送れる。同じ batch_id が付く', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor, submitOnly: true);

    $old = UploadedFile::fake()->image('old.jpg', 300, 400);
    $oldBytes = (string) file_get_contents((string) $old->getRealPath());
    app(SubmitEvidence::class)->submit($company, $user, $old);

    Livewire::actingAs($user)
        ->test('pages::company.submit', ['company' => $company])
        ->set('photo', UploadedFile::fake()->image('a.jpg', 310, 400))
        ->assertSet('state', 'confirm')
        ->set('photo', UploadedFile::fake()->createWithContent('same.jpg', $oldBytes))
        ->set('picked', [UploadedFile::fake()->image('b.jpg', 320, 400)])
        ->assertCount('photos', 3)
        ->assertSee('まとめて送る（3枚）')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('state', 'sent')
        ->assertSet('sentCount', 2)
        ->assertSet('duplicateCount', 1)
        ->assertSee('送れました（2枚）')
        ->assertSee('うち1枚は、もう届いている写真でした')
        ->assertCount('photos', 0);

    $batch = SourceRecord::query()->whereNotNull('batch_id')->get();
    expect(SourceRecord::count())->toBe(3)
        ->and($batch)->toHaveCount(2)
        ->and($batch->pluck('batch_id')->unique())->toHaveCount(1);
});

test('まとめて送る：×で外した写真は送られない。10枚を超えてはためられない', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);

    $component = Livewire::actingAs($user)
        ->test('pages::company.submit', ['company' => $company])
        ->set('picked', [
            UploadedFile::fake()->image('a.jpg', 200, 300),
            UploadedFile::fake()->image('b.jpg', 210, 300),
        ])
        ->assertCount('photos', 2)
        ->call('removePhoto', 0)
        ->assertCount('photos', 1)
        ->call('send')
        ->assertSet('sentCount', 1);

    expect(SourceRecord::count())->toBe(1);

    $component->call('retake');
    $files = array_map(fn (int $i) => UploadedFile::fake()->image("p{$i}.jpg", 100 + $i, 100), range(1, 11));
    $component->set('picked', $files)
        ->assertCount('photos', 10)
        ->assertHasErrors('photo');

    $component->call('removePhoto', 0)->call('removePhoto', 0)->assertCount('photos', 8);
    $component->call('removePhoto', 99)->assertCount('photos', 8);
});

test('まとめて送る：送れなかった写真は並びに残り、もう一度送れる', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);

    $real = app(SubmitEvidence::class);
    $calls = 0;
    $failing = Mockery::mock(SubmitEvidence::class);
    $failing->shouldReceive('submit')->andReturnUsing(function (...$args) use ($real, &$calls) {
        $calls++;
        if ($calls === 2) {
            throw new RuntimeException('電波が切れた');
        }

        return $real->submit(...$args);
    });
    app()->instance(SubmitEvidence::class, $failing);

    $component = Livewire::actingAs($user)
        ->test('pages::company.submit', ['company' => $company])
        ->set('picked', [
            UploadedFile::fake()->image('a.jpg', 200, 300),
            UploadedFile::fake()->image('b.jpg', 210, 300),
        ])
        ->call('send')
        ->assertSet('state', 'sent')
        ->assertSet('sentCount', 1)
        ->assertSet('failedCount', 1)
        ->assertCount('photos', 1)
        ->assertSee('1枚は送れませんでした');

    $component->call('send')
        ->assertSet('state', 'sent')
        ->assertSet('failedCount', 0)
        ->assertCount('photos', 0);

    expect(SourceRecord::count())->toBe(2);
});

test('まとめて送る：全部が重複なら「もう届いています」、閲覧のみの人は送れない', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);
    ['user' => $viewer] = createCompanyWithMember(Role::Viewer, company: $company);

    $file = UploadedFile::fake()->image('a.jpg', 200, 300);
    $bytes = (string) file_get_contents((string) $file->getRealPath());
    app(SubmitEvidence::class)->submit($company, $user, $file);

    Livewire::actingAs($user)
        ->test('pages::company.submit', ['company' => $company])
        ->set('photo', UploadedFile::fake()->createWithContent('same.jpg', $bytes))
        ->call('send')
        ->assertSet('state', 'duplicate')
        ->assertSee('この写真はもう届いています');

    $this->actingAs($viewer)->get(route('company.submit', $company))->assertForbidden();
    expect(SourceRecord::count())->toBe(1);
});
