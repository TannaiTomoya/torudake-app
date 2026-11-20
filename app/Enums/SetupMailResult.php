<?php

namespace App\Enums;

/**
 * パスワードを決めるリンクのメールを送った結果（#39）。
 */
enum SetupMailResult: string
{
    /** 送信サービスに渡した（届いたかどうかは受信箱で確かめる） */
    case Sent = 'sent';

    /** 送らずに書き出すだけの設定（log・array）だった */
    case NotDelivering = 'not_delivering';

    /** 直前に送ったばかりなので、送らなかった */
    case Throttled = 'throttled';

    /** 送れなかった */
    case Failed = 'failed';

    /**
     * 操作した人が、もう一度何かをする必要があるか。
     */
    public function needsRetry(): bool
    {
        return $this === self::Throttled || $this === self::Failed;
    }

    /**
     * コマンドの画面に出す説明。送っていないのに「送信しました」とは書かない。
     */
    public function explain(string $email): string
    {
        return match ($this) {
            self::Sent => "パスワード再設定メールを送信しました：{$email}（届いたかどうかは、本人の受信箱で確かめてください）",
            self::NotDelivering => 'メールは送られていません（今の設定 MAIL_MAILER='.config('mail.default').' は、送らずに書き出すだけ）。'
                .'送る設定は docs/runbook.md の「メールを送る」、それまでは「パスワードを決めるリンク」の手順でリンクを作ってください。',
            self::Throttled => "メールは送っていません（この人には直前に送ったばかりです）。1分たってから php artisan torudake:send-setup-link {$email} を実行してください。",
            self::Failed => 'メールを送れませんでした。php artisan torudake:mail-check で設定を確かめ、'
                ."1分たってから php artisan torudake:send-setup-link {$email} で送り直してください。",
        };
    }
}
