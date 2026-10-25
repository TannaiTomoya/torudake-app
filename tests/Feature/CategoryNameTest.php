<?php

use App\Enums\AiStatus;
use App\Enums\AiTask;
use App\Enums\Role;
use App\Models\AiRun;
use App\Models\AuditLog;
use App\Services\Ai\AiException;
use App\Services\Ai\FakeClaudeClient;
use App\Services\AiDraftBuilder;
use App\Services\CompanySettingsService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(fn () => Storage::fake('evidence'));

test('T44: 有効な分類と同名への変更は拒否し、名前と監査を変えない', function (Role $role): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember($role);
    $id = categoryId($company, '消耗品');
    $count = AuditLog::query()->count();

    Livewire::actingAs($user)->test('pages::company.settings', ['company' => $company])
        ->call('renameCategory', $id, '  燃料  ')
        ->assertHasErrors('categoryName')
        ->assertSee('同じ名前の分類が既にあります');

    expect($company->categories()->findOrFail($id)->name)->toBe('消耗品')
        ->and(AuditLog::query()->count())->toBe($count);
})->with([Role::Owner, Role::Admin]);

test('T44: 重複エラー後に一意な名前へ変更でき、AIもその分類の勘定科目を使う', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember();
    $id = categoryId($company, '消耗品');
    $accountId = $company->categories()->findOrFail($id)->account_id;
    $component = Livewire::actingAs($user)->test('pages::company.settings', ['company' => $company]);
    $component->assertSee('名前を保存')->assertSeeHtml('id="category-name-'.$id.'"');
    $component->call('renameCategory', $id, '燃料')->assertHasErrors('categoryName');
    $component->call('renameCategory', $id, '  架空の備品  ')->assertHasNoErrors()
        ->assertDontSee('同じ名前の分類が既にあります')
        ->assertSee('分類名を保存しました。')->assertSeeHtml('value="架空の備品"');

    $component->call('renameCategory', $id, '燃料')->assertHasErrors('categoryName')
        ->assertDontSee('分類名を保存しました。');

    $log = AuditLog::query()->where('action', 'category.update')->sole();
    expect($company->categories()->findOrFail($id)->name)->toBe('架空の備品')
        ->and($log->before['name'])->toBe('消耗品')
        ->and($log->after['name'])->toBe('架空の備品');
    app(FakeClaudeClient::class)->withJournalize(['category' => '架空の備品']);
    $source = sendReceipt($company, $user);
    expect($source->ai_status)->toBe(AiStatus::Done)
        ->and($source->journalEntry?->category_id)->toBe($id)
        ->and($source->journalEntry?->lines()->where('side', 'debit')->sole()->account_id)->toBe($accountId);
});

test('T44: 他社の同名分類や使わない分類の名前は有効な分類の変更を妨げない', function (bool $archived): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember();
    $id = categoryId($company, '消耗品');
    if ($archived) {
        $company->categories()->findOrFail(categoryId($company, '燃料'))->forceFill(['archived_at' => now()])->save();
    } else {
        ['company' => $other] = createCompanyWithMember();
        $other->categories()->findOrFail(categoryId($other, '燃料'))->forceFill(['name' => '他社だけの分類'])->save();
    }
    $newName = $archived ? '燃料' : '他社だけの分類';

    Livewire::actingAs($user)->test('pages::company.settings', ['company' => $company])
        ->call('renameCategory', $id, $newName)->assertHasNoErrors();
    expect($company->categories()->findOrFail($id)->name)->toBe($newName);
})->with([false, true]);

test('T44: 自分と同じ名前への変更では重複エラーも変更監査も増えない', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember();
    Livewire::actingAs($user)->test('pages::company.settings', ['company' => $company])
        ->call('renameCategory', categoryId($company, '消耗品'), '消耗品')->assertHasNoErrors();
    expect(AuditLog::query()->where('action', 'category.update')->exists())->toBeFalse();
});

test('T44: 不正な分類名はサービスへ直接渡しても保存しない', function (string $name): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember();
    $id = categoryId($company, '消耗品');
    expect(fn () => app(CompanySettingsService::class)->renameCategory($company, $user, $id, $name))
        ->toThrow(ValidationException::class);
    expect($company->categories()->findOrFail($id)->name)->toBe('消耗品')
        ->and(AuditLog::query()->where('action', 'category.update')->exists())->toBeFalse();
})->with(['空白' => '   ', '長すぎる' => str_repeat('あ', 101)]);

test('T44: 設定を開いた後に経理ロールへ変わると分類名を変更できない', function (): void {
    ['company' => $company, 'user' => $user, 'membership' => $membership] = createCompanyWithMember();
    $component = Livewire::actingAs($user)->test('pages::company.settings', ['company' => $company]);
    $membership->forceFill(['role' => Role::Editor])->save();
    $component->call('renameCategory', categoryId($company, '消耗品'), '書換テスト')->assertForbidden();
    expect($company->categories()->where('name', '消耗品')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'category.update')->exists())->toBeFalse();
});

test('T44: 他社の分類IDは変更できず、名前と監査を変えない', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember();
    ['company' => $other] = createCompanyWithMember();
    $foreignId = categoryId($other, '消耗品');
    Livewire::actingAs($user)->test('pages::company.settings', ['company' => $company])
        ->call('renameCategory', $foreignId, '書換テスト')->assertNotFound();
    expect($other->categories()->findOrFail($foreignId)->name)->toBe('消耗品')
        ->and(AuditLog::query()->where('action', 'category.update')->exists())->toBeFalse();
});

test('T44: 既存データに同名分類があってもAI下書きは最初の勘定科目を勝手に選ばない', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember();
    $original = sendReceipt($company, $user);
    $run = AiRun::query()->where('subject_id', $original->id)->where('task', AiTask::Journalize)->sole();
    $company->categories()->findOrFail(categoryId($company, '燃料'))->forceFill(['name' => '消耗品'])->save();
    Queue::fake();
    $source = sendReceipt($company, $user, 410);
    $before = $source->getAttributes();

    expect(fn () => app(AiDraftBuilder::class)->build($company, $source->id,
        ['total_yen' => 3850, 'date' => now()->toDateString()],
        ['category' => '消耗品', 'bank_account' => '現金'], $run,
    ))->toThrow(AiException::class, '同じ名前の分類');

    expect($source->refresh()->getAttributes())->toBe($before)
        ->and($company->journalEntries()->count())->toBe(1);
});

test('T44: 同名分類ではAI処理を失敗として説明し、名前を解消した後の写真は正しく仕訳できる', function (string $duplicate): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember();
    $originalId = categoryId($company, '消耗品');
    $duplicateId = categoryId($company, $duplicate);
    $company->categories()->findOrFail($duplicateId)->forceFill(['name' => '消耗品'])->save();
    app(FakeClaudeClient::class)->withJournalize(['category' => '消耗品']);

    $failed = sendReceipt($company, $user);
    expect($failed->ai_status)->toBe(AiStatus::Failed)
        ->and($failed->ai_error)->toContain('同じ名前の分類')
        ->and($failed->journal_entry_id)->toBeNull()
        ->and($company->journalEntries()->exists())->toBeFalse();

    app(CompanySettingsService::class)->renameCategory($company, $user, $duplicateId, $duplicate);
    $source = sendReceipt($company, $user, 410);
    expect($source->ai_status)->toBe(AiStatus::Done)
        ->and($source->journalEntry?->category_id)->toBe($originalId);
})->with(['異なる勘定科目' => '燃料', '収入と支出' => '売上']);
