<?php

use App\Enums\EntryStatus;
use App\Enums\Role;
use App\Jobs\ProcessEvidenceWithAi;
use App\Models\Company;
use App\Models\SourceRecord;
use App\Models\User;
use App\Services\SubmitEvidence;
use App\Support\RemainingCount;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('evidence');
});

function sendForCount(Company $company, User $user, int $width): SourceRecord
{
    return app(SubmitEvidence::class)
        ->submit($company, $user, UploadedFile::fake()->image('r.jpg', $width, 600))
        ->source ?? throw new RuntimeException('原資料ができていません');
}

test('T15: 「あと n 件」＝未処理＋下書き＋社長から戻し。台本どおり 1 → 3 → 0', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);

    // AI がまだ動いていない（未処理）1枚
    Queue::fake();
    sendForCount($company, $editor, 400);
    expect(RemainingCount::for($company))->toBe(1);

    // AI が下書きを作った2枚。未処理の1枚と合わせて3件
    $drafted = [sendForCount($company, $editor, 410), sendForCount($company, $editor, 420)];
    foreach ($drafted as $source) {
        app()->call([new ProcessEvidenceWithAi($source->id), 'handle']);
    }
    $drafted = array_map(fn (SourceRecord $source): SourceRecord => $source->refresh(), $drafted);
    $drafted[0]->journalEntry()->firstOrFail()->forceFill(['entry_date' => now()->subMonth()->startOfMonth()])->save();
    expect(RemainingCount::for($company))->toBe(3);
    expect(RemainingCount::fromPreviousMonths($company))->toBe(1);

    $this->actingAs($editor)->get(route('company.home', $company))->assertSee('3');

    // 3件とも確定（未処理の1枚は手で入れる）
    $pending = $company->sourceRecords()->whereNull('journal_entry_id')->firstOrFail();
    foreach ([$pending, ...$drafted] as $source) {
        Livewire::actingAs($editor)
            ->test('pages::company.sources.show', ['company' => $company, 'source' => $source->id])
            ->set('categoryId', (string) $company->categories()->where('name', '消耗品')->value('id'))
            ->set('amountYen', '1000')
            ->set('reason', 'misread')
            ->call('confirm')
            ->assertHasNoErrors();
    }

    expect(RemainingCount::for($company))->toBe(0);
    $this->actingAs($editor)->get(route('company.home', $company))->assertSee('未処理の帳簿ができました');

    // 社長から戻された仕訳は、また数える
    $entry = $drafted[0]->refresh()->journalEntry()->firstOrFail();
    $entry->forceFill(['status' => EntryStatus::Returned, 'returned_at' => now(), 'returned_reason' => '金額を確かめて'])->save();
    expect(RemainingCount::for($company))->toBe(1);
});

test('月をまたいだ未処理も全件に含め、指定月の件数は分けて数える。他社の分は含めない', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    ['company' => $other, 'user' => $otherEditor] = createCompanyWithMember(Role::Editor);

    Queue::fake();
    $this->travel(-40)->days();
    sendForCount($company, $editor, 400);
    $this->travelBack();

    sendForCount($other, $otherEditor, 410);

    expect(RemainingCount::for($company))->toBe(1)
        ->and(RemainingCount::for($company, now()))->toBe(0)
        ->and(RemainingCount::fromPreviousMonths($company))->toBe(1)
        ->and(RemainingCount::for($company, now()->subDays(40)))->toBe(1);
});
