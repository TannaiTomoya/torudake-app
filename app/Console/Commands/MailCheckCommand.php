<?php

namespace App\Console\Commands;

use App\Mail\DeliveryTestMail;
use App\Support\MailSetup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * メールを送る設定を確かめる（#39）。
 * 引数なし：設定がそれらしいか（設定の確認）。--to：試しに1通送る（届いたかは受信箱で確かめる）。
 * 「設定がそれらしい」と「届いた」は別。秘密の値は表示しない。
 */
class MailCheckCommand extends Command
{
    protected $signature = 'torudake:mail-check {--to= : 試しに1通送る宛先（自分のメールアドレス）}';

    protected $description = 'メールを送る設定を確かめる。--to を付けると、試しに1通送る';

    public function handle(): int
    {
        foreach (MailSetup::summary() as $line) {
            $this->line($line);
        }

        $problems = MailSetup::problems();

        foreach ($problems as $problem) {
            $this->line("NG {$problem}");
        }

        // エラーの記録に、通信の中身（パスワードの一部）が残らないようにする PHP の設定
        if (! filter_var(ini_get('zend.exception_ignore_args'), FILTER_VALIDATE_BOOLEAN)) {
            $this->line('注意：PHP の zend.exception_ignore_args が Off です。送信に失敗したとき、ログに通信の中身の一部が残ることがあります（本番の php.ini では On にする）');
        }

        if ($problems !== []) {
            $this->error('設定の確認：直すところが '.count($problems).' 件あります（docs/runbook.md の「メールを送る」）。');

            return self::FAILURE;
        }

        $to = trim((string) $this->option('to'));

        if ($to === '') {
            $this->info('設定の確認：問題は見つかりません。');
            $this->line('これは設定を見ただけです。届くかどうかは、--to=自分のメール で試しに送り、受信箱で確かめてください。');

            return self::SUCCESS;
        }

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('--to がメールアドレスの形ではありません。');

            return self::FAILURE;
        }

        try {
            Mail::to($to)->send(new DeliveryTestMail);
        } catch (Throwable $e) {
            $this->error('試し送り：送れませんでした（'.$e::class.'）。');
            $this->line('   '.MailSetup::scrub($e->getMessage()));

            return self::FAILURE;
        }

        $this->info("試し送り：送信サービスに渡しました（{$to}）。");
        $this->line('まだ「届いた」ではありません。受信箱（迷惑メールも）に届いたこと、送信元の表示、リンクの先頭が https であることを見てください。');

        return self::SUCCESS;
    }
}
