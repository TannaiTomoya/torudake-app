<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PasswordSetupMail;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * すでにいる人に、パスワードを決めるリンクのメールを送り直す（#39）。
 * 所属・役割は変えない（add-member をやり直さなくてよい）。
 */
class SendSetupLinkCommand extends Command
{
    protected $signature = 'torudake:send-setup-link {email : その人のメールアドレス}';

    protected $description = 'すでにいる人に、パスワードを決めるリンクのメールを送り直す（所属は変えない）';

    public function handle(PasswordSetupMail $mail): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));

        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("その人が見つかりません：{$email}（先に torudake:add-member で追加してください）");

            return self::FAILURE;
        }

        $result = $mail->send($user);

        if ($result->needsRetry()) {
            $this->error($result->explain($email));

            return self::FAILURE;
        }

        $this->info($result->explain($email));

        return self::SUCCESS;
    }
}
