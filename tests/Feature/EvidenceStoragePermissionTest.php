<?php

use App\Enums\AiStatus;
use App\Enums\ReportStatus;
use App\Enums\Role;
use App\Models\Evidence;
use App\Services\EvidenceFileStorage;
use App\Services\ReportImportService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->storageRoot = sys_get_temp_dir().'/torudake-permissions-'.Str::uuid7();
    File::makeDirectory($this->storageRoot, 0700);
    // 本番の setup-torudake.sh と同じく、最上位で共有グループを継承させる。
    File::makeDirectory($this->storageRoot.'/evidences', 0700);
    chmod($this->storageRoot.'/evidences', 02770);
    config(['filesystems.disks.evidence.root' => $this->storageRoot.'/evidences']);
    Storage::forgetDisk('evidence');
});

afterEach(function (): void {
    Storage::forgetDisk('evidence');
    File::deleteDirectory($this->storageRoot);
});

test('T45: レシートと帳票は厳しいumaskでも共有グループが読み書きでき、その他には公開しない', function (bool $report): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Owner);
    $previousMask = umask(0077);
    try {
        if ($report) {
            $import = app(ReportImportService::class)->upload($company, $user, UploadedFile::fake()->image('report.jpg', 800, 600));
            expect($import->fresh()->status)->toBe(ReportStatus::Review);
            $evidence = Evidence::findOrFail($import->evidence_id);
        } else {
            $source = sendReceipt($company, $user);
            expect($source->ai_status)->toBe(AiStatus::Done);
            $evidence = Evidence::findOrFail($source->evidence_id);
        }
        expect(umask())->toBe(0077);
    } finally {
        umask($previousMask);
    }

    $disk = Storage::disk('evidence');
    $root = $disk->path('');
    $file = $disk->path((string) $evidence->storage_key);
    clearstatcache();
    foreach ([$root, $root.'companies', dirname($file)] as $directory) {
        expect(fileperms($directory) & 07777)->toBe(02770);
    }
    expect(fileperms($file) & 0777)->toBe(0660)
        ->and(filegroup($file))->toBe(filegroup($root))
        ->and(filegroup(dirname($file)))->toBe(filegroup($root))
        ->and($disk->getVisibility((string) $evidence->storage_key))->toBe('private')
        ->and(config('filesystems.disks.evidence.serve'))->toBeFalse();
})->with(['レシート' => false, '帳票' => true]);

test('T45: 写真保存に失敗しても呼び出し元のumaskを戻す', function (): void {
    ['company' => $company] = createCompanyWithMember();
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('putFileAs')->once()->andThrow(new RuntimeException('保存失敗'));
    Storage::shouldReceive('disk')->once()->with('evidence')->andReturn($disk);
    Storage::shouldReceive('forgetDisk')->with('evidence');
    $previousMask = umask(0077);
    try {
        expect(fn () => app(EvidenceFileStorage::class)->put($company, UploadedFile::fake()->image('receipt.jpg'), 'test.jpg'))
            ->toThrow(RuntimeException::class, '保存失敗');
        expect(umask())->toBe(0077);
    } finally {
        umask($previousMask);
    }
});

test('T45: 実ファイルの共有権限を付けても他社の写真は404・他の送信者の写真は403・未ログインはログインへ戻す', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember();
    ['user' => $otherSubmitter] = createCompanyWithMember(Role::Editor, submitOnly: true, company: $company);
    ['company' => $otherCompany, 'user' => $outsider] = createCompanyWithMember();
    $source = sendReceipt($company, $owner);
    $evidence = Evidence::findOrFail($source->evidence_id);
    $url = route('company.evidences.image', [$company, $evidence->id]);
    $before = Storage::disk('evidence')->get((string) $evidence->storage_key);

    $this->actingAs($owner)->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $this->actingAs($outsider)->get($url)->assertNotFound();
    $this->actingAs($outsider)->get(route('company.evidences.image', [$otherCompany, $evidence->id]))->assertNotFound();
    $this->actingAs($otherSubmitter)->get($url)->assertForbidden();
    auth()->logout();
    $this->get($url)->assertRedirect(route('login'));

    expect(Storage::disk('evidence')->get((string) $evidence->storage_key))->toBe($before)
        ->and($evidence->fresh()->storage_key)->toBe($evidence->storage_key);
});

test('T45: デプロイの権限検査は非公開の共有ディレクトリだけを許可する', function (int $mode, bool $allowed): void {
    $root = $this->storageRoot.'/check';
    File::makeDirectory($root, 0700);
    chmod($root, $mode);
    $group = posix_getgrgid(filegroup($root));
    $process = new Process(['bash', base_path('docs/server/check-evidence-permissions.sh'), $root, $group['name']]);
    $process->run();
    expect($process->isSuccessful())->toBe($allowed)
        ->and(fileperms($root) & 07777)->toBe($mode);
    if (! $allowed) {
        expect($process->getErrorOutput())->toContain('写真保存先の権限が不正');
    }
})->with([
    '非公開の共有' => [02770, true],
    '所有者だけ' => [0700, false],
    'その他にも公開' => [02777, false],
    'グループを継承しない' => [0770, false],
]);

test('T45: デプロイの権限検査は誤ったグループとシンボリックリンクを拒否する', function (bool $link): void {
    $root = $this->storageRoot.'/check';
    File::makeDirectory($root, 0700);
    chmod($root, 02770);
    $group = posix_getgrgid(filegroup($root));
    $target = $root;
    if ($link) {
        $target = $this->storageRoot.'/link';
        symlink($root, $target);
    }
    $process = new Process(['bash', base_path('docs/server/check-evidence-permissions.sh'), $target, $link ? $group['name'] : 'wrong-group']);
    $process->run();
    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput())->toContain($link ? '通常のディレクトリではありません' : '写真保存先の権限が不正');
})->with([false, true]);
