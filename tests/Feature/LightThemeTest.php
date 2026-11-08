<?php

use App\Enums\Role;
use App\Models\User;

// #10：ダークモードの端末でも、いつもライト表示。画面の文字（ink）が暗い背景に溶けない

/** `<html …>` の開始タグ */
function htmlTag(string $html): string
{
    preg_match('/<html\b[^>]*>/i', $html, $m);

    return $m[0] ?? '';
}

test('ログイン画面は html に dark を付けず、端末のダーク設定に従う処理も入れない', function (): void {
    $html = $this->get(route('login'))->assertOk()->getContent();

    expect(htmlTag($html))->not->toContain('dark')
        ->and($html)->toContain('<meta name="color-scheme" content="light">')
        ->and($html)->not->toContain('applyAppearance')
        ->and($html)->not->toContain('prefers-color-scheme');
});

test('会社のホームも、サイドバー付きの画面で dark を付けない', function (): void {
    ['company' => $company, 'user' => $owner] = createCompanyWithMember(Role::Owner);

    $html = $this->actingAs($owner)->get(route('company.home', $company))->assertOk()->getContent();

    expect(htmlTag($html))->not->toContain('dark')
        ->and($html)->not->toContain('applyAppearance');
});

test('送るだけの人の画面も dark を付けない', function (): void {
    ['company' => $company, 'user' => $user] = createCompanyWithMember(Role::Editor, submitOnly: true);

    $html = $this->actingAs($user)->get(route('company.submit', $company))->assertOk()->getContent();

    expect(htmlTag($html))->not->toContain('dark');
});

test('設定のテーマ画面は切替を出さず、ライト固定の案内だけを出す', function (): void {
    $this->actingAs(User::factory()->withTwoFactor()->create());

    $this->get(route('appearance.edit'))
        ->assertOk()
        ->assertSee('data-test="appearance-fixed"', false)
        ->assertSee('いつも明るい配色（ライト）で表示します')
        ->assertDontSee('x-model="$flux.appearance"', false)
        ->assertDontSee('data-flux-radio', false);
});

test('レイアウトの html に dark のクラスを直書きしない（外れていることの見張り）', function (): void {
    $files = glob(resource_path('views/layouts/{,*/}*.blade.php'), GLOB_BRACE) ?: [];

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        expect(htmlTag((string) file_get_contents($file)))->not->toContain('dark', basename($file));
    }
});
