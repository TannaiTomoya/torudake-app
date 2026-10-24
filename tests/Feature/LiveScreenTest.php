<?php

use App\Enums\AiStatus;
use App\Enums\CorrectionReason;
use App\Enums\EntryStatus;
use App\Enums\Role;
use App\Enums\SourceStatus;
use App\Models\AiCorrection;
use App\Models\SourceRecord;
use App\Services\Ai\FakeClaudeClient;
use App\Services\SubmitEvidence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('evidence');
});

test('ライブ画面：あと n 件・最新の1件（店名・日付・合計・分類・仕訳）・流れ・キー5の入口が出る。名前やメールは出ない', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    ['company' => $other, 'user' => $otherUser] = createCompanyWithMember(Role::Owner);
    sendReceipt($other, $otherUser, 333);
    sendReceipt($company, $owner);

    $this->actingAs($owner)->get(route('company.live', $company))
        ->assertOk()
        ->assertSee('あと 1件')
        ->assertSee('〇〇石油 八幡平店')
        ->assertSee('3,850円')
        ->assertSee('消耗品')
        ->assertSee('仕訳　消耗品費 3,850円 ／ 現金 3,850円')
        ->assertSee('S 保存（確定）して次へ')
        ->assertSee('E 直す')
        ->assertSee('5 社長の画面')
        ->assertSee(route('company.home', $company))
        ->assertSee('今日送った枚数')
        ->assertDontSee($owner->email)
        ->assertDontSee($owner->name)
        ->assertDontSee($otherUser->email);

    expect($this->actingAs($owner)->get(route('company.live', $company))->getContent())
        ->toContain('data-test="live-today">1枚');
});

test('ライブ画面：S でそのまま確定、Excel に1行入り、あと0件で「今月の帳簿ができました」', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    $source = sendReceipt($company, $owner);

    Livewire::actingAs($owner)
        ->test('pages::company.live', ['company' => $company])
        ->assertSee('あと 1件')
        ->call('save', $source->id)
        ->assertHasNoErrors()
        ->assertSee('確定しました')
        ->assertSee('あと 0件')
        ->assertSee('今月の帳簿が');

    $entry = $source->refresh()->journalEntry()->firstOrFail();
    expect($entry->status)->toBe(EntryStatus::Confirmed)
        ->and($entry->confirmed_by)->toBe($owner->id)
        ->and($entry->workbook_row)->toBe(2);
});

test('ライブ画面：E で直して理由を選び S で確定。理由がなければ確定しない', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    $source = sendReceipt($company, $owner);
    $fuel = categoryId($company, '燃料');

    $component = Livewire::actingAs($owner)
        ->test('pages::company.live', ['company' => $company])
        ->call('startEdit', $source->id)
        ->assertSet('editingSourceId', $source->id)
        ->assertSet('amountYen', '3850')
        ->set('categoryId', $fuel)
        ->call('save', $source->id)
        ->assertHasErrors('reason');

    expect($source->refresh()->status)->toBe(SourceStatus::Drafted);

    $component->set('reason', CorrectionReason::WrongCategory->value)
        ->call('save', $source->id)
        ->assertHasNoErrors()
        ->assertSet('editingSourceId', '')
        ->assertSeeHtml('data-test="live-flash"')
        ->assertSeeHtml('setTimeout(() => show = false, 3000)');

    $entry = $source->refresh()->journalEntry()->with('category')->firstOrFail();
    expect($entry->status)->toBe(EntryStatus::Confirmed)
        ->and($entry->category?->name)->toBe('燃料');
});

