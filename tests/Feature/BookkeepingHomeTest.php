<?php

use App\Enums\EntryStatus;
use App\Enums\Role;
use App\Models\Membership;

beforeEach(fn () => $this->travelTo(now()->setDate(2026, 10, 24)->setTime(9, 0)));

test('T38: adminの経理ホームは未処理と作業メニューを優先し、社長向け機能も開ける', function (int $pending): void {
    ['company' => $company, 'user' => $admin, 'membership' => $membership] = createCompanyWithMember(Role::Admin);
    $admin->forceFill(['name' => '経理担当'])->save();
    ['company' => $other] = createCompanyWithMember(Role::Owner);

    for ($day = 1; $day <= $pending; $day++) {
        putEntry($company, "2026-10-0{$day}", '燃料', 5_000, EntryStatus::Draft);
    }
    putEntry($other, '2026-10-01', '燃料', 9_000, EntryStatus::Draft);

    $response = $this->actingAs($admin)->get(route('company.home', $company))
        ->assertOk()
        ->assertSeeHtml('data-test="remaining"')
        ->assertSeeHtml('data-test="owner-shortcut"')
        ->assertDontSeeHtml('data-test="owner-link"')
        ->assertSeeInOrder(['data-test="sidebar-sources"', 'data-test="sidebar-submit"', 'data-test="sidebar-owner"'], false)
        ->assertSeeHtml('data-test="sidebar-entries"')
        ->assertSeeHtml('data-test="sidebar-workbook"')
        ->assertSeeHtml('data-test="sidebar-live"')
        ->assertSee(route('company.ai', $company))
        ->assertSee(route('company.settings', $company));

    if ($pending === 0) {
        $response->assertSee('未処理の帳簿ができました')
            ->assertDontSeeHtml('data-test="sidebar-remaining"');
    } else {
        $response->assertSeeHtml('data-test="sidebar-remaining">あと2件</span>')
            ->assertSeeHtml('2<span class="text-3xl">件</span>');
    }

    $this->get(route('company.sources.index', $company))->assertOk();
    $this->get(route('company.owner', $company))->assertOk();
    $this->get(route('company.live', $company))->assertOk();

    expect($membership->refresh()->role)->toBe(Role::Admin);
})->with(['未処理なし' => [0], '未処理あり' => [2]]);

test('T38: ホームの主な作業は開いている会社のロールで決まり、別会社の社長ロールを使わない', function (): void {
    ['company' => $owned, 'user' => $user] = createCompanyWithMember(Role::Owner);
    ['company' => $managed] = createCompanyWithMember(Role::Owner);
    $membership = new Membership;
    $membership->forceFill([
        'company_id' => $managed->id,
        'user_id' => $user->id,
        'role' => Role::Admin,
        'submit_only' => false,
        'disabled_at' => null,
    ])->save();
    putEntry($managed, '2026-10-01', '燃料', 5_000, EntryStatus::Draft);

    $this->actingAs($user)->get(route('company.home', $managed))
        ->assertOk()
        ->assertSeeHtml('data-test="remaining"')
        ->assertSeeHtml('data-test="sidebar-remaining">あと1件</span>')
        ->assertDontSeeHtml('data-test="owner-link"');

    $this->get(route('company.home', $owned))
        ->assertOk()
        ->assertSeeHtml('data-test="owner-link"')
        ->assertDontSeeHtml('data-test="remaining"')
        ->assertDontSeeHtml('data-test="sidebar-sources"');
});

test('T38: 表示名が社長でもeditorには社長画面・ライブの入口や権限を与えない', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $editor->forceFill(['name' => '社長'])->save();

    $this->actingAs($editor)->get(route('company.owner', $company))->assertForbidden();
    $this->get(route('company.live', $company))->assertForbidden();

    $this->get(route('company.home', $company))
        ->assertOk()
        ->assertSeeHtml('data-test="remaining"')
        ->assertDontSeeHtml('data-test="owner-link"')
        ->assertDontSeeHtml('data-test="owner-shortcut"')
        ->assertDontSeeHtml('data-test="sidebar-owner"')
        ->assertDontSeeHtml('data-test="sidebar-live"');

});

test('T38: 表示名が経理担当でもownerのホームは社長向けを優先する', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    $owner->forceFill(['name' => '経理担当'])->save();

    $this->actingAs($owner)->get(route('company.home', $company))
        ->assertOk()
        ->assertSeeHtml('data-test="owner-link"')
        ->assertSeeHtml('data-test="sidebar-owner"')
        ->assertDontSeeHtml('data-test="remaining"')
        ->assertDontSeeHtml('data-test="sidebar-sources"');
});
