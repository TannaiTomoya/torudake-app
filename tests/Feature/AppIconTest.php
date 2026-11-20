<?php

// T54：アプリの絵（タブ・スマホのホーム画面・PC のショートカット）。
// 画面の head が絵と manifest を指し、指した先のファイルが実際にあって、書いた大きさどおりであること。

test('ログイン画面の head がアプリの絵と manifest を指す', function (): void {
    $this->get('/login')
        ->assertOk()
        ->assertSee('<link rel="icon" href="/favicon.ico?v=2" sizes="48x48">', false)
        ->assertSee('<link rel="icon" type="image/png" sizes="192x192" href="/icon-192.png?v=2">', false)
        ->assertSee('<link rel="apple-touch-icon" href="/apple-touch-icon.png?v=2">', false)
        ->assertSee('<link rel="manifest" href="/app-manifest.json?v=2">', false)
        ->assertDontSee('favicon.svg', false);
});

test('head が指す絵のファイルがあり、大きさが合っている', function (string $file, int $size): void {
    $path = public_path($file);

    expect(is_file($path))->toBeTrue();

    $info = getimagesize($path);

    expect($info)->not->toBeFalse()
        ->and($info[0])->toBe($size)
        ->and($info[1])->toBe($size);
})->with([
    ['apple-touch-icon.png', 180],
    ['icon-192.png', 192],
    ['icon-512.png', 512],
    ['icon-maskable-512.png', 512],
]);

test('タブ用の絵は ico で、前の絵（Laravel の標準）の svg は残っていない', function (): void {
    $ico = file_get_contents(public_path('favicon.ico'));

    // ico の先頭：予約 0・種類 1（アイコン）
    expect(substr((string) $ico, 0, 4))->toBe("\x00\x00\x01\x00")
        ->and(is_file(public_path('favicon.svg')))->toBeFalse();
});

test('manifest が読めて、書いてある絵がすべてあり、大きさが合っている', function (): void {
    $manifest = json_decode((string) file_get_contents(public_path('app-manifest.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['name'])->toBe('撮って送るだけ帳簿')
        ->and($manifest['start_url'])->toBe('/')
        // アプリのように全画面にはしない（写真の撮影や Excel のダウンロードの動きを変えないため）
        ->and($manifest['display'])->toBe('browser')
        ->and($manifest['icons'])->toHaveCount(3);

    foreach ($manifest['icons'] as $icon) {
        $info = getimagesize(public_path(ltrim($icon['src'], '/')));

        expect($info)->not->toBeFalse()
            ->and("{$info[0]}x{$info[1]}")->toBe($icon['sizes'])
            ->and($info['mime'])->toBe($icon['type']);
    }

    expect(array_column($manifest['icons'], 'purpose'))->toContain('maskable');
});