test('台本の場面：支払い元を現金→社長の立替に直すと、同じ店の2枚目は「前回、この店は『社長の立替』に直されました」と出て支払い元が合う。確定は S', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    $first = sendReceipt($company, $owner);
    $advance = bankAccountId($company, '社長の立替');

    $this->actingAs($owner)->get(route('company.live', $company))->assertSee('支払い元')->assertSee('現金');

    Livewire::actingAs($owner)
        ->test('pages::company.live', ['company' => $company])
        ->call('startEdit', $first->id)
        ->assertSee('支払い元違い')
        ->set('bankAccountId', $advance)
        ->set('reason', CorrectionReason::WrongPayment->value)
        ->call('save', $first->id)
        ->assertHasNoErrors();

    $correction = AiCorrection::query()->sole();
    expect($correction->field)->toBe('credit_account')
        ->and($correction->before)->toBe('現金')
        ->and($correction->after)->toBe('社長の立替')
        ->and($correction->reason)->toBe(CorrectionReason::WrongPayment)
        ->and($correction->kernel)->toBe('vendor:〇〇石油 八幡平店 → payment:社長の立替');

    $second = sendReceipt($company, $owner, 410);
    $entry = $second->journalEntry()->with(['bankAccount', 'lines.account'])->firstOrFail();

    expect($entry->status)->toBe(EntryStatus::Draft)
        ->and($entry->bankAccount?->name)->toBe('社長の立替')
        ->and($entry->lines[1]->account?->name)->toBe('役員借入金')
        ->and($entry->ai_check['remembered_payment'] ?? null)->toBe('社長の立替');

    $this->actingAs($owner)->get(route('company.live', $company))
        ->assertSee('前回、この店は『社長の立替』に直されました')
        ->assertDontSee('前回の修正を覚えています')
        ->assertSee('仕訳　消耗品費 3,850円 ／ 役員借入金 3,850円')
        ->assertSee('S 保存（確定）して次へ');

    $this->actingAs($owner)->get(route('company.sources.show', [$company, $second->id]))
        ->assertSee('前回、この店は『社長の立替』に直されました');

    Livewire::actingAs($owner)
        ->test('pages::company.live', ['company' => $company])
        ->call('save', $second->id)
        ->assertHasNoErrors();

    expect($entry->refresh()->status)->toBe(EntryStatus::Confirmed)
        ->and(AiCorrection::query()->count())->toBe(1);

    $sheet = journalSheet($company);
    expect($sheet->getCell([7, 2])->getValue())->toBe('役員借入金')
        ->and($sheet->getCell([13, 2])->getValue())->toBe('直した')
        ->and($sheet->getCell([7, 3])->getValue())->toBe('役員借入金')
        ->and($sheet->getCell([13, 3])->getValue())->toBe('AIのまま');
});

test('支払い元の記憶は直した記録で決める：AI が現金と答えても、表記の違う同じ店なら社長の立替。別の店・他社の記録は使わない', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    ['company' => $other, 'user' => $otherUser] = createCompanyWithMember(Role::Owner);
    $fake = app(FakeClaudeClient::class);

    // 他社で同じ店を「事業用カード」に直した記録
    $foreign = sendReceipt($other, $otherUser, 290);
    Livewire::actingAs($otherUser)->test('pages::company.live', ['company' => $other])
        ->call('startEdit', $foreign->id)
        ->set('bankAccountId', bankAccountId($other, '事業用カード'))
        ->set('reason', CorrectionReason::WrongPayment->value)
        ->call('save', $foreign->id)->assertHasNoErrors();

    $first = sendReceipt($company, $owner, 300);
    expect($first->journalEntry()->firstOrFail()->ai_check['remembered_payment'] ?? null)->toBeNull();

    Livewire::actingAs($owner)->test('pages::company.live', ['company' => $company])
        ->call('startEdit', $first->id)
        ->set('bankAccountId', bankAccountId($company, '社長の立替'))
        ->set('reason', CorrectionReason::WrongPayment->value)
        ->call('save', $first->id)->assertHasNoErrors();

    // 全角・空白の違う同じ店。AI は決めつけず現金と答える
    $fake->withReading(['vendor' => '〇〇石油　八幡平店 ']);
    $fake->withJournalize(['bank_account' => '現金', 'remembered' => false, 'vendor' => '〇〇石油　八幡平店 ']);
    $same = sendReceipt($company, $owner, 310)->journalEntry()->with('bankAccount')->firstOrFail();
    expect($same->bankAccount?->name)->toBe('社長の立替')
        ->and($same->ai_check['remembered_payment'] ?? null)->toBe('社長の立替');

    // 別の店は既定の現金のまま
    $fake->withReading(['vendor' => '△△ホームセンター']);
    $fake->withJournalize(['bank_account' => '現金', 'remembered' => false, 'vendor' => '△△ホームセンター']);
    $otherStore = sendReceipt($company, $owner, 320)->journalEntry()->with('bankAccount')->firstOrFail();
    expect($otherStore->bankAccount?->name)->toBe('現金')
        ->and($otherStore->ai_check['remembered_payment'] ?? null)->toBeNull();
});

