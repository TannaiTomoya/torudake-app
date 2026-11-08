<?php

/**
 * 稼働確認スクリプトのテスト用の偽のサイト（php -S のルーター）。STUB_MODE で擬似障害を選ぶ。
 */
$mode = getenv('STUB_MODE') ?: 'ok';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

$healthy = '{"app":"torudake","ok":true}';

if ($path === '/login') {
    switch ($mode) {
        case 'login_500':
            http_response_code(500);
            echo 'error';

            return;
        case 'login_redirect_other':
            header('Location: https://example.org/login', true, 302);

            return;
        case 'login_redirect_same':
            header('Location: /elsewhere', true, 302);

            return;
        case 'login_wrong_page':
            echo '<html><body>Welcome to nginx</body></html>';

            return;
        case 'login_slow':
            sleep(3);
    }

    echo '<form><input type="email" name="email"><input type="password" name="password"></form>';

    return;
}

if ($path === '/health') {
    header('Content-Type: application/json');

    switch ($mode) {
        case 'health_503':
            http_response_code(503);
            echo '{"app":"torudake","ok":false}';

            return;
        case 'health_500':
            http_response_code(500);
            echo 'error';

            return;
        case 'health_foreign':
            echo '{"status":"up"}';

            return;
        case 'health_404':
            http_response_code(404);
            echo 'not found';

            return;
    }

    echo $healthy;

    return;
}

http_response_code(404);
