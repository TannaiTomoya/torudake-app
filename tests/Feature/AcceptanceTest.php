<?php

use App\Enums\EntryStatus;
use App\Enums\Role;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * 公開前の受け入れ確認（隔離環境・架空データ・AI は偽物）。
 * 本番でやることは docs/runbook.md の「本番の最小確認」に分けてある。
 */
beforeEach(function (): void {
    Storage::fake('evidence');
});

test('受け入れ：ログイン画面が出て、ログインしていなければ会社の画面へ入れない', function (): void {
    ['company' => $company] = createCompanyWithMember();

    $this->get('/login')->assertOk()->assertSee('type="password"', false);
    $this->get(route('company.home', $company))->assertRedirect('/login');
    $this->get(route('company.workbook', $company))->assertRedirect('/login');
});

test('受け入れ：経理が保存すると確定になり、Excel の仕訳帳にその1件が入る（下書きは入らない）', function (): void {
    ['company' => $company, 'user' => $editor] = createCompanyWithMember(Role::Editor);
    $source = sendReceipt($company, $editor);
    sendReceipt($company, $editor, 410);

    Livewire::actingAs($editor)
        ->test('pages::company.sources.show', ['company' => $company, 'source' => $source->id])
        ->call('confirm')
        ->assertHasNoErrors();

    $response = $this->actingAs($editor)->get(route('company.workbook', $company))->assertOk();

    $path = tempnam(sys_get_temp_dir(), 'acc');
    file_put_contents($path, (string) $response->getContent());
    $sheet = IOFactory::createReader('Xlsx')->load($path)->getActiveSheet();
    unlink($path);

    $draftCount = $company->journalEntries()->where('status', EntryStatus::Draft)->count();
    $confirmed = $company->journalEntries()->where('status', EntryStatus::Confirmed)->sole();

    expect($draftCount)->toBe(1)
        ->and($sheet->getCell([1, 1])->getValue())->toBe('管理番号')
        ->and($sheet->getHighestDataRow())->toBeGreaterThanOrEqual(2)
        ->and(collect($sheet->toArray())->flatten()->contains(fn ($cell) => str_contains((string) $cell, (string) $confirmed->vendor)))->toBeTrue();
});

test('受け入れ：送るだけの人は送る画面に入り、経理・社長の画面は開けない', function (): void {
    ['company' => $company] = createCompanyWithMember(Role::Owner);
    ['user' => $worker] = createCompanyWithMember(Role::Editor, submitOnly: true, company: $company);

    $this->actingAs($worker)->get(route('dashboard'))->assertRedirect(route('company.submit', $company));
    $this->actingAs($worker)->get(route('company.submit', $company))->assertOk();

    foreach (['company.owner', 'company.sources.index', 'company.entries.index', 'company.reports', 'company.settings', 'company.workbook'] as $name) {
        $this->actingAs($worker)->get(route($name, $company))->assertForbidden();
    }
});

test('受け入れ：社長は自分の画面に入り、経理の作業画面・取り込みは社長の権限の範囲で開く', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);
    ['user' => $editor] = createCompanyWithMember(Role::Editor, company: $company);

    $this->actingAs($owner)->get(route('company.owner', $company))->assertOk();
    $this->actingAs($editor)->get(route('company.owner', $company))->assertForbidden();
    $this->actingAs($editor)->get(route('company.reports', $company))->assertForbidden();
    $this->actingAs($editor)->get(route('company.sources.index', $company))->assertOk();
});

test('受け入れ：他社の画面・Excel は404', function (): void {
    ['user' => $editor] = createCompanyWithMember(Role::Editor);
    ['company' => $other] = createCompanyWithMember(Role::Owner);

    $this->actingAs($editor)->get(route('company.home', $other))->assertNotFound();
    $this->actingAs($editor)->get(route('company.workbook', $other))->assertNotFound();
});
