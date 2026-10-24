<?php

use App\Enums\BankAccountKind;
use App\Enums\EntryStatus;
use App\Enums\Role;
use App\Enums\SourceStatus;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\SourceRecord;
use App\Services\SubmitEvidence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

test('T24: 移行で、承認済みの仕訳は確定に、仕訳済みの原資料は確定になり、各社に「後日の請求」の口座ができる', function (): void {
    Storage::fake('evidence');
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Owner);
    $source = app(SubmitEvidence::class)
        ->submit($company, $user, UploadedFile::fake()->image('r.jpg', 400, 600))
        ->source?->refresh() ?? throw new RuntimeException('原資料ができていません');

    // 版2.0 の値に戻す（Model を通さず、移行前の行を作る）
    DB::table('journal_entries')->where('id', $source->journal_entry_id)->update([
        'status' => 'approved',
        'approved_by' => $user->id,
        'approved_at' => '2026-10-20 10:00:00',
    ]);
    DB::table('source_records')->where('id', $source->id)->update(['status' => 'journaled']);
    $company->bankAccounts()->where('kind', BankAccountKind::Invoice)->get()->each(fn (BankAccount $b) => $b->delete());

    $migration = require database_path('migrations/2026_10_24_140000_confirm_by_bookkeeper.php');
    $migration->convertLegacyData();
    $migration->convertLegacyData();

    $entry = JournalEntry::findOrFail($source->journal_entry_id);
    expect($entry->status)->toBe(EntryStatus::Confirmed)
        ->and($entry->confirmed_by)->toBe($user->id)
        ->and($entry->confirmed_at?->toDateTimeString())->toBe('2026-10-20 10:00:00')
        ->and(SourceRecord::findOrFail($source->id)->status)->toBe(SourceStatus::Confirmed);

    expect(Company::findOrFail($company->id)->bankAccounts()->where('kind', BankAccountKind::Invoice)->count())->toBe(1);
});
