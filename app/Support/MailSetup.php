<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * メールが本当に送られる設定かどうか（#39）。
 * 「設定がそれらしい」ことしか分からない。届くかどうかは、試しに送って受信箱で確かめる（torudake:mail-check --to）。
 * 秘密の値（パスワード・MAIL_URL）は返さない。
 */
final class MailSetup
{
    /** 送らずに書き出すだけの送り方 */
    private const NOT_DELIVERING = ['log', 'array'];

    /** 開発用の受け皿（Mailpit など）がいる場所 */
    private const DEVELOPMENT_HOSTS = ['', '127.0.0.1', '0.0.0.0', '::1', 'localhost', 'mailpit', 'mailhog'];

    private const PLACEHOLDER_DOMAINS = ['example.com', 'example.org', 'example.net', 'localhost'];

    public static function mailer(): string
    {
        return (string) config('mail.default');
    }

    public static function transport(): string
    {
        $mailer = self::mailer();

        return (string) config("mail.mailers.{$mailer}.transport", $mailer);
    }

    /**
     * 送られる送り方か。log・array は、書き出すだけで誰にも届かない。
     */
    public static function delivers(): bool
    {
        return ! in_array(self::transport(), self::NOT_DELIVERING, true);
    }

    /**
     * 画面に出してよい、今の設定（秘密の値は「あり・なし」だけ）。
     *
     * @return list<string>
     */
    public static function summary(): array
    {
        $mailer = self::mailer();
        $lines = ["送り方：{$mailer}"];

        if (self::transport() === 'smtp') {
            $lines[0] .= sprintf('（%s:%s）', self::setting('host'), self::setting('port'));
            $lines[] = sprintf(
                'ユーザー名：%s／パスワード：%s',
                self::setting('username') === '' ? 'なし' : 'あり',
                self::setting('password') === '' ? 'なし' : 'あり',
            );
        }

        $lines[] = sprintf('送信元：%s（%s）', (string) config('mail.from.address'), (string) config('mail.from.name'));
        $lines[] = 'リンクの先頭：'.(string) config('app.url');

        return $lines;
    }

    /**
     * 本番で送るには直すところ。なければ空。
     *
     * @return list<string>
     */
    public static function problems(): array
    {
        $problems = [];
        $mailer = self::mailer();

        if (! self::delivers()) {
            $problems[] = "メールは送られません（MAIL_MAILER={$mailer} は、送らずに書き出すだけ）";
        }

        if (self::transport() === 'smtp' && self::setting('url') === '') {
            $host = Str::lower(self::setting('host'));

            if (in_array($host, self::DEVELOPMENT_HOSTS, true)) {
                $problems[] = '送り先のサーバー（MAIL_HOST）が開発用の受け皿です：'.($host === '' ? '（空）' : $host);
            }

            if (self::setting('username') === '' || self::setting('password') === '') {
                $problems[] = 'MAIL_USERNAME か MAIL_PASSWORD が空です';
            }
        }

        $from = (string) config('mail.from.address');
        $fromDomain = Str::lower(Str::after($from, '@'));

        if (filter_var($from, FILTER_VALIDATE_EMAIL) === false || in_array($fromDomain, self::PLACEHOLDER_DOMAINS, true)) {
            $problems[] = '送信元（MAIL_FROM_ADDRESS）が仮の値です：'.($from === '' ? '（空）' : $from);
        }

        $url = (string) config('app.url');
        $urlHost = Str::lower((string) parse_url($url, PHP_URL_HOST));

        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || in_array($urlHost, self::DEVELOPMENT_HOSTS, true)) {
            $problems[] = "メールの中のリンクの先頭（APP_URL）が、https の公開の URL ではありません：{$url}";
        }

        return $problems;
    }

    /**
     * ログに書く前に、エラーの文面から秘密の値を消す。
     */
    public static function scrub(string $message): string
    {
        foreach ([self::setting('password'), self::setting('url')] as $secret) {
            if ($secret !== '') {
                $message = str_replace([$secret, base64_encode($secret), rawurlencode($secret)], '（伏せた）', $message);
            }
        }

        return Str::limit($message, 300);
    }

    private static function setting(string $key): string
    {
        $value = config('mail.mailers.'.self::mailer().'.'.$key);

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
