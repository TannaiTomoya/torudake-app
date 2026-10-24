<?php

use App\Enums\AiTask;
use App\Enums\EntryStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Services\Ai\AiRequest;
use App\Services\Ai\FakeClaudeClient;
use App\Support\RemainingCount;
use Livewire\Livewire;

beforeEach(fn () => $this->travelTo(now()->setDate(2026, 10, 24)->setTime(9, 0)));

function briefRequestCount(): int
{
    return count(array_filter(app(FakeClaudeClient::class)->requests, fn (AiRequest $r): bool => $r->task === AiTask::OwnerBrief));
}

test('T17 社長は理由を付けて経理に戻す。「社長から戻し」になり、あと n 件に入り、気になる仕訳から消える', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    $entry = putEntry($company, '2026-10-01', '部品・修理', 120_000, flags: ['amount'], vendor: '△△機械');
    $remaining = RemainingCount::for($company);

    Livewire::actingAs($owner)->test('pages::company.owner', ['company' => $company])
        ->assertSee('△△機械')
        ->call('startReturn', $entry->id)
        ->set('returnReason', '   ')
        ->call('returnToBookkeeper')
        ->assertHasErrors('returnReason')
        ->set('returnReason', '領収書の金額と合っているか確かめて')
        ->call('returnToBookkeeper')
        ->assertHasNoErrors()
        ->assertSee('経理に戻しました。')
        ->assertDispatched('concerns-changed', count: 0)
        ->assertDontSee('△△機械');

    $entry->refresh();
    expect($entry->status)->toBe(EntryStatus::Returned)
        ->and($entry->returned_by)->toBe($owner->id)
        ->and($entry->returned_at)->not->toBeNull()
        ->and($entry->returned_reason)->toBe('領収書の金額と合っているか確かめて')
        ->and(RemainingCount::for($company))->toBe($remaining + 1)
        ->and(AuditLog::query()->where('company_id', $company->id)->where('action', 'entry.return')->count())->toBe(1);
});

test('T17 戻せるのは社長・管理者だけ（経理・承認者・閲覧は403）。他社の仕訳は404、確定していない仕訳は戻せない', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    ['user' => $admin] = createCompanyWithMember(Role::Admin, company: $company);
    ['company' => $other] = createCompanyWithMember(Role::Owner);
    $entry = putEntry($company, '2026-10-01', '燃料', 5_000);
    $draft = putEntry($company, '2026-10-02', '燃料', 6_000, EntryStatus::Draft);
    $others = putEntry($other, '2026-10-03', '燃料', 7_000, flags: ['amount']);

    foreach ([Role::Editor, Role::Approver, Role::Viewer] as $role) {
        ['user' => $user] = createCompanyWithMember($role, company: $company);
        Livewire::actingAs($user)->test('pages::company.entries.show', ['company' => $company, 'entry' => $entry->id])
            ->assertDontSeeHtml('data-test="entry-return"')
            ->set('returnReason', '確かめて')
            ->call('returnToBookkeeper')
            ->assertForbidden();
    }
    expect($entry->refresh()->status)->toBe(EntryStatus::Confirmed);

    Livewire::actingAs($admin)->test('pages::company.entries.show', ['company' => $company, 'entry' => $entry->id])
        ->assertSeeHtml('data-test="entry-return"')
        ->set('showReturn', true)
        ->set('returnReason', '日付を確かめて')
        ->call('returnToBookkeeper')
        ->assertHasNoErrors()
        ->assertSee('社長から戻されました：日付を確かめて')
        ->assertDontSeeHtml('data-test="entry-return"');
    expect($entry->refresh()->status)->toBe(EntryStatus::Returned)
        ->and($entry->returned_by)->toBe($admin->id);

    Livewire::actingAs($owner)->test('pages::company.owner', ['company' => $company])
        ->call('startReturn', $draft->id)
        ->set('returnReason', '確かめて')
        ->call('returnToBookkeeper')
        ->assertHasErrors('returnReason');
    expect($draft->refresh()->status)->toBe(EntryStatus::Draft);

    Livewire::actingAs($owner)->test('pages::company.owner', ['company' => $company])
        ->call('startReturn', $others->id)
        ->set('returnReason', '確かめて')
        ->call('returnToBookkeeper')
        ->assertNotFound();
    Livewire::actingAs($owner)->test('pages::company.owner', ['company' => $company])
        ->call('markSeen', $others->id)
        ->assertNotFound();
    $others->refresh();
    expect($others->status)->toBe(EntryStatus::Confirmed)
        ->and($others->owner_seen_at)->toBeNull();
});

test('「見た」を押すと気になる仕訳から消え、数がサイドバーと欄で減る。AIは呼び直さない', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    putSales($company, '2026-08', 3_600_000);
    putSales($company, '2025-08', 3_000_000);
    $first = putEntry($company, '2026-10-01', '部品・修理', 120_000, flags: ['amount'], vendor: '△△機械');
    putEntry($company, '2026-10-02', '交際', 50_000, flags: ['ai_private'], vendor: '□□食堂');

    $this->actingAs($owner)->get(route('company.owner', $company))
        ->assertSee('気になる仕訳（<span data-test="owner-concern-count">2</span>件）', false)
        ->assertSeeInOrder(['data-test="sidebar-owner"', 'data-test="sidebar-concern-count">2</span>'], false);
    expect(briefRequestCount())->toBe(1);

    Livewire::actingAs($owner)->test('pages::company.owner', ['company' => $company])
        ->call('markSeen', $first->id)
        ->assertSee('見た印を付けました。')
        ->assertDispatched('concerns-changed', count: 1)
        ->assertSeeHtml('<span data-test="owner-concern-count">1</span>')
        ->assertDontSee('△△機械')
        ->assertSee('□□食堂');

    expect($first->refresh()->owner_seen_at)->not->toBeNull()
        ->and($first->status)->toBe(EntryStatus::Confirmed);

    $this->actingAs($owner)->get(route('company.owner', $company))
        ->assertSee('data-test="sidebar-concern-count">1</span>', false);
    expect(briefRequestCount())->toBe(1);
});

test('サイドバーの「社長の画面」は社長・管理者だけに出る', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    ['user' => $editor] = createCompanyWithMember(Role::Editor, company: $company);

    $this->actingAs($owner)->get(route('company.home', $company))->assertSee('data-test="sidebar-owner"', false);
    $this->actingAs($editor)->get(route('company.home', $company))->assertDontSee('data-test="sidebar-owner"', false);
});
