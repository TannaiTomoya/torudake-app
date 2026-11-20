<?php

namespace App\Services;

use App\Enums\SetupMailResult;
use App\Models\User;
use App\Support\MailSetup;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Throwable;

/**
 * パスワードを決めるリンクのメールを送り、結果を返す（#39）。
 * 送れなかった・送らなかったことを、呼んだ側が見分けられるようにする。リンクそのものは返さない・ログに書かない。
 */
class PasswordSetupMail
{
    public function send(User $user): SetupMailResult
    {
        try {
            $status = Password::broker()->sendResetLink(['email' => $user->email]);
        } catch (Throwable $e) {
            Log::error('メールを送れませんでした（パスワードを決めるリンク）', [
                'user_id' => $user->id,
                'exception' => $e::class,
                'message' => MailSetup::scrub($e->getMessage()),
            ]);

            return SetupMailResult::Failed;
        }

        return match (true) {
            $status === Password::RESET_THROTTLED => SetupMailResult::Throttled,
            $status !== Password::RESET_LINK_SENT => SetupMailResult::Failed,
            ! MailSetup::delivers() => SetupMailResult::NotDelivering,
            default => SetupMailResult::Sent,
        };
    }
}