test('ライブ画面：金額の記載がないときは S を押せず、E で金額を入れると確定できる', function (): void {
    app(FakeClaudeClient::class)->withReading([
        'vendor' => 'ENEOS 八幡平SS',
        'total_yen' => null,
        'amount_missing' => true,
        'payment_method' => 'invoice_later',
        'tax_breakdown' => [],
    ]);
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    $source = sendReceipt($company, $owner);

    $component = Livewire::actingAs($owner)
        ->test('pages::company.live', ['company' => $company])
        ->assertSee('金額を入れてください')
        ->assertSee('E で金額を入れると保存できます')
        ->assertDontSee('S 保存（確定）して次へ')
        ->call('save', $source->id)
        ->assertHasErrors('amountYen');

    expect($source->refresh()->status)->toBe(SourceStatus::Drafted);

    $component->call('startEdit', $source->id)
        ->set('amountYen', '6820')
        ->call('save', $source->id)
        ->assertHasNoErrors();

    expect($source->refresh()->journalEntry?->status)->toBe(EntryStatus::Confirmed)
        ->and($source->amount_yen)->toBe(6820);
});

test('ライブ画面：まとめて送った回は古い順に出し、その回が済むと残りのまだの1件に移る。読み取り中は秒数と15秒・30秒の表示', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    $earlier = sendReceipt($company, $owner, 300);

    $this->travel(1)->minutes();
    $batchId = Str::uuid7()->toString();
    $service = app(SubmitEvidence::class);
    $first = $service->submit($company, $owner, UploadedFile::fake()->image('a.jpg', 310, 400), null, $batchId)->source;
    $second = $service->submit($company, $owner, UploadedFile::fake()->image('b.jpg', 320, 400), null, $batchId)->source;

    $component = Livewire::actingAs($owner)->test('pages::company.live', ['company' => $company]);
    expect($component->instance()->current()?->id)->toBe($first?->id);

    $component->call('save', (string) $first?->id);
    unset($component->instance()->current);
    expect($component->instance()->current()?->id)->toBe($second?->id);

    $component->call('save', (string) $second?->id);
    expect($component->instance()->current()?->id)->toBe($earlier->id);

    $reading = SourceRecord::query()->findOrFail($earlier->id);
    $reading->forceFill(['ai_status' => AiStatus::Reading, 'ai_stage' => '読み取り中'])->save();

    $component->call('$refresh')
        ->assertSee('AIが読んでいます…（読み取り中）')
        ->assertSee('少し時間がかかっています')
        ->assertSee('時間がかかっています。E で手で入れられます')
        ->assertSee('AIが読み終わると保存できます');
});

test('ライブ画面：開けるのは社長・管理者だけ。他社の原資料は保存できない', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    ['user' => $editor] = createCompanyWithMember(Role::Editor, company: $company);
    ['company' => $other, 'user' => $otherUser] = createCompanyWithMember(Role::Owner);
    $foreign = sendReceipt($other, $otherUser);

    $this->actingAs($editor)->get(route('company.live', $company))->assertForbidden();

    Livewire::actingAs($owner)
        ->test('pages::company.live', ['company' => $company])
        ->call('save', $foreign->id)
        ->assertNotFound();

    Livewire::actingAs($owner)
        ->test('pages::company.live', ['company' => $company])
        ->call('startEdit', $foreign->id)
        ->assertNotFound();

    expect($foreign->refresh()->status)->toBe(SourceStatus::Drafted);
});
