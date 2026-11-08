<?php

use Symfony\Component\Process\Process;

/**
 * docs/server/check-public-site.sh（外形確認）が、擬似障害を区別して検出するか。偽のサイトを手元で立てて確かめる。
 */
function freePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);

    return $port;
}

/**
 * @return array{0: int, 1: string} 終了コードと出力
 */
function runSiteCheck(?string $mode, int $timeout = 5): array
{
    $port = freePort();
    $server = null;

    if ($mode !== null) {
        $server = new Process(['php', '-S', "127.0.0.1:{$port}", base_path('tests/stubs/site-router.php')], env: ['STUB_MODE' => $mode]);
        $server->start();

        for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
            usleep(100_000);
        }
    }

    try {
        $check = new Process(['bash', base_path('docs/server/check-public-site.sh'), "http://127.0.0.1:{$port}"], env: ['RETRIES' => '1', 'TIMEOUT' => (string) $timeout]);
        $check->run();

        return [(int) $check->getExitCode(), $check->getOutput()];
    } finally {
        $server?->stop(0);
    }
}

test('外形確認：ログイン画面と /health が正常なら終了コード 0', function (): void {
    [$exit, $output] = runSiteCheck('ok');

    expect($exit)->toBe(0)->and($output)->toContain('OK /login')->toContain('OK /health')->toContain('すべて正常です');
});

test('外形確認：擬似障害を区別して検出する（終了コード 1 と理由）', function (string $mode, string $expected): void {
    [$exit, $output] = runSiteCheck($mode, timeout: $mode === 'login_slow' ? 1 : 5);

    expect($exit)->toBe(1)->and($output)->toContain($expected);
})->with([
    '500' => ['login_500', 'NG /login：サーバーエラーです（HTTP 500）'],
    '別のサイトへのリダイレクト' => ['login_redirect_other', '別のサイトへ移動しています'],
    '同じサイト内の想定外のリダイレクト' => ['login_redirect_same', '想定外のリダイレクトです（HTTP 302）'],
    '別のページの 200' => ['login_wrong_page', 'ログイン画面ではありません'],
    '時間切れ' => ['login_slow', '1秒以内に応答がありません'],
    '止まっている印が古い（503）' => ['health_503', 'DB・定期処理・キューのどれかが止まっています'],
    '/health が 500' => ['health_500', 'NG /health：サーバーエラーです（HTTP 500）'],
    '別のサイトの 200（アプリの印がない）' => ['health_foreign', 'このアプリの印がありません'],
    '/health がない' => ['health_404', '古い版のままの可能性'],
]);

test('外形確認：接続できないときは、成功にせず「接続できません」と報告する', function (): void {
    [$exit, $output] = runSiteCheck(null);

    expect($exit)->toBe(1)->and($output)->toContain('接続できません');
});
