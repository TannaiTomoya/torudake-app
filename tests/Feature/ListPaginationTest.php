<?php

use App\Enums\AiStatus;
use App\Enums\EntryKind;
use App\Enums\EntryStatus;
use App\Enums\Role;
use App\Enums\SourceKind;
use App\Enums\SourceStatus;
use App\Models\Evidence;
use App\Models\JournalEntry;
use App\Models\SourceRecord;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('evidence');
});

test('T37: 101件目以降の原資料・仕訳をページ送りで開け、状態変更は1ページ目に戻る', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor);

    for ($index = 1; $index <= 101; $index++) {
        $createdAt = now()->subDays(101 - $index);
        $evidence = new Evidence;
        $evidence->forceFill([
            'company_id' => $company->id,
            'storage_key' => null,
            'sha256' => hash('sha256', 'pagination-evidence-'.$index),
            'mime' => 'image/jpeg',
            'size_bytes' => 1,
            'uploaded_by' => $user->id,
            'purge_after' => now()->addDays(30),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        $source = new SourceRecord;
        $source->forceFill([
            'company_id' => $company->id,
            'kind' => SourceKind::Receipt,
            'status' => SourceStatus::New,
            'ai_status' => AiStatus::Failed,
            'evidence_id' => $evidence->id,
            'vendor' => sprintf('原資料 %03d', $index),
            'submitted_by' => $user->id,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        $entry = new JournalEntry;
        $entry->forceFill([
            'company_id' => $company->id,
            'entry_date' => $createdAt->toDateString(),
            'kind' => EntryKind::Normal,
            'status' => EntryStatus::Draft,
            'vendor' => sprintf('仕訳 %03d', $index),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();
    }

    $sources = Livewire::actingAs($user)
        ->test('pages::company.sources.index', ['company' => $company])
        ->assertSee('全101件')
        ->assertDontSee('原資料 001');

    for ($page = 1; $page < 5; $page++) {
        $sources->call('nextPage');
    }

    $sources->assertSee('原資料 001')->call('selectStatus', 'new')->assertSee('原資料 101');

    $entries = Livewire::actingAs($user)
        ->test('pages::company.entries.index', ['company' => $company])
        ->assertSee('全101件')
        ->assertDontSee('仕訳 001');

    for ($page = 1; $page < 5; $page++) {
        $entries->call('nextPage');
    }

    $entries->assertSee('仕訳 001')->call('selectStatus', EntryStatus::Draft->value)->assertSee('仕訳 101');
});
