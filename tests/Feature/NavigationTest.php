<?php

use App\Enums\EntryStatus;
use App\Enums\Role;

beforeEach(fn () => $this->travelTo(now()->setDate(2026, 10, 24)->setTime(9, 0)));

test('社長のサイドバーは社長の画面（気になる仕訳の数）・仕訳の一覧・Excel・ライブ画面・設定。ホームは社長の画面だけを大きく出す', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    putEntry($company, '2026-10-01', '部品・修理', 120_000, flags: ['amount']);
    putEntry($company, '2026-10-02', '燃料', 5_000, EntryStatus::Draft);

    $this->actingAs($owner)->get(route('company.home', $company))
        ->assertOk()
        ->assertSee('data-test="sidebar-owner"', false)
        ->assertSee('data-test="sidebar-concern-count">1</span>', false)
        ->assertSee('data-test="sidebar-entries"', false)
        ->assertSee('data-test="sidebar-workbook"', false)
        ->assertSee('data-test="sidebar-live"', false)
        ->assertSee(route('company.settings', $company))
        ->assertDontSee('data-test="sidebar-sources"', false)
        ->assertDontSee('data-test="sidebar-submit"', false)
        ->assertDontSee('メニュー')
        ->assertSee('collapsible="true"', false)
        ->assertSee('次にやること')
        ->assertSee('data-test="owner-link"', false)
        ->assertSee('気になる仕訳が')
        ->assertDontSee('data-test="remaining"', false)
        ->assertDontSee('原資料の一覧');
});

test('経理のサイドバーは直して保存（あと n 件）・レシートを送る・仕訳の一覧・Excel・設定。社長の画面とライブ画面は出ない', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    putEntry($company, '2026-10-01', '燃料', 5_000, EntryStatus::Draft);
    putEntry($company, '2026-10-02', '燃料', 6_000, EntryStatus::Draft);
    putEntry($company, '2026-10-03', '部品・修理', 120_000, flags: ['amount']);

    $this->actingAs($editor)->get(route('company.home', $company))
        ->assertOk()
        ->assertSee('data-test="sidebar-sources"', false)
        ->assertSee('data-test="sidebar-remaining">あと2件</span>', false)
        ->assertSee('data-test="sidebar-submit"', false)
        ->assertSee('data-test="sidebar-entries"', false)
        ->assertSee('data-test="sidebar-workbook"', false)
        ->assertSee(route('profile.edit'))
        ->assertDontSee(route('company.settings', $company))
        ->assertDontSee('data-test="sidebar-owner"', false)
        ->assertDontSee('data-test="sidebar-live"', false)
        ->assertSee('data-test="remaining"', false)
        ->assertSee('直して保存する')
        ->assertDontSee('data-test="owner-link"', false);
});

test('閲覧のみは仕訳の一覧・Excel だけ。ホームは仕訳の一覧を出す', function (): void {
    ['company' => $company, 'user' => $viewer] = createCompanyWithMember(Role::Viewer);

    $this->actingAs($viewer)->get(route('company.home', $company))
        ->assertOk()
        ->assertSee('data-test="sidebar-entries"', false)
        ->assertSee('data-test="sidebar-workbook"', false)
        ->assertDontSee('data-test="sidebar-sources"', false)
        ->assertDontSee('data-test="sidebar-submit"', false)
        ->assertDontSee('data-test="sidebar-owner"', false)
        ->assertSee('data-test="entries-link"', false);
});

test('送るだけの人にはサイドバーを出さない', function (): void {
    ['company' => $company, 'user' => $submitter] = createCompanyWithMember(Role::Editor, submitOnly: true);

    $this->actingAs($submitter)->get(route('company.submit', $company))
        ->assertOk()
        ->assertDontSee('data-test="sidebar"', false)
        ->assertDontSee('data-test="sidebar-entries"', false);
});

test('サイドバーとホームの数に他社のものが混ざらない', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    ['user' => $editor] = createCompanyWithMember(Role::Editor, company: $company);
    ['company' => $other] = createCompanyWithMember(Role::Owner);
    putEntry($company, '2026-10-01', '部品・修理', 120_000, flags: ['amount']);
    putEntry($company, '2026-10-02', '燃料', 5_000, EntryStatus::Draft);
    foreach (range(1, 3) as $day) {
        putEntry($other, "2026-10-0{$day}", '部品・修理', 90_000, flags: ['amount']);
        putEntry($other, "2026-10-1{$day}", '燃料', 4_000, EntryStatus::Draft);
    }

    $this->actingAs($owner)->get(route('company.home', $company))
        ->assertSee('data-test="sidebar-concern-count">1</span>', false)
        ->assertSee('<span class="text-5xl">1</span>', false);

    $this->actingAs($editor)->get(route('company.home', $company))
        ->assertSee('data-test="sidebar-remaining">あと1件</span>', false)
        ->assertSee('1<span class="text-3xl">件</span>', false);
});

test('メニューの「設定」「ログアウト」は日本語', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);

    $this->actingAs($owner)->get(route('company.home', $company))
        ->assertSee('ログアウト')
        ->assertSee('設定')
        ->assertDontSee('Log out')
        ->assertDontSee('>Settings<', false);
});
