<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\SecurityAnswer;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ResetSecurityAnswerCommand extends Command
{
    protected $signature = 'torudake:reset-security-answer {email : その人のメールアドレス}';

    protected $description = '秘密の質問の答えを消す（忘れたとき）。次のログインで本人が決め直す';

    public function handle(SecurityAnswer $answers): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));

        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("その人が見つかりません：{$email}");

            return self::FAILURE;
        }

        $answers->reset($user);

        $this->info('秘密の質問の答えを消し、信頼していた端末も忘れました。次のログイン（パスワードだけ）で、本人が答えを決め直します。');

        return self::SUCCESS;
    }
}
